<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\CreateComboInvoiceAction;
use App\Actions\ExecuteFulfillmentTaskAction;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Enums\TransactionType;
use App\Filament\Pages\AdminAccess;
use App\Filament\Pages\AdminAudit;
use App\Filament\Pages\AdminTasks;
use App\Filament\Pages\FinanceInvoice;
use App\Filament\Pages\FinanceLedger;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentCallback;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Http::preventStrayRequests();
});

function adminOperator(string ...$roles): User
{
    $user = User::factory()->create();
    foreach ($roles as $role) {
        app(AssignAdminRoleAction::class)->execute($user, $role, 'Isolated admin acceptance');
    }

    return $user;
}

function adminRefundInvoice(InstanceStatus $status = InstanceStatus::CANCELLED): array
{
    $buyer = User::factory()->create(['credit' => '20.00', 'frozen_credit' => '7.00']);
    $instance = Instance::factory()->create(['user_id' => $buyer->id, 'status' => $status]);
    $invoice = Invoice::factory()->paid()->create(['user_id' => $buyer->id, 'instance_id' => $instance->id,
        'product_id' => $instance->product_id, 'total_amount' => '100.00', 'credit_applied' => '30.00',
        'gateway_amount' => '70.00', 'discount_amount' => '5.00']);

    return [$invoice, $buyer, $instance];
}

it('protects each administrative page from guests and ordinary customers', function (string $path): void {
    $this->get($path)->assertRedirect('/admin/login');
    $customer = User::factory()->create();
    expect($customer->canAccessPanel(Panel::make()->id('admin')))->toBeFalse();
    $this->actingAs($customer)->get($path)->assertForbidden();
})->with(['/admin/finance', '/admin/finance/invoice?invoice_id=1', '/admin/finance/ledger', '/admin/tasks', '/admin/access', '/admin/audit']);

it('gives each new role only its own panel entry without granting unrelated authority', function (string $role, string $page): void {
    $actor = adminOperator($role);
    expect($actor->canAccessPanel(Panel::make()->id('admin')))->toBeTrue()
        ->and($actor->canAccessPanel(Panel::make()->id('console')))->toBeFalse()
        ->and($actor->can('inventory.import'))->toBeFalse()->and($actor->can('upstream.lifecycle'))->toBeFalse()
        ->and($actor->can('refund-invoice'))->toBeFalse(); // No invoice-scoped authority without an invoice.
    $this->actingAs($actor)->get($page)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
})->with([
    ['finance-viewer', '/admin/finance'], ['finance-operator', '/admin/finance'], ['task-viewer', '/admin/tasks'],
    ['audit-viewer', '/admin/audit'], ['access-manager', '/admin/access'],
]);

it('projects financial metadata without reading payment payloads or service credentials', function (): void {
    $viewer = adminOperator('finance-viewer');
    [$invoice, , $instance] = adminRefundInvoice();
    $instance->update(['encrypted_credentials' => ['password' => 'PRIVATE-SERVICE-PASSWORD']]);
    PaymentAttempt::create(['invoice_id' => $invoice->id, 'gateway' => 'epay_wechat', 'out_trade_no' => 'EXACT-ATTEMPT-42',
        'amount' => '70.00', 'status' => 'created', 'request_payload' => ['key' => 'PRIVATE-PAYMENT-REQUEST']]);
    PaymentCallback::factory()->create(['invoice_id' => $invoice->id, 'gateway' => 'epay_wechat', 'amount' => '70.00', 'raw_payload' => ['sign' => 'PRIVATE-PAYMENT-SIGN']]);
    $sql = [];
    DB::listen(function ($query) use (&$sql): void {
        $sql[] = $query->sql;
    });
    $this->actingAs($viewer)->get('/admin/finance/invoice?invoice_id='.$invoice->id)->assertOk()
        ->assertSee('100.00 = 钱包 30.00 + 网关 70.00')->assertSee('EXACT-ATTEMPT-42')->assertDontSee('确认全额退款')
        ->assertDontSee('PRIVATE-SERVICE-PASSWORD')->assertDontSee('PRIVATE-PAYMENT-REQUEST')->assertDontSee('PRIVATE-PAYMENT-SIGN');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $component = Livewire::withQueryParams(['invoice_id' => $invoice->id])->test(FinanceInvoice::class)->assertOk()->assertSee($invoice->invoice_no)->assertDontSee('PRIVATE-PAYMENT-SIGN');
    $component->call('$refresh')->assertOk()->assertSee($invoice->invoice_no)->assertDontSee('PRIVATE-SERVICE-PASSWORD');
    foreach ($sql as $query) {
        if (str_starts_with(strtolower($query), 'select') && preg_match('/from "(payment_attempts|payment_callbacks|instances)"/', $query)) {
            expect($query)->not->toContain('select *')->not->toContain('request_payload')->not->toContain('raw_payload')->not->toContain('encrypted_credentials');
        }
    }
    $this->postJson('/admin/finance/invoices/'.$invoice->id.'/refund', ['mode' => 'refund_wallet', 'reason' => 'Forged', 'confirm' => 1])->assertForbidden();
    $this->get('/admin/access')->assertForbidden();
    $this->get('/admin/audit')->assertForbidden();
});

it('refunds only the stored full amount once and records the real web actor with request context', function (): void {
    $actor = adminOperator('finance-operator');
    [$invoice, $buyer] = adminRefundInvoice();
    $body = ['mode' => 'refund_wallet', 'reason' => 'Approved full refund', 'confirm' => 1, 'amount' => '9999.00'];
    $this->actingAs($actor)->post('/admin/finance/invoices/'.$invoice->id.'/refund', $body)->assertRedirect('/admin/finance/invoice?invoice_id='.$invoice->id);
    $this->post('/admin/finance/invoices/'.$invoice->id.'/refund', $body)->assertRedirect();
    expect($buyer->fresh()->credit)->toBe('120.00')->and($buyer->fresh()->frozen_credit)->toBe('7.00')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::REFUNDED)->and(FinancialTransaction::sole()->amount)->toBe('100.00')
        ->and(FinancialTransaction::sole()->reference_key)->toBe('refund:'.$invoice->id);
    $audit = DB::table('audit_logs')->where('action', 'invoice.refund')->sole();
    expect($audit->actor_id)->toBe($actor->id)->and($audit->reason)->toBe('Approved full refund')
        ->and($audit->ip)->toBe('127.0.0.1')->and($audit->request_id)->toBeString()->not->toBe('');
    $this->postJson('/admin/finance/invoices/'.$invoice->id.'/refund', ['mode' => 'external_record', 'external_confirm' => 1,
        'reason' => 'Conflicting mode', 'confirm' => 1])->assertUnprocessable()->assertJsonValidationErrors('operation');
    $this->assertDatabaseCount('financial_transactions', 1);
    Http::assertNothingSent();
});

it('requires independent external-refund confirmation and records expense without sending money', function (): void {
    [$invoice, $buyer] = adminRefundInvoice();
    $this->actingAs(adminOperator('finance-operator'));
    $body = ['mode' => 'external_record', 'reason' => 'Provider refund receipt #42', 'confirm' => 1];
    $this->postJson('/admin/finance/invoices/'.$invoice->id.'/refund', $body)->assertUnprocessable()->assertJsonValidationErrors('external_confirm');
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->post('/admin/finance/invoices/'.$invoice->id.'/refund', $body + ['external_confirm' => 1])->assertRedirect();
    expect($buyer->fresh()->credit)->toBe('20.00')->and(FinancialTransaction::sole()->type)->toBe(TransactionType::EXPENSE)
        ->and(FinancialTransaction::sole()->meta['external_refund_confirmed'])->toBeTrue();
    Http::assertNothingSent();
});

it('requires a separate keep-service waiver rather than treating refund confirmation as termination', function (): void {
    [$invoice, $buyer, $instance] = adminRefundInvoice(InstanceStatus::ACTIVE);
    $this->actingAs(adminOperator('finance-operator'));
    $body = ['mode' => 'refund_wallet', 'reason' => 'Explicit retained service exception', 'confirm' => 1];
    $this->postJson('/admin/finance/invoices/'.$invoice->id.'/refund', $body)->assertUnprocessable()->assertJsonValidationErrors('operation');
    expect($buyer->fresh()->credit)->toBe('20.00');
    $this->post('/admin/finance/invoices/'.$invoice->id.'/refund', $body + ['keep_service' => 1])->assertRedirect();
    expect($instance->fresh()->status)->toBe(InstanceStatus::ACTIVE)->and($buyer->fresh()->credit)->toBe('120.00')
        ->and(FinancialTransaction::sole()->meta['keep_service'])->toBeTrue();
});

it('rejects incomplete refund consent without changing money', function (array $body, string $field): void {
    [$invoice, $buyer] = adminRefundInvoice();
    $this->actingAs(adminOperator('finance-operator'))->postJson('/admin/finance/invoices/'.$invoice->id.'/refund', $body)
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($buyer->fresh()->credit)->toBe('20.00')->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID);
    $this->assertDatabaseCount('financial_transactions', 0);
})->with([
    [['mode' => 'refund_wallet', 'reason' => 'Missing confirmation'], 'confirm'],
    [['mode' => 'refund_wallet', 'keep_service' => 1, 'reason' => '   ', 'confirm' => 1], 'reason'],
    [['mode' => 'refund_wallet', 'reason' => str_repeat('x', 256), 'confirm' => 1], 'reason'],
    [['mode' => 'partial_refund', 'reason' => 'Unsupported mode', 'confirm' => 1], 'mode'],
]);

it('fails the entire web refund if the mandatory audit cannot be inserted', function (): void {
    [$invoice, $buyer] = adminRefundInvoice();
    $this->actingAs(adminOperator('finance-operator'));
    DB::unprepared("CREATE TRIGGER reject_admin_refund BEFORE INSERT ON audit_logs WHEN NEW.action = 'invoice.refund' BEGIN SELECT RAISE(ABORT, 'Audit unavailable'); END");
    $this->withoutExceptionHandling();
    expect(fn () => $this->post('/admin/finance/invoices/'.$invoice->id.'/refund', ['mode' => 'refund_wallet', 'reason' => 'Audit rollback', 'confirm' => 1]))
        ->toThrow(QueryException::class, 'Audit unavailable');
    expect($buyer->fresh()->credit)->toBe('20.00')->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID);
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('grants and revokes fixed web roles with before-after audit while retaining unrelated roles', function (): void {
    $manager = adminOperator('access-manager');
    $target = adminOperator('inventory-viewer');
    $body = ['role' => 'finance-operator', 'reason' => 'Join finance team', 'confirm' => 1];
    $this->actingAs($manager)->post('/admin/access/'.$target->id.'/role', $body + ['operation' => 'grant'])->assertRedirect('/admin/access?user_id='.$target->id);
    expect($target->fresh()->can('finance.refund'))->toBeTrue()->and($target->fresh()->can('inventory.view'))->toBeTrue();
    $grant = DB::table('audit_logs')->where('action', 'admin.role.grant')->where('actor_id', $manager->id)->sole();
    expect(json_decode($grant->before, true)['roles'])->toBe(['inventory-viewer'])
        ->and(json_decode($grant->after, true)['roles'])->toBe(['finance-operator', 'inventory-viewer'])
        ->and($grant->request_id)->toBeString()->and($grant->ip)->toBe('127.0.0.1');
    $this->post('/admin/access/'.$target->id.'/role', $body + ['operation' => 'revoke'])->assertRedirect();
    expect($target->fresh()->can('finance.refund'))->toBeFalse()->and($target->fresh()->can('inventory.view'))->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'admin.role.revoke', 'actor_id' => $manager->id, 'subject_id' => $target->id]);
});

it('rejects web self-assignment, unknown roles, and access-manager delegation', function (string $attack): void {
    $manager = adminOperator('access-manager');
    $target = $attack === 'self' ? $manager : User::factory()->create();
    $role = match ($attack) {
        'unknown' => 'super_admin', 'manager' => 'access-manager', default => 'finance-operator'
    };
    $this->actingAs($manager)->postJson('/admin/access/'.$target->id.'/role', ['role' => $role, 'operation' => 'grant', 'reason' => 'Forged scope', 'confirm' => 1])->assertUnprocessable();
    expect($target->fresh()->can('finance.refund'))->toBeFalse();
    expect(DB::table('audit_logs')->where('actor_id', $manager->id)->count())->toBe(0);
})->with(['self', 'unknown', 'manager']);

it('rechecks the actual operator authority inside role action even with a stale authenticated model', function (): void {
    $manager = adminOperator('access-manager');
    $target = User::factory()->create();
    expect($manager->can('access.manage'))->toBeTrue();
    $separate = User::findOrFail($manager->id);
    app(AssignAdminRoleAction::class)->execute($separate, 'access-manager', 'Revoke compromised operator', true);
    expect(fn () => app(AssignAdminRoleAction::class)->execute($target, 'finance-operator', 'Stale authority', actor: $manager))->toThrow(AuthorizationException::class);
    expect($target->fresh()->can('finance.refund'))->toBeFalse();
});

it('rolls back role assignment if audit storage fails', function (): void {
    $manager = adminOperator('access-manager');
    $target = User::factory()->create();
    DB::unprepared("CREATE TRIGGER reject_role_audit BEFORE INSERT ON audit_logs WHEN NEW.actor_id IS NOT NULL AND NEW.action = 'admin.role.grant' BEGIN SELECT RAISE(ABORT, 'Role audit unavailable'); END");
    expect(fn () => app(AssignAdminRoleAction::class)->execute($target, 'finance-operator', 'Audited grant', actor: $manager))->toThrow(QueryException::class, 'Role audit unavailable');
    expect($target->fresh()->getRoleNames()->all())->toBe([])->and($target->fresh()->can('finance.refund'))->toBeFalse();
});

it('bootstraps and revokes managers only through an explicitly audited server command', function (): void {
    $user = User::factory()->create();
    $this->artisan('admin:role', ['user' => $user->id, 'role' => 'access-manager', '--reason' => 'Initial named operator'])->assertSuccessful();
    expect($user->fresh()->can('access.manage'))->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'admin.role.grant', 'actor_type' => 'system', 'actor_id' => null, 'subject_id' => $user->id, 'reason' => 'Initial named operator']);
    $this->artisan('admin:role', ['user' => $user->id, 'role' => 'access-manager', '--reason' => 'Revoke initial operator', '--revoke' => true])->assertSuccessful();
    expect($user->fresh()->can('access.manage'))->toBeFalse();
});

it('revokes financial pages, hydrated components and forged financial posts', function (): void {
    $operator = adminOperator('finance-operator');
    [$invoice] = adminRefundInvoice();
    $this->actingAs($operator);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $component = Livewire::test(FinanceLedger::class)->assertOk();
    app(AssignAdminRoleAction::class)->execute($operator, 'finance-operator', 'Revoke financial authority', true);
    $component->call('$refresh')->assertForbidden();
    $this->get('/admin/finance')->assertForbidden();
    $this->postJson('/admin/finance/invoices/'.$invoice->id.'/refund', ['mode' => 'refund_wallet', 'reason' => 'Revoked request', 'confirm' => 1])->assertForbidden();
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('shows safe task metadata and queue failures without querying their sensitive payloads', function (): void {
    $viewer = adminOperator('task-viewer');
    $task = FulfillmentTask::factory()->create(['payload' => ['password' => 'PRIVATE-TASK-PASSWORD'], 'error_message' => 'PRIVATE-UPSTREAM-ERROR']);
    DB::table('failed_jobs')->insert(['uuid' => 'failed-job-42', 'connection' => 'database', 'queue' => 'default',
        'payload' => 'PRIVATE-FAILED-PAYLOAD', 'exception' => 'PRIVATE-EXCEPTION-KEY', 'failed_at' => now()]);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $this->actingAs($viewer)->get('/admin/tasks')->assertOk()->assertSee('failed-job-42')->assertDontSee('PRIVATE-TASK-PASSWORD')->assertDontSee('PRIVATE-UPSTREAM-ERROR')
        ->assertDontSee('PRIVATE-FAILED-PAYLOAD')->assertDontSee('PRIVATE-EXCEPTION-KEY')->assertDontSee('重试原任务');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(AdminTasks::class)->assertDontSee('PRIVATE-TASK-PASSWORD')->assertDontSee('PRIVATE-EXCEPTION-KEY');
    foreach ($queries as $query) {
        if (str_starts_with(strtolower($query), 'select') && preg_match('/from "(fulfillment_tasks|failed_jobs)"/', $query)) {
            expect($query)->not->toContain('select *')->not->toContain('"payload"')->not->toContain('"exception"')->not->toContain('"error_message"');
        }
    }
    $this->postJson('/admin/tasks/'.$task->id.'/retry', ['reason' => 'Viewer cannot retry', 'confirm' => 1])->assertForbidden();
});

it('retries a paid failed local task once from the centralized page without another wallet deduction', function (): void {
    Queue::fake();
    $buyer = User::factory()->create(['credit' => '20.00']);
    $product = Product::factory()->create(['local_prices' => ['onetime' => ['price' => '10.00', 'setup_fee' => '0.00', 'enabled' => true]]]);
    $invoice = app(CreateComboInvoiceAction::class)->execute($buyer, $product, BillingCycle::ONETIME, [], true, input: ['request_id' => (string) Str::ulid()]);
    $task = FulfillmentTask::sole();
    $task->update(['max_attempts' => 1]);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    $this->actingAs(adminOperator('task-viewer', 'inventory-operator'));
    $this->get('/admin/tasks?task_id='.$task->id)->assertOk()->assertSee('重试原任务');
    $body = ['reason' => 'Replenishment verified', 'confirm' => 1];
    $this->post('/admin/tasks/'.$task->id.'/retry', $body)->assertRedirect('/admin/tasks?task_id='.$task->id);
    $this->postJson('/admin/tasks/'.$task->id.'/retry', $body)->assertUnprocessable();
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::PENDING)->and($task->fresh()->attempts)->toBe(0)
        ->and($buyer->fresh()->credit)->toBe('10.00')->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID);
    expect(DB::table('audit_logs')->where('action', 'fulfillment.retry')->count())->toBe(1);
    $this->assertDatabaseCount('fulfillment_tasks', 1);
});

it('rejects resetting a running task lease through the centralized operation', function (): void {
    Queue::fake();
    $task = FulfillmentTask::factory()->create(['status' => FulfillmentTaskStatus::RUNNING, 'locked_by' => 'active-worker', 'lease_expires_at' => now()->addMinutes(5)]);
    $this->actingAs(adminOperator('task-viewer', 'inventory-operator'))->postJson('/admin/tasks/'.$task->id.'/retry', ['reason' => 'Forged lease reset', 'confirm' => 1])->assertUnprocessable();
    expect($task->fresh()->locked_by)->toBe('active-worker')->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::RUNNING);
    Queue::assertNothingPushed();
});

it('does not let generic task authority bypass remote card reconciliation', function (): void {
    Queue::fake();
    $account = UpstreamApi::factory()->create(['driver' => DriverType::SUB2API]);
    $instance = Instance::factory()->create(['driver_type' => DriverType::SUB2API, 'upstream_api_id' => $account->id]);
    $task = FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'status' => FulfillmentTaskStatus::FAILED,
        'payload' => ['remote_card' => ['stage' => 'requested']]]);
    $this->actingAs(adminOperator('task-viewer', 'inventory-operator'))->get('/admin/tasks?task_id='.$task->id)->assertOk()->assertDontSee('重试原任务');
    $this->postJson('/admin/tasks/'.$task->id.'/retry', ['reason' => 'Blind remote replay', 'confirm' => 1])->assertUnprocessable();
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED)->and($task->fresh()->payload['remote_card']['stage'])->toBe('requested');
    Queue::assertNothingPushed();
});

it('requires classic lifecycle authority and preserves the original upstream task intent', function (): void {
    Queue::fake();
    $instance = Instance::factory()->create(['driver_type' => DriverType::ZJMF_API]);
    $task = FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'status' => FulfillmentTaskStatus::FAILED,
        'payload' => ['classic_order' => ['stage' => 'payment_requested', 'invoice_id' => 903]]]);
    $operator = adminOperator('task-viewer', 'inventory-operator');
    $this->actingAs($operator)->postJson('/admin/tasks/'.$task->id.'/retry', ['reason' => 'Wrong authority', 'confirm' => 1])->assertForbidden();
    app(AssignAdminRoleAction::class)->execute($operator, 'upstream-lifecycle', 'Classic operator approved');
    $this->post('/admin/tasks/'.$task->id.'/retry', ['reason' => 'Upstream invoice reconciled', 'confirm' => 1])->assertRedirect();
    expect($task->fresh()->payload)->toBe(['classic_order' => ['stage' => 'payment_requested', 'invoice_id' => 903]])
        ->and($task->fresh()->status)->toBe(FulfillmentTaskStatus::PENDING);
    Http::assertNothingSent();
});

it('projects audit snapshots through an allowlist and keeps accounting receivables visible', function (): void {
    $auditor = adminOperator('audit-viewer');
    $target = User::factory()->create();
    DB::table('audit_logs')->insert(['actor_id' => $auditor->id, 'action' => 'projection.check', 'subject_type' => User::class, 'subject_id' => $target->id,
        'reason' => 'Projection acceptance', 'before' => json_encode(['status' => 'Paid', 'password' => 'PRIVATE-AUDIT-PASSWORD']),
        'after' => json_encode(['credit' => '10.00', 'request' => ['secret' => 'PRIVATE-AUDIT-REQUEST']]), 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($auditor)->get('/admin/audit?action=projection.check')->assertOk()->assertSee('Projection acceptance')
        ->assertSee('Paid')->assertSee('10.00')->assertDontSee('PRIVATE-AUDIT-PASSWORD')->assertDontSee('PRIVATE-AUDIT-REQUEST');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(AdminAudit::class)->assertDontSee('PRIVATE-AUDIT-PASSWORD')->assertDontSee('PRIVATE-AUDIT-REQUEST');
    $finance = adminOperator('finance-viewer');
    FinancialTransaction::create(['user_id' => $target->id, 'type' => TransactionType::AFFILIATE_CLAWBACK, 'amount' => '10.00',
        'reference_key' => 'clawback:acceptance', 'meta' => ['currency' => 'CNY', 'recovered_amount' => '3.00', 'receivable_amount' => '7.00', 'secret' => 'PRIVATE-LEDGER-META']]);
    PaymentCallback::factory()->create(['invoice_id' => null, 'invoice_no' => 'UNKNOWN-EXACT-NUMBER', 'gateway' => 'epay_wechat',
        'outcome' => 'unknown_invoice', 'raw_payload' => ['sign' => 'PRIVATE-UNKNOWN-CALLBACK']]);
    $this->actingAs($finance)->get('/admin/finance/ledger')->assertOk()->assertSee('扣回 3.00 · 应收 7.00')->assertSee('UNKNOWN-EXACT-NUMBER')
        ->assertDontSee('PRIVATE-LEDGER-META')->assertDontSee('PRIVATE-UNKNOWN-CALLBACK');
});

it('does not expose credentials or grant controls through read-only access permission', function (): void {
    $manager = adminOperator('access-manager');
    $manager->removeRole('access-manager');
    $manager->givePermissionTo('access.view');
    $this->actingAs($manager)->get('/admin/access')->assertOk()->assertDontSee('保存角色');
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(AdminAccess::class)->assertOk()->assertDontSee('保存角色')->assertDontSee($manager->password);
    $this->postJson('/admin/access/'.User::factory()->create()->id.'/role', ['role' => 'finance-operator', 'operation' => 'grant', 'reason' => 'Viewer forgery', 'confirm' => 1])->assertForbidden();
    expect(Gate::forUser($manager)->allows('finance.refund'))->toBeFalse();
});
