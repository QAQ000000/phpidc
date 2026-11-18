<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\ProcessAffiliateWithdrawalAction;
use App\Actions\RecordAffiliateReceivableSettlementAction;
use App\Actions\RefundInvoiceAction;
use App\Actions\RequestAffiliateWithdrawalAction;
use App\Actions\ReverseAffiliateReceivableSettlementAction;
use App\Enums\TransactionType;
use App\Models\AffiliateCommission;
use App\Models\AffiliateReceivableReversal;
use App\Models\AffiliateReceivableSettlement;
use App\Models\AffiliateWithdrawal;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AffiliateFunds;
use App\Services\AffiliateReceivableBalance;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);
beforeEach(function (): void {
    $this->installApplicationForTest();
    Queue::fake();
    Http::preventStrayRequests();
});

function receivableReviewFixture(): array
{
    $actor = User::factory()->create();
    foreach (['finance-operator', 'finance-receivable', 'finance-payout'] as $role) {
        app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated receivable review');
    }
    $owner = User::factory()->create(['credit' => '17.00', 'affiliate_balance' => '0.00']);
    $invoice = Invoice::factory()->paid()->create(['total_amount' => '100.00', 'gateway_amount' => '100.00']);
    AffiliateCommission::factory()->create(['referrer_id' => $owner->id, 'buyer_id' => $invoice->user_id,
        'invoice_id' => $invoice->id, 'status' => 'available', 'amount' => '10.00']);
    app(RefundInvoiceAction::class)->execute($invoice, 'external_record', keepService: true, reason: 'Completed external refund', actor: $actor);

    return [$owner->fresh(), FinancialTransaction::where('user_id', $owner->id)->where('type', TransactionType::AFFILIATE_CLAWBACK)->sole(), $actor];
}

function receivableReviewInput(FinancialTransaction $root, array $changes = []): array
{
    return array_replace(['amount' => '4.00', 'source' => 'external_received', 'request_id' => (string) Str::ulid(),
        'external_channel' => 'bank', 'external_reference' => 'RECEIPT-'.Str::ulid(), 'received_at' => now()->subMinute()->startOfMinute()->toDateTimeString(),
        'reason' => 'Verified completed external collection', 'confirm' => '1',
        'version' => app(AffiliateReceivableBalance::class)->project($root)['version']], $changes);
}

function receivableReviewReverseInput(FinancialTransaction $root, array $changes = []): array
{
    return array_replace(['request_id' => (string) Str::ulid(), 'reason' => 'Verified collection registration was incorrect',
        'correction_reference' => 'CORRECTION-'.Str::ulid(), 'confirm' => '1',
        'version' => app(AffiliateReceivableBalance::class)->project($root)['version']], $changes);
}

it('partially and fully settles genuine refund debt without moving any wallet or commission balance', function (): void {
    [$owner, $root, $actor] = receivableReviewFixture();
    $original = $root->getAttributes();
    $balances = $owner->only(['credit', 'frozen_credit', 'affiliate_balance', 'affiliate_frozen_balance']);
    $input = receivableReviewInput($root);
    $entry = app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, $input);
    $repeat = app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, $input);
    $projection = app(AffiliateReceivableBalance::class)->project($root);
    expect($projection['valid'])->toBeTrue()->and($projection['principal'])->toBe('10.00')->and($projection['settled'])->toBe('4.00')
        ->and($projection['remaining'])->toBe('6.00')->and($repeat->id)->toBe($entry->id)
        ->and(app(AffiliateFunds::class)->hasReceivable($owner->id))->toBeTrue();

    app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, receivableReviewInput($root, ['amount' => '6.00']));
    expect(app(AffiliateReceivableBalance::class)->project($root)['remaining'])->toBe('0.00')
        ->and(app(AffiliateFunds::class)->hasReceivable($owner->id))->toBeFalse()
        ->and($owner->fresh()->only(array_keys($balances)))->toBe($balances)->and($root->fresh()->getAttributes())->toBe($original)
        ->and(AffiliateReceivableSettlement::count())->toBe(2)
        ->and(FinancialTransaction::where('type', TransactionType::AFFILIATE_RECEIVABLE_SETTLE)->count())->toBe(2);
    $this->assertDatabaseHas('audit_logs', ['action' => 'affiliate.receivable_settled', 'actor_id' => $actor->id, 'subject_id' => $entry->id]);
    $this->assertDatabaseHas('notification_events', ['event_key' => 'receivable-receipt:'.$entry->id.':settled']);
});

it('keeps fresh affiliate funds blocked until debt is collected and restores payout blocking after a reversal', function (): void {
    [$owner, $root, $actor] = receivableReviewFixture();
    $owner->forceFill(['affiliate_balance' => '20.00'])->save();
    expect(fn () => app(RequestAffiliateWithdrawalAction::class)->execute($owner, '2.00', (string) Str::ulid()))->toThrow(ValidationException::class);
    $entry = app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, receivableReviewInput($root, ['amount' => '10.00']));
    $withdrawal = app(RequestAffiliateWithdrawalAction::class)->execute($owner, '2.00', (string) Str::ulid());
    $reverseInput = receivableReviewReverseInput($root);
    $reverse = app(ReverseAffiliateReceivableSettlementAction::class)->execute($entry, $actor, $reverseInput);
    expect(app(ReverseAffiliateReceivableSettlementAction::class)->execute($entry, $actor, $reverseInput)->id)->toBe($reverse->id)
        ->and(app(AffiliateReceivableBalance::class)->project($root)['remaining'])->toBe('10.00');
    expect(fn () => app(ProcessAffiliateWithdrawalAction::class)->execute($withdrawal, $actor, true, 'Cannot approve while debt reopened'))->toThrow(ValidationException::class);
    expect($owner->fresh()->credit)->toBe('17.00')->and($owner->fresh()->affiliate_balance)->toBe('20.00')
        ->and($withdrawal->fresh()->status)->toBe('pending')->and(AffiliateReceivableReversal::count())->toBe(1);
});

it('corrects a reversed receipt through one immutable unbranched revision chain', function (): void {
    [$owner, $root, $actor] = receivableReviewFixture();
    $input = receivableReviewInput($root, ['external_channel' => ' BANK ', 'external_reference' => ' original-receipt ']);
    $entry = app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, $input);
    $originalLedger = FinancialTransaction::findOrFail($entry->ledger_transaction_id)->getAttributes();
    app(ReverseAffiliateReceivableSettlementAction::class)->execute($entry, $actor, receivableReviewReverseInput($root));
    expect(fn () => app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor,
        receivableReviewInput($root, ['external_reference' => 'original-receipt'])))->toThrow(ValidationException::class);
    $corrected = app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor,
        receivableReviewInput($root, ['amount' => '3.00', 'external_reference' => 'original-receipt', 'corrects_settlement_id' => $entry->id]));
    expect($corrected->corrects_settlement_id)->toBe($entry->id)->and($entry->fresh()->active_receipt_key)->toBeNull()
        ->and($corrected->external_reference)->toBe('ORIGINAL-RECEIPT')->and(app(AffiliateReceivableBalance::class)->project($root)['remaining'])->toBe('7.00')
        ->and(FinancialTransaction::findOrFail($entry->ledger_transaction_id)->getAttributes())->toBe($originalLedger);
    expect(fn () => app(ReverseAffiliateReceivableSettlementAction::class)->execute($entry, $actor, receivableReviewReverseInput($root)))->toThrow(ValidationException::class);
    expect(fn () => app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor,
        receivableReviewInput($root, ['external_reference' => 'ORIGINAL-RECEIPT', 'corrects_settlement_id' => $entry->id])))->toThrow(ValidationException::class);
});

it('rejects stale confirmations changed replay intent and reuse of one external receipt by another owner', function (): void {
    [$owner, $root, $actor] = receivableReviewFixture();
    [, $otherRoot] = receivableReviewFixture();
    $input = receivableReviewInput($root, ['external_reference' => 'ONE-COMPLETED-RECEIPT']);
    app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, $input);
    expect(fn () => app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, array_replace($input, ['amount' => '5.00'])))->toThrow(ValidationException::class);
    expect(fn () => app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, array_replace($input, ['request_id' => (string) Str::ulid(), 'external_reference' => 'NEW-RECEIPT'])))->toThrow(ValidationException::class);
    expect(fn () => app(RecordAffiliateReceivableSettlementAction::class)->execute($otherRoot, $actor,
        receivableReviewInput($otherRoot, ['external_reference' => 'one-completed-receipt'])))->toThrow(ValidationException::class);
    expect(AffiliateReceivableSettlement::count())->toBe(1)->and(app(AffiliateReceivableBalance::class)->project($otherRoot)['remaining'])->toBe('10.00');
});

it('requires independent receivable authority and rejects revoked authority even for an idempotent replay', function (string $role): void {
    [$owner, $root, $actor] = receivableReviewFixture();
    $readonly = User::factory()->create();
    if ($role !== 'customer') {
        app(AssignAdminRoleAction::class)->execute($readonly, $role, 'Read-only financial review');
    }
    expect(fn () => app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $readonly, receivableReviewInput($root)))->toThrow(AuthorizationException::class);
    $input = receivableReviewInput($root);
    app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, $input);
    app(AssignAdminRoleAction::class)->execute($actor, 'finance-receivable', 'Revoke collection authority', revoke: true);
    expect(fn () => app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, $input))->toThrow(AuthorizationException::class);
})->with(['customer', 'finance-viewer', 'finance-operator', 'finance-payout']);

it('rejects invalid input before creating collection evidence', function (array $changes): void {
    [$owner, $root, $actor] = receivableReviewFixture();
    expect(fn () => app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, receivableReviewInput($root, $changes)))->toThrow(ValidationException::class);
    expect(AffiliateReceivableSettlement::count())->toBe(0)->and(app(AffiliateReceivableBalance::class)->project($root)['remaining'])->toBe('10.00');
})->with([
    [['amount' => '0.00']], [['amount' => '-1.00']], [['amount' => '10.01']], [['amount' => '1.001']], [['amount' => 1.5]],
    [['source' => 'wallet']], [['currency' => 'USD']], [['confirm' => '0']], [['reason' => ' ']],
    [['received_at' => '2100-01-01']], [['external_reference' => []]], [['request_id' => []]],
]);

it('keeps malformed original evidence blocked without accepting a settlement', function (string $corruption): void {
    [$owner, $root, $actor] = receivableReviewFixture();
    $meta = $root->meta;
    match ($corruption) {
        'amount' => $meta['receivable_amount'] = 'invalid',
        'currency' => $meta['currency'] = 'USD',
        'commission' => $meta['commission_id'] = 999999,
    };
    DB::table('financial_transactions')->where('id', $root->id)->update(['meta' => json_encode($meta, JSON_THROW_ON_ERROR)]);
    $root = $root->fresh();
    expect(app(AffiliateFunds::class)->hasReceivable($owner->id))->toBeTrue();
    expect(fn () => app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, receivableReviewInput($root)))->toThrow(ValidationException::class);
})->with(['amount', 'currency', 'commission']);

it('rolls back collection rows ledgers outbox and audit together on a write failure', function (string $failedTable): void {
    [$owner, $root, $actor] = receivableReviewFixture();
    $before = ['ledger' => FinancialTransaction::count(), 'audit' => DB::table('audit_logs')->count(), 'events' => DB::table('notification_events')->count()];
    $reject = true;
    DB::connection()->beforeExecuting(function (string $query) use ($failedTable, &$reject): void {
        if ($reject && str_starts_with(strtolower($query), 'insert into') && str_contains($query, $failedTable)) {
            throw new RuntimeException('Isolated collection persistence failure');
        }
    });
    try {
        expect(fn () => app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, receivableReviewInput($root)))->toThrow(RuntimeException::class, 'Isolated collection persistence failure');
    } finally {
        $reject = false;
    }
    expect(AffiliateReceivableSettlement::count())->toBe(0)->and(FinancialTransaction::count())->toBe($before['ledger'])
        ->and(DB::table('audit_logs')->count())->toBe($before['audit'])->and(DB::table('notification_events')->count())->toBe($before['events'])
        ->and(app(AffiliateReceivableBalance::class)->project($root)['remaining'])->toBe('10.00');
})->with(['financial_transactions', 'affiliate_receivable_settlements', 'audit_logs']);

it('rolls back receipt release and reversal evidence when the audit cannot commit', function (): void {
    [$owner, $root, $actor] = receivableReviewFixture();
    $entry = app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, receivableReviewInput($root));
    $receipt = $entry->active_receipt_key;
    $reject = true;
    DB::connection()->beforeExecuting(function (string $query) use (&$reject): void {
        if ($reject && str_starts_with(strtolower($query), 'insert into') && str_contains($query, 'audit_logs')) {
            throw new RuntimeException('Isolated reversal audit failure');
        }
    });
    try {
        expect(fn () => app(ReverseAffiliateReceivableSettlementAction::class)->execute($entry, $actor, receivableReviewReverseInput($root)))->toThrow(RuntimeException::class, 'Isolated reversal audit failure');
    } finally {
        $reject = false;
    }
    expect(AffiliateReceivableReversal::count())->toBe(0)->and($entry->fresh()->active_receipt_key)->toBe($receipt)
        ->and($entry->fresh()->reversed_at)->toBeNull()->and(app(AffiliateReceivableBalance::class)->project($root)['remaining'])->toBe('6.00');
});

it('supports collection against a retained anonymized financial owner without giving a new identity its money', function (): void {
    [$owner, $root, $actor] = receivableReviewFixture();
    $owner->forceFill(['anonymized_at' => now(), 'email' => 'closed-owner@deleted.invalid'])->save();
    $owner->delete();
    $new = User::factory()->create();
    app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, receivableReviewInput($root, ['amount' => '10.00']));
    expect(app(AffiliateFunds::class)->hasReceivable($owner->id))->toBeFalse()->and(User::withTrashed()->findOrFail($owner->id)->trashed())->toBeTrue()
        ->and($new->fresh()->credit)->toBe('0.00');
});

it('exposes the remaining projection on the dedicated page and requires collection authority on both HTTP writes', function (): void {
    [, $root, $actor] = receivableReviewFixture();
    $viewer = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($viewer, 'finance-viewer', 'Read-only receivable review');
    $this->actingAs($viewer)->get('/admin/finance/receivables?clawback='.$root->id)->assertOk()->assertSee('10.00')->assertDontSee('已经核实实际收到上述 CNY');
    $this->post('/admin/finance/receivables/'.$root->id.'/settlements', receivableReviewInput($root))->assertForbidden();
    $this->actingAs($actor)->get('/admin/finance/receivables?clawback='.$root->id)->assertOk()->assertSee('已经核实实际收到上述 CNY');
    $input = receivableReviewInput($root);
    $this->post('/admin/finance/receivables/'.$root->id.'/settlements', $input)->assertRedirect('/admin/finance/receivables?clawback='.$root->id)->assertSessionHasNoErrors();
    $entry = AffiliateReceivableSettlement::sole();
    $this->actingAs($viewer)->post('/admin/finance/receivable-settlements/'.$entry->id.'/reverse', receivableReviewReverseInput($root))->assertForbidden();
    $this->actingAs($actor)->post('/admin/finance/receivable-settlements/'.$entry->id.'/reverse', receivableReviewReverseInput($root))->assertRedirect('/admin/finance/receivables?clawback='.$root->id)->assertSessionHasNoErrors();
    expect(app(AffiliateReceivableBalance::class)->project($root)['remaining'])->toBe('10.00');
});

it('records operations attention when a real reversal reopens debt for a closed owner or unresolved external payout', function (string $case): void {
    [$owner, $root, $actor] = receivableReviewFixture();
    $entry = app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, receivableReviewInput($root, ['amount' => '10.00']));
    if ($case === 'closed_account') {
        $owner->forceFill(['anonymized_at' => now(), 'deleted_at' => now()])->save();
    } else {
        AffiliateWithdrawal::create(['user_id' => $owner->id, 'destination' => 'external', 'amount' => '2.00', 'status' => 'unknown']);
    }
    $reverse = app(ReverseAffiliateReceivableSettlementAction::class)->execute($entry, $actor, receivableReviewReverseInput($root));
    $attention = DB::table('audit_logs')->where('action', 'affiliate.receivable_attention')->where('subject_id', $reverse->id)->sole();
    expect(json_decode($attention->after, true)['status'])->toBe($case)
        ->and(app(AffiliateReceivableBalance::class)->project($root)['remaining'])->toBe('10.00');
    $this->assertDatabaseHas('notification_events', ['scope' => 'operations', 'event_type' => 'affiliate.receivable.attention', 'event_key' => 'receivable-reversal:'.$reverse->id.':attention']);
})->with(['closed_account', 'external_payout']);

it('corrects one reversed receipt across owners only while the prior financial evidence remains valid', function (bool $corruptPriorEvidence): void {
    [$originalOwner, $originalRoot, $actor] = receivableReviewFixture();
    [$newOwner, $newRoot] = receivableReviewFixture();
    $entry = app(RecordAffiliateReceivableSettlementAction::class)->execute($originalRoot, $actor,
        receivableReviewInput($originalRoot, ['external_reference' => 'CROSS-OWNER-CORRECTION']));
    app(ReverseAffiliateReceivableSettlementAction::class)->execute($entry, $actor, receivableReviewReverseInput($originalRoot));
    if ($corruptPriorEvidence) {
        DB::table('financial_transactions')->where('id', $entry->ledger_transaction_id)->update(['meta' => '{}']);
    }
    $input = receivableReviewInput($newRoot, ['external_reference' => 'CROSS-OWNER-CORRECTION', 'corrects_settlement_id' => $entry->id]);
    if ($corruptPriorEvidence) {
        expect(fn () => app(RecordAffiliateReceivableSettlementAction::class)->execute($newRoot, $actor, $input))->toThrow(ValidationException::class);
        expect(app(AffiliateReceivableBalance::class)->project($newRoot)['remaining'])->toBe('10.00');
    } else {
        $corrected = app(RecordAffiliateReceivableSettlementAction::class)->execute($newRoot, $actor, $input);
        expect($corrected->user_id)->toBe($newOwner->id)->and($corrected->corrects_settlement_id)->toBe($entry->id)
            ->and(app(AffiliateReceivableBalance::class)->project($originalRoot)['remaining'])->toBe('10.00')
            ->and(app(AffiliateReceivableBalance::class)->project($newRoot)['remaining'])->toBe('6.00');
    }
    expect($originalOwner->fresh()->credit)->toBe('17.00')->and($newOwner->fresh()->credit)->toBe('17.00');
})->with([false, true]);
