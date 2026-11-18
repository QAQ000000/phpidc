<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\BackfillExternalPayoutReceiptsAction;
use App\Actions\ClaimExternalPayoutReceiptAction;
use App\Actions\ProcessExternalAffiliateWithdrawalAction;
use App\Actions\RequestExternalAffiliateWithdrawalAction;
use App\Models\ExternalPayoutReceipt;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\WalletClearance;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
    Queue::fake();
});

function receiptAffiliateFixture(): array
{
    $owner = User::factory()->create(['affiliate_balance' => '30.00']);
    $actor = User::factory()->create();
    foreach (['external-payout-reviewer', 'external-payout-operator', 'external-payout-confirmer'] as $role) {
        app(AssignAdminRoleAction::class)->execute($actor, $role, 'Shared receipt isolated authority');
    }
    $withdrawal = app(RequestExternalAffiliateWithdrawalAction::class)->execute($owner,
        ['request_id' => (string) Str::ulid(), 'amount' => '10.00', 'payout_method' => 'alipay', 'payee_name' => 'Offline payee', 'payee_account' => 'SIMULATED-ONLY']);
    $action = app(ProcessExternalAffiliateWithdrawalAction::class);
    foreach (['approve', 'start'] as $operation) {
        $action->execute($withdrawal, $actor, ['request_id' => (string) Str::ulid(), 'operation' => $operation, 'reason' => 'Offline registry fixture', 'confirm' => true]);
    }

    return [$owner, $actor, $withdrawal->fresh()];
}

function receiptPaidInput($withdrawal, string $reference = ' SHARED-RECEIPT '): array
{
    return ['request_id' => (string) Str::ulid(), 'operation' => 'paid', 'reason' => 'Offline confirmed receipt', 'confirm' => true,
        'intent_key' => $withdrawal->payout_intent_key, 'currency' => 'CNY', 'confirmed_amount' => '10.00',
        'evidence' => 'SIMULATED-EVIDENCE-ONLY', 'external_reference' => $reference];
}

function receiptWalletFixture(User $actor, array $changes = []): WalletClearance
{
    $owner = User::factory()->create();

    return WalletClearance::create(array_replace(['user_id' => $owner->id, 'request_id' => (string) Str::ulid(), 'scenario' => 'closure_balance',
        'amount' => '10.00', 'currency' => 'CNY', 'payout_method' => 'alipay', 'payout_details' => ['account' => 'SIMULATED-ONLY'],
        'status' => 'processing', 'requested_by' => $owner->id, 'payout_intent_key' => 'WC'.Str::ulid()], $changes));
}

it('atomically registers a confirmed affiliate payout once with its money and normalized evidence', function (): void {
    [$owner, $actor, $withdrawal] = receiptAffiliateFixture();
    $input = receiptPaidInput($withdrawal);
    $action = app(ProcessExternalAffiliateWithdrawalAction::class);
    $action->execute($withdrawal, $actor, $input);
    $action->execute($withdrawal, $actor, $input);
    $receipt = ExternalPayoutReceipt::sole();
    expect($receipt->payout_method)->toBe('alipay')->and($receipt->external_reference)->toBe('SHARED-RECEIPT')
        ->and($receipt->amount)->toBe('10.00')->and($receipt->currency)->toBe('CNY')
        ->and($receipt->affiliate_withdrawal_id)->toBe($withdrawal->id)->and($receipt->wallet_clearance_id)->toBeNull()
        ->and($receipt->confirmed_by)->toBe($actor->id)->and($owner->fresh()->affiliate_frozen_balance)->toBe('0.00')
        ->and(FinancialTransaction::where('reference_key', 'payout:'.$withdrawal->id)->count())->toBe(1);
    Http::assertNothingSent();
});

it('keeps the registry empty when later payout audit persistence fails', function (): void {
    [$owner, $actor, $withdrawal] = receiptAffiliateFixture();
    $active = true;
    DB::listen(function (QueryExecuted $query) use (&$active): void {
        if ($active && preg_match('/^insert into ["`]?audit_logs["`]? /i', $query->sql) === 1) {
            throw new RuntimeException('Injected shared receipt audit failure');
        }
    });
    try {
        expect(fn () => app(ProcessExternalAffiliateWithdrawalAction::class)->execute($withdrawal, $actor, receiptPaidInput($withdrawal)))
            ->toThrow(RuntimeException::class, 'Injected shared receipt audit failure');
    } finally {
        $active = false;
    }
    expect(ExternalPayoutReceipt::count())->toBe(0)->and($withdrawal->fresh()->status)->toBe('processing')
        ->and($owner->fresh()->affiliate_frozen_balance)->toBe('10.00')
        ->and(FinancialTransaction::where('reference_key', 'payout:'.$withdrawal->id)->count())->toBe(0);
});

it('blocks reuse of a historical paid affiliate receipt before backfill registers it', function (): void {
    [, $actor, $withdrawal] = receiptAffiliateFixture();
    app(ProcessExternalAffiliateWithdrawalAction::class)->execute($withdrawal, $actor, receiptPaidInput($withdrawal, 'PRIVATE-LEGACY-RECEIPT'));
    DB::table('external_payout_receipts')->delete(); // Recreate the old, not-yet-backfilled registry state.
    $wallet = receiptWalletFixture($actor);
    expect(fn () => DB::transaction(fn () => app(ClaimExternalPayoutReceiptAction::class)->execute('alipay', ' private-legacy-receipt ', '10.00', 'CNY', $actor, walletClearanceId: $wallet->id)))
        ->toThrow(ValidationException::class);
    expect(ExternalPayoutReceipt::count())->toBe(0)->and($wallet->fresh()->status)->toBe('processing');
});

it('blocks an affiliate payment when the same receipt belongs to an unregistered paid wallet clearance', function (): void {
    [$owner, $actor, $withdrawal] = receiptAffiliateFixture();
    receiptWalletFixture($actor, ['status' => 'paid', 'external_reference' => ' legacy-wallet ', 'confirmed_by' => $actor->id]);
    expect(fn () => app(ProcessExternalAffiliateWithdrawalAction::class)->execute($withdrawal, $actor, receiptPaidInput($withdrawal, 'LEGACY-WALLET')))
        ->toThrow(ValidationException::class);
    expect($withdrawal->fresh()->status)->toBe('processing')->and($owner->fresh()->affiliate_frozen_balance)->toBe('10.00')
        ->and(ExternalPayoutReceipt::count())->toBe(0)->and(FinancialTransaction::where('reference_key', 'payout:'.$withdrawal->id)->count())->toBe(0);
});

it('refuses to replace an existing business receipt or change its amount and ownership', function (): void {
    [, $actor] = receiptAffiliateFixture();
    $wallet = receiptWalletFixture($actor);
    $claim = app(ClaimExternalPayoutReceiptAction::class);
    DB::transaction(fn () => $claim->execute('alipay', 'ONE-RECEIPT', '10.00', 'CNY', $actor, walletClearanceId: $wallet->id));
    DB::transaction(fn () => $claim->execute('alipay', ' one-receipt ', '10.00', 'CNY', $actor, walletClearanceId: $wallet->id));
    expect(fn () => DB::transaction(fn () => $claim->execute('alipay', 'OTHER-RECEIPT', '10.00', 'CNY', $actor, walletClearanceId: $wallet->id)))
        ->toThrow(ValidationException::class);
    expect(fn () => DB::transaction(fn () => $claim->execute('alipay', 'ONE-RECEIPT', '9.00', 'CNY', $actor, walletClearanceId: $wallet->id)))
        ->toThrow(ValidationException::class);
    expect(ExternalPayoutReceipt::count())->toBe(1)->and(ExternalPayoutReceipt::sole()->external_reference)->toBe('ONE-RECEIPT');
});

it('backfills historical paid receipts idempotently without modifying balances ledgers or original evidence', function (): void {
    [, $actor, $withdrawal] = receiptAffiliateFixture();
    app(ProcessExternalAffiliateWithdrawalAction::class)->execute($withdrawal, $actor, receiptPaidInput($withdrawal, 'ORIGINAL-PAID-RECEIPT'));
    DB::table('external_payout_receipts')->delete();
    $wallet = receiptWalletFixture($actor, ['status' => 'paid', 'external_reference' => 'WALLET-PAID-RECEIPT', 'confirmed_by' => $actor->id]);
    $original = json_encode(['users' => DB::table('users')->get(), 'ledger' => DB::table('financial_transactions')->get(),
        'affiliate' => $withdrawal->fresh()->getRawOriginal(), 'wallet' => $wallet->fresh()->getRawOriginal()], JSON_THROW_ON_ERROR);
    $action = app(BackfillExternalPayoutReceiptsAction::class);
    $preview = $action->execute(dryRun: true);
    expect($preview['scanned'])->toBe(2)->and($preview['would_register'])->toBe(2)->and($preview['registered'])->toBe(0)
        ->and($preview['conflicts'])->toBe([])->and(ExternalPayoutReceipt::count())->toBe(0);
    $first = $action->execute();
    $second = $action->execute();
    expect($first['registered'])->toBe(2)->and($first['conflicts'])->toBe([])->and($second['registered'])->toBe(0)
        ->and($second['existing'])->toBe(2)->and($second['conflicts'])->toBe([])->and(ExternalPayoutReceipt::count())->toBe(2);
    expect(json_encode(['users' => DB::table('users')->get(), 'ledger' => DB::table('financial_transactions')->get(),
        'affiliate' => $withdrawal->fresh()->getRawOriginal(), 'wallet' => $wallet->fresh()->getRawOriginal()], JSON_THROW_ON_ERROR))->toBe($original);
});

it('reports all duplicate historical owners without selecting a winner or printing raw receipt details', function (): void {
    [, $actor, $withdrawal] = receiptAffiliateFixture();
    app(ProcessExternalAffiliateWithdrawalAction::class)->execute($withdrawal, $actor, receiptPaidInput($withdrawal, 'PRIVATE-CONFLICT-RECEIPT'));
    DB::table('external_payout_receipts')->delete();
    $wallet = receiptWalletFixture($actor, ['status' => 'paid', 'external_reference' => ' private-conflict-receipt ', 'confirmed_by' => $actor->id]);
    $report = app(BackfillExternalPayoutReceiptsAction::class)->execute();
    expect($report['scanned'])->toBe(2)->and($report['registered'])->toBe(0)->and($report['conflicts'])->toHaveCount(2)
        ->and(array_column($report['conflicts'], 'kind'))->toBe(['affiliate', 'wallet'])
        ->and($report['conflicts'][0]['receipt_fingerprint'])->toBe($report['conflicts'][1]['receipt_fingerprint'])
        ->and(ExternalPayoutReceipt::count())->toBe(0)->and($withdrawal->fresh()->status)->toBe('paid')->and($wallet->fresh()->status)->toBe('paid');
    expect(Artisan::call('payments:backfill-payout-receipts', ['--json' => true]))->toBe(1);
    $output = Artisan::output();
    expect($output)->not->toContain('PRIVATE-CONFLICT-RECEIPT')->and(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['conflicts'])->toHaveCount(2);
});

it('reports missing historical confirmer evidence instead of silently ignoring the paid item', function (): void {
    [, $actor, $withdrawal] = receiptAffiliateFixture();
    app(ProcessExternalAffiliateWithdrawalAction::class)->execute($withdrawal, $actor, receiptPaidInput($withdrawal));
    DB::table('external_payout_receipts')->delete();
    DB::table('affiliate_withdrawals')->where('id', $withdrawal->id)->update(['confirmed_by' => null]);
    $report = app(BackfillExternalPayoutReceiptsAction::class)->execute();
    expect($report['registered'])->toBe(0)->and($report['conflicts'])->toHaveCount(1)
        ->and($report['conflicts'][0]['id'])->toBe($withdrawal->id)->and(ExternalPayoutReceipt::count())->toBe(0);
});

it('enforces exactly one receipt owner in the database', function (bool $both): void {
    [, $actor, $withdrawal] = receiptAffiliateFixture();
    $wallet = receiptWalletFixture($actor);
    expect(fn () => DB::transaction(fn () => DB::table('external_payout_receipts')->insert(['payout_method' => 'alipay', 'external_reference' => 'INVALID-OWNER',
        'amount' => '10.00', 'currency' => 'CNY', 'confirmed_by' => $actor->id, 'affiliate_withdrawal_id' => $both ? $withdrawal->id : null,
        'wallet_clearance_id' => $both ? $wallet->id : null, 'created_at' => now()])))
        ->toThrow(QueryException::class);
    expect(ExternalPayoutReceipt::count())->toBe(0);
})->with([false, true]);
