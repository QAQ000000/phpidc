<?php

declare(strict_types=1);

use App\Actions\AssignInventoryRoleAction;
use App\Actions\CreateComboInvoiceAction;
use App\Actions\ExecuteFulfillmentTaskAction;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Filament\Pages\InventoryCards;
use App\Models\FulfillmentTask;
use App\Models\Product;
use App\Models\RedemptionCode;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);
beforeEach(function (): void {
    $this->installApplicationForTest();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function inventoryUser(string $role): User
{
    $user = User::factory()->create();
    app(AssignInventoryRoleAction::class)->execute($user, $role, 'Inventory acceptance access');

    return $user;
}

it('denies inventory entry to anonymous users and ordinary customers even in local mode', function (): void {
    config(['app.env' => 'local']);
    $this->get('/admin/inventory')->assertRedirect('/admin/login');
    $customer = User::factory()->create();
    expect($customer->canAccessPanel(Panel::make()->id('admin')))->toBeFalse();
    $this->actingAs($customer)->get('/admin/inventory')->assertForbidden();
    $this->post('/admin/inventory/import', ['product_id' => 1, 'codes' => 'NO-ACCESS', 'reason' => 'Forged'])->assertForbidden();
    $this->post('/admin/fulfillment/1/retry', ['reason' => 'Forged', 'confirm' => 1])->assertNotFound();
    $this->assertDatabaseCount('redemption_codes', 0);
});

it('permits read-only inventory metadata without selecting or serializing secrets', function (): void {
    $viewer = inventoryUser('inventory-viewer');
    $product = Product::factory()->create(['name' => 'Local card']);
    $card = RedemptionCode::factory()->create(['product_id' => $product->id, 'code' => 'PRIVATE-INVENTORY-CARD']);
    $this->actingAs($viewer);
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    foreach (['/admin/inventory', '/admin/inventory/cards', '/admin/fulfillment'] as $path) {
        $this->get($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('PRIVATE-INVENTORY-CARD')->assertDontSee('加密导入');
    }
    foreach ($queries as $sql) {
        if (str_starts_with(strtolower($sql), 'select') && str_contains($sql, 'redemption_codes')) {
            expect($sql)->not->toContain('"code"')->not->toContain('"code_hash"')->not->toContain('select *');
        }
    }
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test(InventoryCards::class)->assertSee('Local card')->assertDontSee('PRIVATE-INVENTORY-CARD')->assertDontSee($card->code_hash);
    $this->post('/admin/inventory/import', ['product_id' => $product->id, 'codes' => 'FORGED', 'reason' => 'Forged'])->assertForbidden();
    $task = FulfillmentTask::factory()->create(['status' => 'failed']);
    $this->post('/admin/fulfillment/'.$task->id.'/retry', ['reason' => 'Forged', 'confirm' => 1])->assertForbidden();
    expect(Gate::forUser($viewer)->allows('refund-invoice', $task->invoice))->toBeFalse();
});

it('imports encrypted cards with byte-preserving deduplication and a real actor audit', function (): void {
    $operator = inventoryUser('inventory-operator');
    $product = Product::factory()->create();
    $this->actingAs($operator)->post('/admin/inventory/import', ['product_id' => $product->id,
        'codes' => "  CARD-WITH-SPACES  \r\nCARD-SECOND\r\nCARD-SECOND\r\n", 'reason' => 'Replenish shortage'])
        ->assertRedirect('/admin/inventory/cards?product_id='.$product->id)->assertSessionHas('inventory_status', '新增 2 张，重复 1 张。');
    $this->assertDatabaseCount('redemption_codes', 2);
    expect(RedemptionCode::orderBy('id')->first()->code)->toBe('  CARD-WITH-SPACES  ');
    $this->assertDatabaseHas('audit_logs', ['action' => 'cards.import', 'actor_id' => $operator->id, 'actor_type' => 'user', 'reason' => 'Replenish shortage']);
    foreach (['redemption_codes' => 'code', 'audit_logs' => 'after'] as $table => $column) {
        expect(DB::table($table)->pluck($column)->implode(' '))->not->toContain('CARD-SECOND');
    }
    $this->get('/admin/inventory/cards')->assertOk()->assertDontSee('CARD-SECOND');
});

it('never flashes submitted card plaintext on failed validation or nonlocal product selection', function (): void {
    $this->actingAs(inventoryUser('inventory-operator'));
    $product = Product::factory()->create(['driver_type' => DriverType::ZJMF_API]);
    $this->from('/admin/inventory/cards')->post('/admin/inventory/import', ['product_id' => $product->id, 'codes' => 'PRIVATE-FAILED-IMPORT', 'reason' => str_repeat('x', 1001)])
        ->assertSessionHasErrors('reason')->assertSessionMissing('_old_input.codes');
    $this->from('/admin/inventory/cards')->post('/admin/inventory/import', ['product_id' => $product->id, 'codes' => 'PRIVATE-FAILED-IMPORT', 'reason' => 'Wrong product'])
        ->assertSessionHasErrors('operation')->assertSessionMissing('_old_input.codes');
    $this->get('/admin/inventory/cards')->assertDontSee('PRIVATE-FAILED-IMPORT');
    $this->assertDatabaseCount('redemption_codes', 0);
});

it('keeps paid shortages manual at the retry limit and delivers exactly once after replenishment and confirmation', function (): void {
    Queue::fake();
    $buyer = User::factory()->create(['credit' => '20.00']);
    $product = Product::factory()->create(['local_prices' => ['onetime' => ['price' => '10.00', 'setup_fee' => '0.00', 'enabled' => true]]]);
    $invoice = app(CreateComboInvoiceAction::class)->execute($buyer, $product, BillingCycle::ONETIME, [], true, input: ['request_id' => (string) Str::ulid()]);
    $task = FulfillmentTask::sole();
    $task->update(['max_attempts' => 1]);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED)->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID);
    $operator = inventoryUser('inventory-operator');
    $this->actingAs($operator)->get('/admin/fulfillment')->assertSee($invoice->invoice_no)->assertSee('缺货候选');
    $this->post('/admin/inventory/import', ['product_id' => $product->id, 'codes' => 'SINGLE-DELIVERY', 'reason' => 'Stock arrived'])->assertRedirect();
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED);
    $this->post('/admin/fulfillment/'.$task->id.'/retry', ['reason' => 'Stock replenished'])->assertSessionHasErrors('confirm');
    $this->post('/admin/fulfillment/'.$task->id.'/retry', ['reason' => 'Stock replenished', 'confirm' => 1])->assertRedirect('/admin/fulfillment');
    $this->post('/admin/fulfillment/'.$task->id.'/retry', ['reason' => 'Double click', 'confirm' => 1])->assertSessionHasErrors('operation');
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($buyer->fresh()->credit)->toBe('10.00')->and(RedemptionCode::sole()->status)->toBe('sold')
        ->and($task->instance->fresh()->encrypted_credentials['card_key'])->toBe('SINGLE-DELIVERY');
    $this->assertDatabaseCount('fulfillment_tasks', 1);
    expect(DB::table('financial_transactions')->where('reference_key', 'settle:'.$invoice->id)->count())->toBe(1);
    $this->assertDatabaseHas('audit_logs', ['action' => 'fulfillment.retry', 'actor_id' => $operator->id, 'reason' => 'Stock replenished']);
    $this->get('/admin/fulfillment')->assertDontSee($invoice->invoice_no);
});

it('rejects retry after payment or instance or binding changes without dispatching a job', function (string $change): void {
    Queue::fake();
    $task = FulfillmentTask::factory()->create(['status' => 'failed']);
    match ($change) {
        'refunded' => $task->invoice->update(['status' => InvoiceStatus::REFUNDED]),
        'unpaid' => $task->invoice->update(['status' => InvoiceStatus::UNPAID]),
        'terminal' => $task->instance->update(['status' => InstanceStatus::CANCELLED]),
        'delivered' => $task->instance->update(['encrypted_credentials' => ['card_key' => 'ALREADY-SOLD']]),
        'binding' => $task->instance->update(['driver_type' => DriverType::SUB2API]),
        'upstream' => $task->instance->update(['driver_type' => DriverType::ZJMF_API]),
        'running' => $task->update(['status' => FulfillmentTaskStatus::RUNNING, 'lease_expires_at' => now()->addMinutes(5)]),
    };
    $this->actingAs(inventoryUser('inventory-operator'))->post('/admin/fulfillment/'.$task->id.'/retry', ['reason' => 'Stale page', 'confirm' => 1])->assertSessionHasErrors('operation');
    Queue::assertNothingPushed();
    expect(DB::table('audit_logs')->where('action', 'fulfillment.retry')->count())->toBe(0);
})->with(['refunded', 'unpaid', 'terminal', 'delivered', 'binding', 'upstream', 'running']);

it('revokes inventory access for pages, Livewire hydration and forged posts through an audited CLI', function (): void {
    $operator = inventoryUser('inventory-operator');
    $this->actingAs($operator);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $component = Livewire::test(InventoryCards::class)->assertOk();
    $this->artisan('inventory:role', ['user' => $operator->id, 'role' => 'inventory-operator', '--revoke' => true, '--reason' => 'Operator left team'])->assertSuccessful();
    $operator->unsetRelation('roles')->unsetRelation('permissions');
    $this->get('/admin/inventory')->assertForbidden();
    $component->call('$refresh')->assertForbidden();
    $this->post('/admin/inventory/import', ['product_id' => 1, 'codes' => 'FORGED', 'reason' => 'Revoked'])->assertForbidden();
    $this->assertDatabaseHas('audit_logs', ['action' => 'inventory.role.revoke', 'reason' => 'Operator left team', 'subject_id' => $operator->id]);
});

it('rejects unknown roles and missing reasons and never grants unrelated financial access', function (): void {
    $user = User::factory()->create();
    $assign = app(AssignInventoryRoleAction::class);
    expect(fn () => $assign->execute($user, 'super_admin', 'Unapproved authority'))->toThrow(DomainException::class)
        ->and(fn () => $assign->execute($user, 'inventory-operator', ''))->toThrow(DomainException::class);
    $assign->execute($user, 'inventory-operator', 'Inventory only');
    expect($user->can('inventory.import'))->toBeTrue()->and($user->can('refund-invoice'))->toBeFalse()
        ->and($user->canAccessPanel(Panel::make()->id('console')))->toBeFalse();
});
