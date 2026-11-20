<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\RecordAffiliateReceivableSettlementAction;
use App\Actions\RefundInvoiceAction;
use App\Actions\ReverseAffiliateReceivableSettlementAction;
use App\Enums\TransactionType;
use App\Models\AffiliateCommission;
use App\Models\ExternalCollectionReceipt;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Invoice;
use App\Models\OfflineInvoiceReceipt;
use App\Models\User;
use App\Services\AffiliateReceivableBalance;
use App\Support\ExternalCollectionReceipts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
    Queue::fake();
});

function collectionReceivable(): array
{
    $actor = User::factory()->create();
    foreach (['finance-operator', 'finance-receivable'] as $role) {
        app(AssignAdminRoleAction::class)->execute($actor, $role, 'Receipt test');
    }
    $owner = User::factory()->create(['affiliate_balance' => '0.00']);
    $invoice = Invoice::factory()->paid()->create(['total_amount' => '100.00', 'gateway_amount' => '100.00']);
    AffiliateCommission::factory()->create(['referrer_id' => $owner->id, 'buyer_id' => $invoice->user_id,
        'invoice_id' => $invoice->id, 'status' => 'available', 'amount' => '10.00']);
    app(RefundInvoiceAction::class)->execute($invoice, 'external_record', keepService: true, reason: 'Completed refund', actor: $actor);
    $root = FinancialTransaction::where('user_id', $owner->id)->where('type', TransactionType::AFFILIATE_CLAWBACK)->sole();

    return [$owner, $actor, $root];
}

function collectionInput(FinancialTransaction $root, string $reference): array
{
    return ['amount' => '4.00', 'source' => 'external_received', 'request_id' => (string) Str::ulid(),
        'external_channel' => 'bank_transfer', 'external_reference' => $reference, 'received_at' => now()->subMinute()->toDateTimeString(),
        'reason' => 'Verified receipt', 'confirm' => '1', 'version' => app(AffiliateReceivableBalance::class)->project($root)['version']];
}

/** Synthetic source used only to test registry exclusivity; real posting is tested separately. */
function collectionOffline(User $actor, string $reference): OfflineInvoiceReceipt
{
    $invoice = Invoice::factory()->paid()->create();
    $task = FulfillmentTask::factory()->create(['invoice_id' => $invoice->id, 'user_id' => $invoice->user_id]);
    $ledger = fn (string $kind): FinancialTransaction => FinancialTransaction::create(['user_id' => $invoice->user_id,
        'invoice_id' => $invoice->id, 'type' => $kind, 'amount' => '4.00', 'reference_key' => $kind.':collection:'.Str::ulid()]);

    return OfflineInvoiceReceipt::create(['request_id' => (string) Str::ulid(), 'invoice_id' => $invoice->id,
        'user_id' => $invoice->user_id, 'processed_by' => $actor->id, 'amount' => '4.00', 'currency' => 'CNY',
        'external_channel' => 'bank_transfer', 'external_reference' => $reference, 'received_at' => now()->subMinute(),
        'reason' => 'Test receipt', 'intent' => ['version' => 1], 'allocation_snapshot' => [],
        'payment_transaction_id' => $ledger('payment')->id, 'settlement_transaction_id' => $ledger('credit_settle')->id,
        'fulfillment_task_id' => $task->id, 'encrypted_evidence' => 'PRIVATE-RECEIPT-PROOF']);
}

it('claims one incoming payment across invoice and receivable workflows in either ordering', function (bool $invoiceFirst): void {
    [$owner, $actor, $root] = collectionReceivable();
    $reference = 'BANK-COLLECTION-'.Str::ulid();
    $offline = collectionOffline($actor, $reference);
    if ($invoiceFirst) {
        DB::transaction(fn () => app(ExternalCollectionReceipts::class)->claimOffline($offline));
        expect(fn () => app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, collectionInput($root, $reference)))
            ->toThrow(ValidationException::class);
        $this->assertDatabaseCount('affiliate_receivable_settlements', 0);
    } else {
        app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, collectionInput($root, $reference));
        expect(fn () => DB::transaction(fn () => app(ExternalCollectionReceipts::class)->claimOffline($offline)))
            ->toThrow(ValidationException::class);
    }
    $this->assertDatabaseCount('external_collection_receipts', 1);
})->with([true, false]);

it('retains reversed collection evidence and only permits its original receivable correction', function (): void {
    [$owner, $actor, $root] = collectionReceivable();
    $reference = 'BANK-REVERSED-'.Str::ulid();
    $entry = app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, collectionInput($root, $reference));
    $reverse = app(ReverseAffiliateReceivableSettlementAction::class)->execute($entry, $actor,
        ['request_id' => (string) Str::ulid(), 'reason' => 'Correct wrong allocation', 'correction_reference' => 'CORRECTION',
            'confirm' => '1', 'version' => app(AffiliateReceivableBalance::class)->project($root)['version']]);
    $claim = ExternalCollectionReceipt::sole();
    expect($claim->released_at)->not->toBeNull()->and($claim->active_receipt_key)->toBeNull();
    $offline = collectionOffline($actor, $reference);
    expect(fn () => DB::transaction(fn () => app(ExternalCollectionReceipts::class)->claimOffline($offline)))->toThrow(ValidationException::class);
    $next = app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor,
        collectionInput($root, $reference) + ['corrects_settlement_id' => $entry->id]);
    expect($next->corrects_settlement_id)->toBe($entry->id)
        ->and(ExternalCollectionReceipt::whereNotNull('active_receipt_key')->sole()->receivable_settlement_id)->toBe($next->id)
        ->and(ExternalCollectionReceipt::count())->toBe(2)->and($reverse->settlement_id)->toBe($entry->id);
});

it('backfills real legacy claims without changing existing financial rows and remains idempotent', function (): void {
    [$owner, $actor, $root] = collectionReceivable();
    $entry = app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, collectionInput($root, 'LEGACY-COLLECTION'));
    $ledger = DB::table('financial_transactions')->orderBy('id')->get()->toJson();
    $original = $entry->fresh()->getAttributes();
    $migration = require database_path('migrations/2026_11_20_000020_add_manual_financial_receipts.php');
    $migration->down();
    $migration->up();
    $migration->up();
    expect(ExternalCollectionReceipt::sole()->receivable_settlement_id)->toBe($entry->id)
        ->and($entry->fresh()->getAttributes())->toBe($original)
        ->and(DB::table('financial_transactions')->orderBy('id')->get()->toJson())->toBe($ledger);
});

it('refuses inconsistent legacy receipt evidence before creating new schema', function (): void {
    [$owner, $actor, $root] = collectionReceivable();
    $entry = app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, collectionInput($root, 'INVALID-LEGACY'));
    $migration = require database_path('migrations/2026_11_20_000020_add_manual_financial_receipts.php');
    $migration->down();
    DB::table('affiliate_receivable_settlements')->where('id', $entry->id)->update(['active_receipt_key' => str_repeat('a', 64)]);
    expect(fn () => $migration->up())->toThrow(ValidationException::class);
    expect(Schema::hasTable('offline_invoice_receipts'))->toBeFalse()
        ->and(Schema::hasTable('external_collection_receipts'))->toBeFalse();
});

it('keeps receipt secrets hidden and forbids editing or deleting immutable sources', function (): void {
    $actor = User::factory()->create();
    $receipt = collectionOffline($actor, 'IMMUTABLE');
    DB::transaction(fn () => app(ExternalCollectionReceipts::class)->claimOffline($receipt));
    expect(json_encode($receipt))->not->toContain('PRIVATE-RECEIPT-PROOF', 'encrypted_evidence', 'intent')
        ->and(DB::table('offline_invoice_receipts')->where('id', $receipt->id)->value('encrypted_evidence'))->not->toContain('PRIVATE-RECEIPT-PROOF');
    expect(fn () => $receipt->update(['amount' => '2.00']))->toThrow(LogicException::class);
    expect(fn () => $receipt->delete())->toThrow(LogicException::class);
    expect(fn () => ExternalCollectionReceipt::sole()->update(['released_at' => now(), 'active_receipt_key' => null]))->toThrow(LogicException::class);
    expect(fn () => ExternalCollectionReceipt::sole()->delete())->toThrow(LogicException::class);
});
