<?php

declare(strict_types=1);

use App\Actions\AdjustCustomerWalletAction;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\NotificationManager;
use App\Services\ReceivableAdmission;
use App\Support\AdminFinancialAccess;
use App\Support\WalletCapacity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Http::preventStrayRequests();
    Queue::fake();
    $this->mock(NotificationManager::class)->shouldNotReceive('queue', 'queueOperations');
    $this->mock(ReceivableAdmission::class)->shouldNotReceive('run', 'runMany', 'prepare', 'assertLocked');
});

afterEach(function (): void {
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

function c3WalletActor(array $permissions = ['finance.view', 'finance.wallet-adjust']): User
{
    $actor = User::factory()->create();
    foreach ($permissions as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $actor;
}

function c3WalletInput(array $changes = []): array
{
    return array_replace(['request_id' => (string) Str::ulid(), 'direction' => 'credit', 'amount' => '2.50',
        'currency' => 'CNY', 'confirm' => '1'], $changes);
}

it('adjusts only available wallet value with an explicit direction original-owner ledger and real administrator audit', function (string $direction, string $expected): void {
    $actor = c3WalletActor();
    $customer = User::factory()->create(['credit' => '12.00', 'frozen_credit' => '3.45', 'wallet_clearance_frozen' => '6.78',
        'affiliate_balance' => '9.01', 'affiliate_frozen_balance' => '2.34', 'receivable_revision' => 12,
        'receivable_reconciliation_required' => true, 'disabled_at' => now()]);
    $originalDisabled = $customer->disabled_at;
    $input = c3WalletInput(['direction' => $direction, 'reason' => '  核对原始补差  ', 'credit' => '999.99',
        'frozen_credit' => '0.00', 'wallet_clearance_frozen' => '0.00', 'user_id' => $actor->id, 'disabled_at' => null]);
    $entry = app(AdjustCustomerWalletAction::class)->execute($customer, $actor, $input);
    $current = $customer->fresh();
    expect($current->credit)->toBe($expected)->and($current->frozen_credit)->toBe('3.45')
        ->and($current->wallet_clearance_frozen)->toBe('6.78')->and($current->affiliate_balance)->toBe('9.01')
        ->and($current->affiliate_frozen_balance)->toBe('2.34')->and($current->receivable_revision)->toBe(12)
        ->and($current->receivable_reconciliation_required)->toBeTrue()->and($current->disabled_at->eq($originalDisabled))->toBeTrue();
    expect($entry->user_id)->toBe($customer->id)->and($entry->invoice_id)->toBeNull()->and($entry->amount)->toBe('2.50')
        ->and($entry->type)->toBe($direction === 'credit' ? TransactionType::WALLET_ADJUST_CREDIT : TransactionType::WALLET_ADJUST_DEBIT)
        ->and($entry->type->isCredit())->toBe($direction === 'credit')->and($entry->balance_after)->toBe($expected)
        ->and($entry->frozen_after)->toBe('3.45')->and($entry->reference_key)->toBe('adjust:'.$input['request_id'])
        ->and($entry->meta)->toBe(['version' => 1, 'request_id' => $input['request_id'], 'owner_id' => $customer->id,
            'actor_id' => $actor->id, 'direction' => $direction, 'amount' => '2.50', 'currency' => 'CNY',
            'reason' => '核对原始补差', 'wallet_clearance_frozen_after' => '6.78']);
    $audit = DB::table('audit_logs')->where('action', 'wallet.adjusted')->sole();
    expect($audit->actor_id)->toBe($actor->id)->and($audit->actor_type)->toBe('user')
        ->and($audit->subject_type)->toBe(FinancialTransaction::class)->and($audit->subject_id)->toBe($entry->id)
        ->and($audit->reason)->toBe('核对原始补差')->and($audit->request_id)->not->toBeNull();
    foreach (['invoices', 'payment_attempts', 'payment_callbacks', 'fulfillment_tasks', 'affiliate_receivable_settlements', 'notification_events'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
})->with([['credit', '14.50'], ['debit', '9.50']]);

it('returns the original row for a canonical replay without another audit even after funds or target closure change', function (string $change): void {
    $actor = c3WalletActor();
    $customer = User::factory()->create(['credit' => '10.00']);
    $input = c3WalletInput(['request_id' => strtolower((string) Str::ulid()), 'direction' => 'debit', 'amount' => '10.00']);
    $entry = app(AdjustCustomerWalletAction::class)->execute($customer, $actor, $input);
    if ($change === 'closed') {
        $customer->forceFill(['deleted_at' => now(), 'anonymized_at' => now()])->save();
    } elseif ($change === 'privileged') {
        $customer->givePermissionTo(Permission::findOrCreate('finance.view', 'web'));
    }
    $replay = app(AdjustCustomerWalletAction::class)->execute($customer, $actor, $input);
    expect($replay->id)->toBe($entry->id)->and($replay->reference_key)->toBe('adjust:'.strtoupper($input['request_id']))
        ->and(User::withTrashed()->findOrFail($customer->id)->credit)->toBe('0.00');
    $this->assertDatabaseCount('financial_transactions', 1);
    expect(DB::table('audit_logs')->where('action', 'wallet.adjusted')->count())->toBe(1);
})->with(['funds', 'closed', 'privileged']);

it('rejects a changed intent under the same request without new money or audit', function (string $change): void {
    $actor = c3WalletActor();
    $customer = User::factory()->create(['credit' => '10.00']);
    $input = c3WalletInput();
    app(AdjustCustomerWalletAction::class)->execute($customer, $actor, $input);
    $target = $customer;
    $replayActor = $actor;
    if ($change === 'owner') {
        $target = User::factory()->create(['credit' => '77.00']);
    } elseif ($change === 'actor') {
        $replayActor = c3WalletActor();
    } else {
        $input[$change] = match ($change) {
            'amount' => '2.51', 'direction' => 'debit', default => '另一原因'
        };
    }
    expect(fn () => app(AdjustCustomerWalletAction::class)->execute($target, $replayActor, $input))->toThrow(ValidationException::class);
    expect($customer->fresh()->credit)->toBe('12.50');
    if ($change === 'owner') {
        expect($target->fresh()->credit)->toBe('77.00');
    }
    $this->assertDatabaseCount('financial_transactions', 1);
    expect(DB::table('audit_logs')->where('action', 'wallet.adjusted')->count())->toBe(1);
})->with(['amount', 'direction', 'reason', 'owner', 'actor']);

it('requires fresh finance viewing and exact wallet authority for new writes and successful replays', function (array $permissions): void {
    $customer = User::factory()->create(['credit' => '10.00']);
    $input = c3WalletInput();
    $actor = c3WalletActor();
    $entry = app(AdjustCustomerWalletAction::class)->execute($customer, $actor, $input);
    expect($actor->can('finance.wallet-adjust'))->toBeTrue();
    $actor->syncPermissions(array_map(fn (string $name) => Permission::findOrCreate($name, 'web'), $permissions));
    foreach ([$input, c3WalletInput()] as $request) {
        expect(fn () => app(AdjustCustomerWalletAction::class)->execute($customer, $actor, $request))->toThrow(AuthorizationException::class);
    }
    expect($customer->fresh()->credit)->toBe('12.50')->and(FinancialTransaction::sole()->id)->toBe($entry->id);
})->with([
    'view only' => [['finance.view']],
    'wallet only' => [['finance.wallet-adjust']],
    'other financial authority' => [['finance.view', 'finance.offline-receipt']],
    'customer management only' => [['customers.view', 'customers.manage']],
]);

it('rejects stale disabled deleted or anonymized administrators before replay or a new movement', function (string $state): void {
    $actor = c3WalletActor();
    $customer = User::factory()->create();
    $input = c3WalletInput();
    app(AdjustCustomerWalletAction::class)->execute($customer, $actor, $input);
    User::whereKey($actor->id)->update([$state => now()]);
    foreach ([$input, c3WalletInput()] as $request) {
        expect(fn () => app(AdjustCustomerWalletAction::class)->execute($customer, $actor, $request))->toThrow(HttpException::class);
    }
    expect($customer->fresh()->credit)->toBe('2.50');
    $this->assertDatabaseCount('financial_transactions', 1);
})->with(['disabled_at', 'deleted_at', 'anonymized_at']);

it('protects self privileged and closed targets on every new request', function (string $kind): void {
    $actor = c3WalletActor();
    $target = $kind === 'self' ? $actor : User::factory()->create();
    match ($kind) {
        'role' => $target->assignRole(Role::findOrCreate('c3-wallet-protected', 'web')),
        'permission' => $target->givePermissionTo(Permission::findOrCreate('support.view', 'web')),
        'deleted' => $target->forceFill(['deleted_at' => now()])->save(),
        'anonymized' => $target->forceFill(['anonymized_at' => now()])->save(),
        default => null,
    };
    expect(app(AdminFinancialAccess::class)->isManageableTarget($actor, $target))->toBeFalse();
    expect(fn () => app(AdjustCustomerWalletAction::class)->execute($target, $actor, c3WalletInput()))->toThrow(HttpException::class);
    expect(User::withTrashed()->findOrFail($target->id)->credit)->toBe('0.00');
    $this->assertDatabaseCount('financial_transactions', 0);
    expect(DB::table('audit_logs')->where('action', 'wallet.adjusted')->count())->toBe(0);
})->with(['self', 'role', 'permission', 'deleted', 'anonymized']);

it('uses the full wallet capacity and preserves existing overcapacity only for a reducing adjustment', function (): void {
    $actor = c3WalletActor();
    $customer = User::factory()->create(['credit' => '9999999999.90', 'frozen_credit' => '0.04', 'wallet_clearance_frozen' => '0.05']);
    expect(fn () => app(AdjustCustomerWalletAction::class)->execute($customer, $actor, c3WalletInput(['amount' => '0.01'])))->toThrow(ValidationException::class);
    expect($customer->fresh()->credit)->toBe('9999999999.90');
    $customer->forceFill(['frozen_credit' => '0.10'])->save();
    $entry = app(AdjustCustomerWalletAction::class)->execute($customer, $actor, c3WalletInput(['direction' => 'debit', 'amount' => '0.01']));
    expect($entry->balance_after)->toBe('9999999999.89')->and($entry->frozen_after)->toBe('0.10')
        ->and($entry->meta['wallet_clearance_frozen_after'])->toBe('0.05');
    expect(fn () => app(AdjustCustomerWalletAction::class)->execute($customer, $actor, c3WalletInput(['amount' => '0.01'])))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('financial_transactions', 1);
});

it('rejects a deduction above available value without consuming either reservation or commissions', function (): void {
    $customer = User::factory()->create(['credit' => '1.00', 'frozen_credit' => '9.00', 'wallet_clearance_frozen' => '7.00', 'affiliate_balance' => '8.00']);
    expect(fn () => app(AdjustCustomerWalletAction::class)->execute($customer, c3WalletActor(),
        c3WalletInput(['direction' => 'debit', 'amount' => '1.01'])))->toThrow(ValidationException::class);
    expect(WalletCapacity::snapshot($customer->fresh()))->toBe(['credit' => '1.00', 'frozen_credit' => '9.00', 'wallet_clearance_frozen' => '7.00'])
        ->and($customer->fresh()->affiliate_balance)->toBe('8.00');
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('rejects malformed unsigned decimal amounts confirmations currencies and identifiers before any financial write', function (array $changes): void {
    $customer = User::factory()->create(['credit' => '10.00']);
    expect(fn () => app(AdjustCustomerWalletAction::class)->execute($customer, c3WalletActor(), c3WalletInput($changes)))->toThrow(ValidationException::class);
    expect($customer->fresh()->credit)->toBe('10.00');
    $this->assertDatabaseCount('financial_transactions', 0);
})->with([
    [['amount' => '0.00']], [['amount' => '-1.00']], [['amount' => '+1.00']], [['amount' => '1']], [['amount' => '1.001']],
    [['amount' => 1.0]], [['amount' => '10000000000.00']], [['currency' => 'USD']], [['confirm' => '0']],
    [['request_id' => 'same-key']], [['reason' => str_repeat('界', 256)]], [['direction' => 'adjustment']],
]);

it('accepts exact boundary amounts and retains a zero text note without requiring a customer-view role', function (): void {
    $customer = User::factory()->create();
    $actor = c3WalletActor();
    $entry = app(AdjustCustomerWalletAction::class)->execute($customer, $actor, c3WalletInput(['amount' => WalletCapacity::LIMIT, 'reason' => '0']));
    expect($entry->amount)->toBe(WalletCapacity::LIMIT)->and($entry->remark)->toBe('0')->and($entry->meta['reason'])->toBe('0')
        ->and($actor->can('customers.view'))->toBeFalse();
    app(AdjustCustomerWalletAction::class)->execute($customer, $actor, c3WalletInput(['amount' => WalletCapacity::LIMIT, 'direction' => 'debit']));
    expect($customer->fresh()->credit)->toBe('0.00');
});

it('rolls wallet and append-only ledger back if the mandatory administrator audit fails', function (): void {
    $actor = c3WalletActor();
    $customer = User::factory()->create(['credit' => '10.00']);
    DB::unprepared("CREATE TRIGGER c3_reject_wallet_audit BEFORE INSERT ON audit_logs WHEN NEW.action = 'wallet.adjusted' BEGIN SELECT RAISE(ABORT, 'C3 audit unavailable'); END");
    expect(fn () => app(AdjustCustomerWalletAction::class)->execute($customer, $actor, c3WalletInput()))->toThrow(QueryException::class);
    expect($customer->fresh()->credit)->toBe('10.00');
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('rolls a balance change back when the append-only financial record cannot be inserted', function (): void {
    $actor = c3WalletActor();
    $customer = User::factory()->create(['credit' => '10.00']);
    DB::unprepared("CREATE TRIGGER c3_reject_wallet_ledger BEFORE INSERT ON financial_transactions BEGIN SELECT RAISE(ABORT, 'C3 ledger unavailable'); END");
    expect(fn () => app(AdjustCustomerWalletAction::class)->execute($customer, $actor, c3WalletInput()))->toThrow(QueryException::class);
    expect($customer->fresh()->credit)->toBe('10.00');
    $this->assertDatabaseCount('financial_transactions', 0);
    expect(DB::table('audit_logs')->where('action', 'wallet.adjusted')->count())->toBe(0);
});
