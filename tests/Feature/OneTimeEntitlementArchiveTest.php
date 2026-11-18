<?php

declare(strict_types=1);

use App\Actions\AccrueAffiliateCommissionAction;
use App\Actions\ArchiveOneTimeEntitlementAction;
use App\Actions\CloseCustomerAccountAction;
use App\Actions\ConfigureSub2ApiAction;
use App\Actions\CreateComboInvoiceAction;
use App\Actions\CreateSub2ApiProductAction;
use App\Actions\ExecuteFulfillmentTaskAction;
use App\Actions\HandlePaymentCallbackAction;
use App\Actions\MatureAffiliateCommissionsAction;
use App\Actions\ReencryptSecretsAction;
use App\Actions\RegisterCustomerAction;
use App\Contracts\OneTimeDeliverable;
use App\Data\VerifiedPaymentResult;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Models\AffiliateRule;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\InstanceEntitlementArchive;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\RedemptionCode;
use App\Models\User;
use App\Services\AccountClosureEligibility;
use App\Services\DriverManager;
use App\Services\OneTimeEntitlementEvidence;
use App\Support\InstallationState;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);
beforeEach(function (): void {
    $this->installApplicationForTest();
    Queue::fake();
    Http::preventStrayRequests();
});

function entitlementDeliveredCard(DriverType $type = DriverType::REDEMPTION, bool $combo = false, ?User $user = null): array
{
    $product = Product::factory()->create(['driver_type' => $type, 'local_prices' => ['onetime' => ['enabled' => true, 'price' => '10.00', 'setup_fee' => '0.00']]]);
    $card = RedemptionCode::factory()->create(['product_id' => $product->id, 'code' => 'ENTITLEMENT-PRIVATE-'.Str::ulid()]);
    $user ??= User::factory()->create(['credit' => $combo ? '3.00' : '10.00', 'password' => 'Archive-Password-123']);
    $invoice = app(CreateComboInvoiceAction::class)->execute($user, $product, BillingCycle::ONETIME, [], true, 'epay_wechat', input: ['request_id' => (string) Str::ulid()]);
    if ($combo) {
        app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult('epay_wechat', 'ENTITLEMENT-PAY-'.Str::ulid(), $invoice->invoice_no, '7.00'));
    }
    $task = FulfillmentTask::where('invoice_id', $invoice->id)->sole();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);

    return [$user->fresh(), $task->instance()->firstOrFail(), $invoice->fresh(), $task->fresh(), $card->fresh()];
}

function entitlementDeliveredRemoteCard(): array
{
    $account = app(ConfigureSub2ApiAction::class)->execute('Archive remote account', 'https://entitlement-sub2.test/api/v1', 'PRIVATE-REMOTE-KEY', 'Configure fixed-code delivery');
    $product = app(CreateSub2ApiProductAction::class)->execute($account, 'Archive remote card', '10.00', '100', 'CNY', 'Launch fixed-code product');
    $user = User::factory()->create(['credit' => '10.00', 'password' => 'Archive-Password-123']);
    $invoice = app(CreateComboInvoiceAction::class)->execute($user, $product, BillingCycle::ONETIME, [], true, input: ['request_id' => (string) Str::ulid()]);
    $task = FulfillmentTask::where('invoice_id', $invoice->id)->sole();
    Http::fake(['https://entitlement-sub2.test/api/v1/admin/redeem-codes/generate' => Http::response(['code' => 0, 'data' => [
        ['id' => 81, 'code' => 'ENTITLEMENT-REMOTE-PRIVATE', 'type' => 'balance', 'value' => 100, 'status' => 'unused'],
    ]])]);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);

    return [$user->fresh(), $task->instance()->firstOrFail(), $invoice->fresh(), $task->fresh(), $account->fresh()];
}

it('archives a fully paid local fixed-code delivery without altering the service stock or money', function (DriverType $type, bool $combo): void {
    [$user, $instance, $invoice, $task, $card] = entitlementDeliveredCard($type, $combo);
    $history = [$instance->getAttributes(), $invoice->getAttributes(), $task->getAttributes(), $card->getAttributes(), FinancialTransaction::orderBy('id')->get()->toArray()];
    $requestId = (string) Str::ulid();
    $archive = app(ArchiveOneTimeEntitlementAction::class)->execute($user, $instance, 'Archive-Password-123', strtolower($requestId), true);
    expect($archive->user_id)->toBe($user->id)->and($archive->instance_id)->toBe($instance->id)
        ->and($archive->request_id)->toBe($requestId)->and($archive->confirmation_version)->toBe(1)
        ->and($archive->confirmed_at)->not->toBeNull()->and($archive->archived_at)->not->toBeNull()
        ->and($instance->fresh()->status)->toBe(InstanceStatus::ACTIVE)->and($card->fresh()->status)->toBe('sold')
        ->and([$instance->fresh()->getAttributes(), $invoice->fresh()->getAttributes(), $task->fresh()->getAttributes(), $card->fresh()->getAttributes(), FinancialTransaction::orderBy('id')->get()->toArray()])->toBe($history)
        ->and(app(OneTimeEntitlementEvidence::class)->validArchive($instance))->toBeTrue()
        ->and(app(ArchiveOneTimeEntitlementAction::class)->execute($user, $instance, 'Archive-Password-123', $requestId, true)->id)->toBe($archive->id)
        ->and(InstanceEntitlementArchive::count())->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'instance.entitlement_archived')->count())->toBe(1);
    $raw = DB::table('instance_entitlement_archives')->where('id', $archive->id)->value('delivery_snapshot');
    expect($raw)->not->toContain($card->code, $card->code_hash, $user->email, 'Archive-Password-123', 'encrypted_credentials', 'encrypted_result');
    Http::assertNothingSent();
})->with([[DriverType::REDEMPTION, false], [DriverType::REDEMPTION, true], [DriverType::SUB2API, false], [DriverType::SUB2API, true]]);

it('retains valid buyer delivery evidence after the same invoice pays its referrer commission', function (): void {
    AffiliateRule::create(['name' => 'Archive referral', 'commission_rate' => '10.00', 'priority' => 1, 'is_active' => true]);
    $referrer = User::factory()->create();
    $buyer = User::factory()->create(['credit' => '10.00', 'password' => 'Archive-Password-123', 'referred_by' => $referrer->id]);
    [$user, $instance, $invoice] = entitlementDeliveredCard(user: $buyer);
    $commission = app(AccrueAffiliateCommissionAction::class)->execute($invoice->id);
    expect($commission)->not->toBeNull();
    $this->travel(8)->days();
    app(MatureAffiliateCommissionsAction::class)->execute($commission->id);
    expect($referrer->fresh()->affiliate_balance)->toBe('1.00');
    $archive = app(ArchiveOneTimeEntitlementAction::class)->execute($user, $instance, 'Archive-Password-123', (string) Str::ulid(), true);
    expect(app(OneTimeEntitlementEvidence::class)->validArchive($instance))->toBeTrue()
        ->and($archive->delivery_snapshot['invoice']['id'])->toBe($invoice->id)
        ->and(app(AccountClosureEligibility::class)->forUser($user)['eligible'])->toBeTrue();
});

it('requires the owner current password confirmation and a stable request key', function (): void {
    [$user, $instance] = entitlementDeliveredCard();
    $action = app(ArchiveOneTimeEntitlementAction::class);
    expect(fn () => $action->execute(User::factory()->create(), $instance, 'Archive-Password-123', (string) Str::ulid(), true))->toThrow(HttpException::class)
        ->and(fn () => $action->execute($user, $instance, 'wrong-password', (string) Str::ulid(), true))->toThrow(ValidationException::class)
        ->and(fn () => $action->execute($user, $instance, 'Archive-Password-123', (string) Str::ulid(), false))->toThrow(ValidationException::class)
        ->and(fn () => $action->execute($user, $instance, 'Archive-Password-123', 'invalid', true))->toThrow(ValidationException::class)
        ->and(InstanceEntitlementArchive::count())->toBe(0);
    $requestId = (string) Str::ulid();
    $archive = $action->execute($user, $instance, 'Archive-Password-123', $requestId, true);
    $other = Instance::factory()->create(['user_id' => $user->id]);
    expect(fn () => $action->execute($user, $other, 'Archive-Password-123', $requestId, true))->toThrow(ValidationException::class)
        ->and(InstanceEntitlementArchive::count())->toBe(1)
        ->and($archive->fresh()->instance_id)->toBe($instance->id);
});

it('rejects incomplete contradictory or unresolved payment and delivery evidence', function (string $change): void {
    [$user, $instance, $invoice, $task, $card] = entitlementDeliveredCard();
    match ($change) {
        'unpaid' => $invoice->update(['status' => InvoiceStatus::UNPAID]),
        'missing_paid_at' => $invoice->update(['paid_at' => null]),
        'missing_settlement' => DB::table('financial_transactions')->where('reference_key', 'settle:'.$invoice->id)->delete(),
        'wrong_settlement' => DB::table('financial_transactions')->where('reference_key', 'settle:'.$invoice->id)->update(['amount' => '9.00']),
        'unsold' => $card->update(['status' => 'reserved']),
        'missing_sold_at' => $card->update(['sold_at' => null]),
        'wrong_credentials' => $instance->update(['encrypted_credentials' => ['card_key' => 'UNRELATED-CARD']]),
        'bad_ciphertext' => DB::table('instances')->where('id', $instance->id)->update(['encrypted_credentials' => 'corrupt']),
        'pending_task' => $task->update(['status' => FulfillmentTaskStatus::PENDING]),
        'running_task' => $task->update(['status' => FulfillmentTaskStatus::RUNNING]),
        'missing_execution' => $task->update(['executed_at' => null]),
        'wrong_task_owner' => $task->update(['user_id' => User::factory()->create()->id]),
        'unresolved_remote' => FulfillmentTask::factory()->create(['user_id' => $user->id, 'instance_id' => $instance->id, 'invoice_id' => $invoice->id, 'idempotency_key' => 'unresolved-archive:'.Str::ulid(), 'status' => FulfillmentTaskStatus::FAILED, 'payload' => ['remote_delivery' => ['state' => 'uncertain']]]),
        'unresolved_payment' => PaymentAttempt::create(['invoice_id' => $invoice->id, 'gateway' => 'epay_wechat', 'out_trade_no' => 'UNKNOWN-ARCHIVE', 'amount' => '10.00', 'status' => 'unknown']),
        'recurring_boundary' => $instance->update(['next_due_at' => now()->addMonth()]),
        'unsupported_capability' => $instance->update(['driver_type' => DriverType::ZJMF_API]),
        'changed_purchase' => $invoice->update(['line_items' => ['purchase' => ['driver_type' => DriverType::ZJMF_API->value, 'billing_cycle' => 'onetime']]]),
    };
    expect(fn () => app(ArchiveOneTimeEntitlementAction::class)->execute($user, $instance->fresh(), 'Archive-Password-123', (string) Str::ulid(), true))->toThrow(ValidationException::class)
        ->and(InstanceEntitlementArchive::count())->toBe(0)
        ->and(app(OneTimeEntitlementEvidence::class)->validArchive($instance))->toBeFalse();
    Http::assertNothingSent();
})->with(['unpaid', 'missing_paid_at', 'missing_settlement', 'wrong_settlement', 'unsold', 'missing_sold_at', 'wrong_credentials', 'bad_ciphertext', 'pending_task', 'running_task', 'missing_execution', 'wrong_task_owner', 'unresolved_remote', 'unresolved_payment', 'recurring_boundary', 'unsupported_capability', 'changed_purchase']);

it('validates delivered remote fixed codes locally after supplier deactivation and credential revocation', function (): void {
    [$user, $instance, $invoice, $task, $account] = entitlementDeliveredRemoteCard();
    expect($task->payload['remote_delivery']['state'])->toBe('delivered')->and($task->status)->toBe(FulfillmentTaskStatus::SUCCESS);
    Http::fake(fn () => throw new LogicException('Archiving must never contact a supplier.'));
    DB::table('upstream_apis')->where('id', $account->id)->update(['is_active' => false, 'encrypted_secret' => '']);
    expect(app(DriverManager::class)->oneTimeDelivery($instance))->toBeInstanceOf(OneTimeDeliverable::class);
    $archive = app(ArchiveOneTimeEntitlementAction::class)->execute($user, $instance, 'Archive-Password-123', (string) Str::ulid(), true);
    expect(app(OneTimeEntitlementEvidence::class)->validArchive($instance))->toBeTrue()
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)->and($instance->fresh()->status)->toBe(InstanceStatus::ACTIVE)
        ->and(RedemptionCode::where('instance_id', $instance->id)->sole()->status)->toBe('sold')
        ->and(json_encode($archive->delivery_snapshot))->not->toContain('ENTITLEMENT-REMOTE-PRIVATE', 'PRIVATE-REMOTE-KEY', 'encrypted_result', 'encrypted_purchase');
    Http::assertNothingSent();
    $this->actingAs($user)->get('/console/instances')->assertOk()->assertSee('/console/instances/'.$instance->id.'/archive');
    $this->get('/console/instances/'.$instance->id.'/archive')->assertOk()->assertSee('当前交付证据核验通过');
});

it('blocks incomplete or inconsistent original remote-code results without querying the supplier', function (string $change): void {
    [$user, $instance, , $task] = entitlementDeliveredRemoteCard();
    $payload = $task->payload;
    if (in_array($change, ['requested', 'uncertain', 'received'], true)) {
        $payload['remote_delivery']['state'] = $change;
    } elseif ($change === 'corrupt_result') {
        $payload['remote_delivery']['encrypted_result'] = 'corrupt';
    } elseif ($change === 'wrong_result') {
        $payload['remote_delivery']['encrypted_result'] = Crypt::encryptString(json_encode(['upstream_id' => '81', 'credentials' => ['card_key' => 'WRONG-CODE']]));
    } elseif ($change === 'wrong_request') {
        $payload['remote_delivery']['face_value'] = '200.00';
    } else {
        $instance->update(['upstream_instance_id' => '82']);
    }
    $task->update(['payload' => $payload]);
    Http::fake(fn () => throw new LogicException('Do not query or reissue a historical delivery.'));
    expect(fn () => app(ArchiveOneTimeEntitlementAction::class)->execute($user, $instance->fresh(), 'Archive-Password-123', (string) Str::ulid(), true))->toThrow(ValidationException::class)
        ->and(InstanceEntitlementArchive::count())->toBe(0);
    Http::assertNothingSent();
})->with(['requested', 'uncertain', 'received', 'corrupt_result', 'wrong_result', 'wrong_request', 'wrong_binding']);

it('keeps archives immutable and withdraws the closure exception when current delivery facts change', function (): void {
    [$user, $instance, , , $card] = entitlementDeliveredCard();
    $action = app(ArchiveOneTimeEntitlementAction::class);
    $requestId = (string) Str::ulid();
    $archive = $action->execute($user, $instance, 'Archive-Password-123', $requestId, true);
    $original = $archive->fresh()->getAttributes();
    $card->update(['sold_at' => $card->sold_at->copy()->addSecond()]);
    expect(app(OneTimeEntitlementEvidence::class)->validArchive($instance))->toBeFalse()
        ->and(fn () => $action->execute($user, $instance, 'Archive-Password-123', $requestId, true))->toThrow(ValidationException::class)
        ->and($archive->fresh()->getAttributes())->toBe($original)
        ->and(fn () => $archive->update(['delivery_fingerprint' => str_repeat('0', 64)]))->toThrow(LogicException::class)
        ->and(fn () => $archive->delete())->toThrow(LogicException::class);
});

it('preserves a non-secret archive across a real application key rotation', function (): void {
    [$user, $instance] = entitlementDeliveredRemoteCard();
    $archive = app(ArchiveOneTimeEntitlementAction::class)->execute($user, $instance, 'Archive-Password-123', (string) Str::ulid(), true);
    $before = $archive->fresh()->getAttributes();
    $oldKey = config('app.key');
    $newKey = 'base64:'.base64_encode(random_bytes(32));
    $oldEncrypter = app('encrypter');
    $state = app(InstallationState::class);
    $state->write('installed.lock', $state->seal(['format' => 1, 'admin_id' => 1], $oldKey));
    File::ensureDirectoryExists(storage_path('framework'));
    File::put(storage_path('framework/down'), '{}');
    app(ReencryptSecretsAction::class)->execute($oldKey, $newKey, 'Rotate isolated archived delivery evidence', true);
    try {
        config(['app.key' => $newKey]);
        Crypt::swap($state->encrypter($newKey));
        expect($archive->fresh()->getAttributes())->toBe($before)
            ->and(app(OneTimeEntitlementEvidence::class)->validArchive($instance))->toBeTrue()
            ->and(app(OneTimeEntitlementEvidence::class)->fingerprint(app(OneTimeEntitlementEvidence::class)->snapshot($instance->fresh())))->toBe($archive->delivery_fingerprint)
            ->and($instance->fresh()->encrypted_credentials['card_key'])->toBe('ENTITLEMENT-REMOTE-PRIVATE');
    } finally {
        config(['app.key' => $oldKey]);
        Crypt::swap($oldEncrypter);
    }
});

it('rolls back the archive if its mandatory audit cannot be written', function (): void {
    [$user, $instance] = entitlementDeliveredCard();
    DB::unprepared("CREATE TRIGGER entitlement_archive_audit_failure BEFORE INSERT ON audit_logs WHEN NEW.action = 'instance.entitlement_archived' BEGIN SELECT RAISE(ABORT, 'archive audit unavailable'); END");
    expect(fn () => app(ArchiveOneTimeEntitlementAction::class)->execute($user, $instance, 'Archive-Password-123', (string) Str::ulid(), true))->toThrow(QueryException::class)
        ->and(InstanceEntitlementArchive::count())->toBe(0)
        ->and($instance->fresh()->status)->toBe(InstanceStatus::ACTIVE)
        ->and(RedemptionCode::where('instance_id', $instance->id)->sole()->status)->toBe('sold');
    DB::unprepared('DROP TRIGGER entitlement_archive_audit_failure');
});

it('provides a private owner-only confirmation flow with explicit saving instructions', function (): void {
    [$user, $instance] = entitlementDeliveredCard();
    $path = '/console/instances/'.$instance->id.'/archive';
    $this->actingAs($user)->get($path)->assertOk()->assertSee('请先保存卡密')->assertSee('当前密码')->assertHeader('Cache-Control', 'no-store, private');
    $this->post($path, ['request_id' => (string) Str::ulid(), 'current_password' => 'wrong', 'confirm' => '1'])->assertSessionHasErrors('current_password');
    $this->post($path, ['request_id' => (string) Str::ulid(), 'current_password' => 'Archive-Password-123', 'confirm' => '1'])->assertRedirect($path);
    $this->get($path)->assertOk()->assertSee('当前交付证据核验通过')->assertDontSee('name="current_password"', false);
    $other = User::factory()->create();
    $this->actingAs($other)->withSession(['password_hash_web' => $other->password])->get($path)->assertForbidden();
    $this->post($path, ['request_id' => (string) Str::ulid(), 'current_password' => 'Archive-Password-123', 'confirm' => '1'])->assertForbidden();
});

it('allows the archived delivery closure exception while keeping ownership tied to the original identity', function (): void {
    [$user, $instance, $invoice, , $card] = entitlementDeliveredCard();
    $evidence = app(OneTimeEntitlementEvidence::class);
    expect(app(AccountClosureEligibility::class)->forUser($user)['reasons'])->toHaveKey('instances');
    $requestId = (string) Str::ulid();
    $archive = app(ArchiveOneTimeEntitlementAction::class)->execute($user, $instance, 'Archive-Password-123', $requestId, true);
    expect(app(AccountClosureEligibility::class)->forUser($user)['eligible'])->toBeTrue();
    $closed = app(CloseCustomerAccountAction::class)->execute($user, 'Archive-Password-123', true);
    $new = app(RegisterCustomerAction::class)->execute('New archive identity', $user->email, 'Independent-New-123', null);
    expect($closed->trashed())->toBeTrue()->and($new->id)->not->toBe($user->id)
        ->and($archive->fresh()->user_id)->toBe($user->id)->and($invoice->fresh()->user_id)->toBe($user->id)
        ->and($instance->fresh()->user_id)->toBe($user->id)->and($instance->fresh()->status)->toBe(InstanceStatus::ACTIVE)
        ->and($card->fresh()->status)->toBe('sold')->and($evidence->validArchive($instance))->toBeTrue()
        ->and(InstanceEntitlementArchive::where('user_id', $new->id)->count())->toBe(0)
        ->and(fn () => app(ArchiveOneTimeEntitlementAction::class)->execute($closed, $instance, 'Archive-Password-123', $requestId, true))->toThrow(ValidationException::class);
    $this->actingAs($new)->get('/console/instances/'.$instance->id.'/archive')->assertForbidden();
});
