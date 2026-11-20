<?php

declare(strict_types=1);

use App\Actions\CreateComboInvoiceAction;
use App\Actions\HandlePaymentCallbackAction;
use App\Actions\RecordOfflineInvoiceReceiptAction;
use App\Actions\RefundInvoiceAction;
use App\Actions\ResolveProductOptionsAction;
use App\Data\VerifiedPaymentResult;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentCallbackOutcome;
use App\Enums\TransactionType;
use App\Jobs\AccrueAffiliateCommissionJob;
use App\Jobs\ExecuteFulfillmentTaskJob;
use App\Jobs\SendNotificationDeliveryJob;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentCallback;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ProductOptionItem;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Services\NotificationManager;
use App\Support\OfflineInvoiceReceiptRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    $this->offlineReceiptRealQueue = Queue::getFacadeRoot();
    Queue::fake();
    Http::preventStrayRequests();
    $this->travelTo(now()->startOfSecond());
});

function offlineReceiptActor(array $permissions = ['finance.view', 'finance.offline-receipt']): User
{
    $actor = User::factory()->create();
    foreach ($permissions as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $actor;
}

function offlineReceiptFixture(bool $wallet = true): array
{
    $owner = User::factory()->create(['credit' => '3.00', 'frozen_credit' => '7.00', 'wallet_clearance_frozen' => '2.00']);
    $product = Product::factory()->create(['local_prices' => ['onetime' => ['price' => '10.00', 'enabled' => true]]]);
    $invoice = app(CreateComboInvoiceAction::class)->execute($owner, $product, BillingCycle::ONETIME, [], $wallet, 'epay_wechat',
        ['request_id' => (string) Str::ulid()]);
    $actor = offlineReceiptActor();
    $input = ['request_id' => (string) Str::ulid(), 'amount' => $invoice->gateway_amount,
        'external_channel' => 'bank_transfer', 'external_reference' => 'C3-BANK-'.Str::ulid(),
        'received_at' => now()->toIso8601String(), 'confirm' => '1', 'evidence' => 'C3 private receipt evidence'];

    return [$owner, $invoice, $actor, $input];
}

function offlineReceiptAssertNoPosting(Invoice $invoice, int $ledger = 1): void
{
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::UNPAID)->and($invoice->fresh()->paid_at)->toBeNull();
    test()->assertDatabaseCount('offline_invoice_receipts', 0);
    test()->assertDatabaseCount('external_collection_receipts', 0);
    test()->assertDatabaseCount('fulfillment_tasks', 0);
    test()->assertDatabaseCount('financial_transactions', $ledger);
    expect(DB::table('audit_logs')->where('action', 'invoice.offline_receipt_recorded')->count())->toBe(0);
}

it('settles only the original wallet allocation and the exact offline amount with complete independent evidence', function (bool $wallet): void {
    [$owner, $invoice, $actor, $input] = offlineReceiptFixture($wallet);
    $allocation = $invoice->fresh()->getRawOriginal();
    $receipt = app(RecordOfflineInvoiceReceiptAction::class)->execute($invoice, $actor, $input);
    $payment = FinancialTransaction::findOrFail($receipt->payment_transaction_id);
    $settlement = FinancialTransaction::findOrFail($receipt->settlement_transaction_id);
    $task = FulfillmentTask::findOrFail($receipt->fulfillment_task_id);
    $current = $invoice->fresh();

    expect($current->status)->toBe(InvoiceStatus::PAID)->and($current->paid_at)->not->toBeNull()
        ->and($current->total_amount)->toBe('10.00')->and($current->credit_applied)->toBe($wallet ? '3.00' : '0.00')
        ->and($current->gateway_amount)->toBe($wallet ? '7.00' : '10.00')->and($current->gateway)->toBe('epay_wechat')
        ->and($current->gateway_trade_no)->toBeNull()->and($current->line_items)->toBe($invoice->line_items)
        ->and($owner->fresh()->credit)->toBe($wallet ? '0.00' : '3.00')->and($owner->fresh()->frozen_credit)->toBe('7.00')
        ->and($owner->fresh()->wallet_clearance_frozen)->toBe('2.00')
        ->and($receipt->user_id)->toBe($owner->id)->and($receipt->processed_by)->toBe($actor->id)
        ->and($receipt->amount)->toBe($input['amount'])->and($receipt->currency)->toBe('CNY')
        ->and($payment->type)->toBe(TransactionType::PAYMENT)->and($payment->amount)->toBe($input['amount'])
        ->and($payment->reference_key)->toBe('payment:'.$invoice->id)
        ->and($settlement->type)->toBe(TransactionType::CREDIT_SETTLE)->and($settlement->amount)->toBe($wallet ? '3.00' : '0.00')
        ->and($settlement->reference_key)->toBe('settle:'.$invoice->id)
        ->and($task->instance_id)->toBe($current->instance_id)->and($task->invoice_id)->toBe($invoice->id)
        ->and($task->idempotency_key)->toBe($invoice->id.':provision:'.$current->instance_id)
        ->and($receipt->allocation_snapshot)->toBe(app(OfflineInvoiceReceiptRules::class)->allocation($current))
        ->and(DB::table('invoices')->where('id', $invoice->id)->value('line_items'))->toBe($allocation['line_items']);
    $this->assertDatabaseHas('notification_events', ['user_id' => $owner->id, 'event_key' => 'invoice:'.$invoice->id.':paid']);
    $this->assertDatabaseHas('audit_logs', ['actor_id' => $actor->id, 'subject_id' => $invoice->id, 'action' => 'invoice.offline_receipt_recorded']);
    $this->assertDatabaseCount('offline_invoice_receipts', 1);
    $this->assertDatabaseCount('external_collection_receipts', 1);
    $this->assertDatabaseCount('payment_attempts', 0);
    $this->assertDatabaseCount('payment_callbacks', 0);
    $this->assertDatabaseCount('instances', 1);
    $this->assertDatabaseCount('fulfillment_tasks', 1);
    $this->assertDatabaseCount('financial_transactions', $wallet ? 3 : 2);
    expect(DB::table('offline_invoice_receipts')->value('encrypted_evidence'))->not->toContain('C3 private receipt evidence');
    expect(json_encode($receipt->fresh()->toArray()))->not->toContain('C3 private receipt evidence', 'encrypted_evidence');
    expect(DB::table('audit_logs')->where('action', 'invoice.offline_receipt_recorded')->value('after'))
        ->not->toContain('C3 private receipt evidence', $input['external_reference']);
    Http::assertNothingSent();
})->with([true, false]);

it('returns the original immutable receipt on exact replay without rechecking new-business target eligibility', function (): void {
    [$owner, $invoice, $actor, $input] = offlineReceiptFixture();
    $action = app(RecordOfflineInvoiceReceiptAction::class);
    $receipt = $action->execute($invoice, $actor, $input);
    $before = $receipt->fresh()->getRawOriginal();
    $owner->forceFill(['anonymized_at' => now(), 'deleted_at' => now()])->save();
    $again = $action->execute($invoice, $actor, $input);
    expect($again->getRawOriginal())->toBe($before)->and(User::withTrashed()->findOrFail($owner->id)->frozen_credit)->toBe('7.00');
    $this->assertDatabaseCount('offline_invoice_receipts', 1);
    $this->assertDatabaseCount('fulfillment_tasks', 1);
    $this->assertDatabaseCount('financial_transactions', 3);
    expect(DB::table('audit_logs')->where('action', 'invoice.offline_receipt_recorded')->count())->toBe(1);
});

it('rejects a reused request with changed intent or private evidence', function (string $field): void {
    [$owner, $invoice, $actor, $input] = offlineReceiptFixture();
    $action = app(RecordOfflineInvoiceReceiptAction::class);
    $receipt = $action->execute($invoice, $actor, $input);
    $changes = match ($field) {
        'amount' => ['amount' => '7.01'], 'currency' => ['currency' => 'USD'],
        'channel' => ['external_channel' => 'cash'], 'reference' => ['external_reference' => 'DIFFERENT-C3-RECEIPT'],
        'time' => ['received_at' => now()->subMinute()->toIso8601String()], 'reason' => ['reason' => 'different note'],
        'evidence' => ['evidence' => 'different private evidence'], default => [],
    };
    $changedActor = $field === 'actor' ? offlineReceiptActor() : $actor;
    expect(fn () => $action->execute($invoice, $changedActor, array_replace($input, $changes)))->toThrow(ValidationException::class);
    expect($receipt->fresh()->amount)->toBe('7.00')->and($owner->fresh()->frozen_credit)->toBe('7.00');
    $this->assertDatabaseCount('offline_invoice_receipts', 1);
    $this->assertDatabaseCount('financial_transactions', 3);
})->with(['amount', 'currency', 'channel', 'reference', 'time', 'reason', 'evidence', 'actor']);

it('refuses the same external receipt on a second owner and rolls the whole attempted settlement back', function (): void {
    [$firstOwner, $first, $actor, $input] = offlineReceiptFixture();
    [$secondOwner, $second, $otherActor, $secondInput] = offlineReceiptFixture();
    app(RecordOfflineInvoiceReceiptAction::class)->execute($first, $actor, $input);
    $secondInput['external_reference'] = strtolower($input['external_reference']);
    expect(fn () => app(RecordOfflineInvoiceReceiptAction::class)->execute($second, $otherActor, $secondInput))->toThrow(ValidationException::class);
    expect($second->fresh()->status)->toBe(InvoiceStatus::UNPAID)->and($secondOwner->fresh()->frozen_credit)->toBe('10.00')
        ->and($firstOwner->fresh()->frozen_credit)->toBe('7.00');
    $this->assertDatabaseCount('offline_invoice_receipts', 1);
    $this->assertDatabaseCount('external_collection_receipts', 1);
    $this->assertDatabaseCount('instances', 1);
    $this->assertDatabaseCount('fulfillment_tasks', 1);
    $this->assertDatabaseCount('financial_transactions', 4);
});

it('does not let a new request settle the same invoice twice or reuse another owners request', function (string $collision): void {
    [$owner, $invoice, $actor, $input] = offlineReceiptFixture();
    $action = app(RecordOfflineInvoiceReceiptAction::class);
    $original = $action->execute($invoice, $actor, $input);
    if ($collision === 'same invoice') {
        $input['request_id'] = (string) Str::ulid();
        $target = $invoice;
    } else {
        [$otherOwner, $target, $actor, $otherInput] = offlineReceiptFixture();
        $input = array_replace($otherInput, ['request_id' => $input['request_id']]);
    }
    expect(fn () => $action->execute($target, $actor, $input))->toThrow(ValidationException::class);
    expect($original->fresh()->amount)->toBe('7.00')->and($owner->fresh()->frozen_credit)->toBe('7.00');
    if (isset($otherOwner)) {
        expect($otherOwner->fresh()->frozen_credit)->toBe('10.00')->and($target->fresh()->status)->toBe(InvoiceStatus::UNPAID);
    }
    $this->assertDatabaseCount('offline_invoice_receipts', 1);
    $this->assertDatabaseCount('fulfillment_tasks', 1);
})->with(['same invoice', 'other owner']);

it('normalizes the stable request receipt time and default note without changing secret evidence', function (): void {
    [$owner, $invoice, $actor, $input] = offlineReceiptFixture();
    $input['request_id'] = ' '.strtolower($input['request_id']).' ';
    $input['external_reference'] = ' '.strtolower($input['external_reference']).' ';
    $input['reason'] = ' ';
    $input['evidence'] = '';
    $action = app(RecordOfflineInvoiceReceiptAction::class);
    $receipt = $action->execute($invoice, $actor, $input);
    unset($input['reason'], $input['evidence']);
    $input['received_at'] = now()->utc()->toIso8601String();
    $again = $action->execute($invoice, $actor, $input);
    expect($again->id)->toBe($receipt->id)->and($receipt->encrypted_evidence)->toBeNull()
        ->and($receipt->reason)->toBe('登记已实际收到的线下账单款项')
        ->and($receipt->request_id)->toBe(strtoupper(trim($input['request_id'])))
        ->and($receipt->external_reference)->toBe(strtoupper(trim($input['external_reference'])))
        ->and($owner->fresh()->frozen_credit)->toBe('7.00');
    $this->assertDatabaseCount('financial_transactions', 3);
});

it('validates actual receipt evidence before posting any money', function (string $invalid): void {
    [$owner, $invoice, $actor, $input] = offlineReceiptFixture();
    match ($invalid) {
        'bad request' => $input['request_id'] = 'not-a-server-request',
        'rounded amount' => $input['amount'] = '7.001',
        'float amount' => $input['amount'] = 7.0,
        'negative amount' => $input['amount'] = '-7.00',
        'exponent amount' => $input['amount'] = '7e0',
        'gateway channel' => $input['external_channel'] = 'epay_wechat',
        'missing receipt' => $input['external_reference'] = '',
        'malformed receipt' => $input['external_reference'] = 'bank receipt with spaces',
        'future receipt' => $input['received_at'] = now()->addSecond()->toIso8601String(),
        'missing time' => $input['received_at'] = null,
        'unconfirmed' => $input['confirm'] = false,
        'long reason' => $input['reason'] = str_repeat('a', 256),
        'long evidence' => $input['evidence'] = str_repeat('b', 2001),
    };
    expect(fn () => app(RecordOfflineInvoiceReceiptAction::class)->execute($invoice, $actor, $input))->toThrow(ValidationException::class);
    offlineReceiptAssertNoPosting($invoice);
    expect($owner->fresh()->credit)->toBe('0.00')->and($owner->fresh()->frozen_credit)->toBe('10.00');
})->with(['bad request', 'rounded amount', 'float amount', 'negative amount', 'exponent amount', 'gateway channel',
    'missing receipt', 'malformed receipt', 'future receipt', 'missing time', 'unconfirmed', 'long reason', 'long evidence']);

it('refuses any existing payment attempt or callback even when no gateway payment was posted', function (string $source): void {
    [$owner, $invoice, $actor, $input] = offlineReceiptFixture();
    if ($source === 'callback') {
        PaymentCallback::factory()->create(['invoice_id' => $invoice->id, 'invoice_no' => $invoice->invoice_no,
            'gateway' => $invoice->gateway, 'outcome' => PaymentCallbackOutcome::REJECTED_SHORT_AMOUNT]);
    } else {
        PaymentAttempt::create(['invoice_id' => $invoice->id, 'gateway' => $invoice->gateway,
            'out_trade_no' => 'PAY'.Str::ulid(), 'amount' => $invoice->gateway_amount, 'status' => $source]);
    }
    expect(fn () => app(RecordOfflineInvoiceReceiptAction::class)->execute($invoice, $actor, $input))->toThrow(ValidationException::class);
    offlineReceiptAssertNoPosting($invoice);
    expect($owner->fresh()->frozen_credit)->toBe('10.00');
})->with(['created', 'failed', 'expired', 'callback']);

it('requires an active authorized finance operator and an ordinary original owner', function (string $invalid): void {
    [$owner, $invoice, $actor, $input] = offlineReceiptFixture();
    match ($invalid) {
        'view only' => $actor->revokePermissionTo('finance.offline-receipt'),
        'write only' => $actor->revokePermissionTo('finance.view'),
        'disabled actor' => $actor->forceFill(['disabled_at' => now()])->save(),
        'self' => $invoice->update(['user_id' => $actor->id]),
        'backend target' => $owner->givePermissionTo(Permission::findOrCreate('customers.view', 'web')),
        'closed target' => $owner->forceFill(['anonymized_at' => now(), 'deleted_at' => now()])->save(),
    };
    expect(fn () => app(RecordOfflineInvoiceReceiptAction::class)->execute($invoice, $actor, $input))
        ->toThrow(in_array($invalid, ['view only', 'write only'], true) ? AuthorizationException::class : HttpException::class);
    offlineReceiptAssertNoPosting($invoice);
})->with(['view only', 'write only', 'disabled actor', 'self', 'backend target', 'closed target']);

it('accepts an original unpaid obligation for a disabled ordinary customer without restoring access', function (): void {
    [$owner, $invoice, $actor, $input] = offlineReceiptFixture();
    $owner->forceFill(['disabled_at' => now(), 'auth_revision' => 2])->save();
    $receipt = app(RecordOfflineInvoiceReceiptAction::class)->execute($invoice, $actor, $input);
    expect($receipt->user_id)->toBe($owner->id)->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($owner->fresh()->isAccessEnabled())->toBeFalse()->and($owner->fresh()->auth_revision)->toBe(2);
});

it('rejects ineligible invoice states types selection or a changed exact allocation', function (string $invalid): void {
    [$owner, $invoice, $actor, $input] = offlineReceiptFixture();
    $items = $invoice->line_items;
    match ($invalid) {
        'paid' => $invoice->update(['status' => InvoiceStatus::PAID]),
        'expired' => $invoice->update(['status' => InvoiceStatus::EXPIRED]),
        'cancelled' => $invoice->update(['status' => InvoiceStatus::CANCELLED]),
        'refunded' => $invoice->update(['status' => InvoiceStatus::REFUNDED]),
        'overdue' => $invoice->update(['expires_at' => now()->subSecond()]),
        'topup' => $invoice->update(['type' => InvoiceType::WALLET_TOPUP]),
        'downgrade' => $invoice->update(['type' => InvoiceType::DOWNGRADE]),
        'non-CNY' => $invoice->update(['currency' => 'USD']),
        'zero gateway' => $invoice->update(['gateway_amount' => '0.00']),
        'bad identity' => $invoice->update(['total_amount' => '11.00']),
        'amount mismatch' => $input['amount'] = '6.00',
        'selection' => $items['automatic_renewal'] = ['selection_pending' => true],
        'missing purchase' => $items = [],
    };
    if (in_array($invalid, ['selection', 'missing purchase'], true)) {
        $invoice->update(['line_items' => $items]);
    }
    $before = $invoice->fresh()->getRawOriginal();
    expect(fn () => app(RecordOfflineInvoiceReceiptAction::class)->execute($invoice, $actor, $input))->toThrow(ValidationException::class);
    expect($invoice->fresh()->getRawOriginal())->toBe($before)->and($owner->fresh()->frozen_credit)->toBe('10.00');
    $this->assertDatabaseCount('offline_invoice_receipts', 0);
    $this->assertDatabaseCount('fulfillment_tasks', 0);
})->with(['paid', 'expired', 'cancelled', 'refunded', 'overdue', 'topup', 'downgrade', 'non-CNY', 'zero gateway',
    'bad identity', 'amount mismatch', 'selection', 'missing purchase']);

it('requires the original matching freeze and rejects previously consumed or released allocations', function (string $invalid): void {
    [$owner, $invoice, $actor, $input] = offlineReceiptFixture();
    $freeze = FinancialTransaction::sole();
    match ($invalid) {
        'missing' => DB::table('financial_transactions')->where('id', $freeze->id)->delete(),
        'wrong owner' => DB::table('financial_transactions')->where('id', $freeze->id)->update(['user_id' => $actor->id]),
        'wrong type' => DB::table('financial_transactions')->where('id', $freeze->id)->update(['type' => TransactionType::CREDIT_IN->value]),
        'wrong amount' => DB::table('financial_transactions')->where('id', $freeze->id)->update(['amount' => '2.00']),
        'no balance' => $owner->forceFill(['frozen_credit' => '2.00'])->save(),
        default => FinancialTransaction::create(['user_id' => $owner->id, 'invoice_id' => $invoice->id,
            'type' => TransactionType::CREDIT_SETTLE, 'amount' => '3.00', 'reference_key' => $invalid.':'.$invoice->id]),
    };
    $before = FinancialTransaction::count();
    expect(fn () => app(RecordOfflineInvoiceReceiptAction::class)->execute($invoice, $actor, $input))->toThrow(ValidationException::class);
    offlineReceiptAssertNoPosting($invoice, $before);
})->with(['missing', 'wrong owner', 'wrong type', 'wrong amount', 'no balance', 'settle', 'payment', 'unfreeze', 'refund']);

it('rolls back funds receipt registry instance task and notification when a mandatory write fails', function (string $failure): void {
    [$owner, $invoice, $actor, $input] = offlineReceiptFixture();
    // Exercise the actual after-commit queue rather than QueueFake, which records deferred jobs eagerly.
    Queue::swap($this->offlineReceiptRealQueue);
    config(['queue.default' => 'database']);
    if ($failure === 'notification') {
        $notifications = Mockery::mock(NotificationManager::class);
        $notifications->shouldReceive('queue')->once()->andThrow(new RuntimeException('C3 notification write failed'));
        $this->app->instance(NotificationManager::class, $notifications);
    } else {
        DB::unprepared("CREATE TRIGGER c3_offline_audit_failure BEFORE INSERT ON audit_logs WHEN NEW.action = 'invoice.offline_receipt_recorded' BEGIN SELECT RAISE(ABORT, 'C3 audit failed'); END");
    }
    expect(fn () => app(RecordOfflineInvoiceReceiptAction::class)->execute($invoice, $actor, $input))
        ->toThrow($failure === 'notification' ? RuntimeException::class : QueryException::class);
    offlineReceiptAssertNoPosting($invoice);
    $this->assertDatabaseCount('instances', 0);
    $this->assertDatabaseCount('notification_events', 0);
    expect($owner->fresh()->credit)->toBe('0.00')->and($owner->fresh()->frozen_credit)->toBe('10.00');
    $this->assertDatabaseCount('jobs', 0);
})->with(['notification', 'audit']);

it('retries a concurrency failure with one committed receipt audit notification and after-commit dispatch', function (): void {
    // RefreshDatabase owns an outer transaction, which intentionally prevents nested deadlock retries.
    // A separate in-memory connection exercises the real top-level transaction and queue commit.
    $original = DB::getDefaultConnection();
    config(['database.connections.offline_receipt_retry' => [...config('database.connections.sqlite'), 'database' => ':memory:']]);
    DB::setDefaultConnection('offline_receipt_retry');
    try {
        Artisan::call('migrate', ['--database' => 'offline_receipt_retry', '--force' => true]);
        [$owner, $invoice, $actor, $input] = offlineReceiptFixture();
        Queue::swap($this->offlineReceiptRealQueue);
        config(['queue.default' => 'database', 'queue.connections.database.connection' => 'offline_receipt_retry']);
        $calls = 0;
        $notifications = Mockery::mock(NotificationManager::class);
        $notifications->shouldReceive('queue')->twice()->andReturnUsing(function (...$arguments) use (&$calls): void {
            if (++$calls === 1) {
                throw new PDOException('Deadlock found when trying to get lock', 40001);
            }
            (new NotificationManager)->queue(...$arguments);
        });
        $this->app->instance(NotificationManager::class, $notifications);
        $action = app(RecordOfflineInvoiceReceiptAction::class);
        $receipt = $action->execute($invoice, $actor, $input);
        $again = $action->execute($invoice, $actor, $input);
        expect($calls)->toBe(2)->and($again->id)->toBe($receipt->id)
            ->and($owner->fresh()->credit)->toBe('0.00')->and($owner->fresh()->frozen_credit)->toBe('7.00')
            ->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)
            ->and(DB::table('audit_logs')->where('action', 'invoice.offline_receipt_recorded')->count())->toBe(1);
        foreach (['offline_invoice_receipts', 'external_collection_receipts', 'instances', 'fulfillment_tasks', 'notification_events', 'notification_deliveries'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $this->assertDatabaseCount('financial_transactions', 3);
        foreach ([ExecuteFulfillmentTaskJob::class, AccrueAffiliateCommissionJob::class, SendNotificationDeliveryJob::class] as $job) {
            expect(DB::table('jobs')->get()->filter(fn ($row): bool => json_decode($row->payload, true)['displayName'] === $job))->toHaveCount(1);
        }
        $this->assertDatabaseCount('jobs', 3);
        Http::assertNothingSent();
    } finally {
        DB::purge('offline_receipt_retry');
        DB::setDefaultConnection($original);
    }
});

it('keeps a real late gateway callback independent and refunds only the original full invoice value', function (string $mode): void {
    [$owner, $invoice, $actor, $input] = offlineReceiptFixture();
    $receipt = app(RecordOfflineInvoiceReceiptAction::class)->execute($invoice, $actor, $input);
    $callback = new VerifiedPaymentResult('epay_wechat', 'C3-REAL-LATE-TRADE', $invoice->invoice_no, '7.00');
    app(HandlePaymentCallbackAction::class)->execute($callback);
    app(HandlePaymentCallbackAction::class)->execute($callback);
    expect(PaymentCallback::sole()->outcome)->toBe(PaymentCallbackOutcome::PROCESSED_OVERPAYMENT)
        ->and($owner->fresh()->credit)->toBe('7.00')->and($owner->fresh()->frozen_credit)->toBe('7.00');
    $invoice->fresh()->instance()->firstOrFail()->update(['status' => 'Cancelled']);
    $actor->givePermissionTo(Permission::findOrCreate('finance.refund', 'web'));
    $refund = app(RefundInvoiceAction::class)->execute($invoice, $mode, reason: 'C3 original full invoice refund', actor: $actor);
    $again = app(RefundInvoiceAction::class)->execute($invoice, $mode, reason: 'C3 repeated refund', actor: $actor);
    expect($again->id)->toBe($refund->id)->and($refund->amount)->toBe('10.00')
        ->and($refund->type)->toBe($mode === 'refund_wallet' ? TransactionType::CREDIT_IN : TransactionType::EXPENSE)
        ->and($refund->meta['offline_receipt_id'])->toBe($receipt->id)
        ->and($refund->meta['source_payment_transaction_id'])->toBe($receipt->payment_transaction_id)
        ->and($refund->meta['source_settlement_transaction_id'])->toBe($receipt->settlement_transaction_id)
        ->and($owner->fresh()->credit)->toBe($mode === 'refund_wallet' ? '17.00' : '7.00')
        ->and($owner->fresh()->frozen_credit)->toBe('7.00')->and($invoice->fresh()->status)->toBe(InvoiceStatus::REFUNDED);
    $this->assertDatabaseCount('fulfillment_tasks', 1);
    $this->assertDatabaseCount('payment_callbacks', 1);
    $this->assertDatabaseCount('financial_transactions', 5);
    app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult('epay_wechat', 'C3-NEW-TRADE-AFTER-REFUND', $invoice->invoice_no, '7.00'));
    expect(PaymentCallback::where('gateway_trade_no', 'C3-NEW-TRADE-AFTER-REFUND')->sole()->outcome)->toBe(PaymentCallbackOutcome::REJECTED_INVALID_STATE)
        ->and($owner->fresh()->credit)->toBe($mode === 'refund_wallet' ? '17.00' : '7.00');
    $this->assertDatabaseCount('financial_transactions', 5);
    Http::assertNothingSent();
})->with(['refund_wallet', 'external_record']);

it('rejects refund if the independent offline settlement source was damaged', function (string $damage): void {
    [$owner, $invoice, $actor, $input] = offlineReceiptFixture();
    $receipt = app(RecordOfflineInvoiceReceiptAction::class)->execute($invoice, $actor, $input);
    $actor->givePermissionTo(Permission::findOrCreate('finance.refund', 'web'));
    $invoice->fresh()->instance()->firstOrFail()->update(['status' => 'Cancelled']);
    match ($damage) {
        'snapshot' => DB::table('offline_invoice_receipts')->where('id', $receipt->id)->update(['allocation_snapshot' => '{}']),
        'payment' => DB::table('financial_transactions')->where('id', $receipt->payment_transaction_id)->update(['amount' => '8.00']),
        'settle' => DB::table('financial_transactions')->where('id', $receipt->settlement_transaction_id)->update(['amount' => '2.00']),
        'missing receipt' => DB::table('offline_invoice_receipts')->where('id', $receipt->id)->update(['invoice_id' => Invoice::factory()->create()->id]),
        'missing claim' => DB::table('external_collection_receipts')->where('offline_invoice_receipt_id', $receipt->id)->delete(),
        'released claim' => DB::table('external_collection_receipts')->where('offline_invoice_receipt_id', $receipt->id)->update(['active_receipt_key' => null, 'released_at' => now()]),
        'wrong claim' => DB::table('external_collection_receipts')->where('offline_invoice_receipt_id', $receipt->id)->update(['registered_by' => $owner->id]),
    };
    expect(fn () => app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', reason: 'C3 damaged source', actor: $actor))->toThrow(DomainException::class);
    expect($owner->fresh()->credit)->toBe('0.00')->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID);
    $this->assertDatabaseMissing('financial_transactions', ['reference_key' => 'refund:'.$invoice->id]);
})->with(['snapshot', 'payment', 'settle', 'missing receipt', 'missing claim', 'released claim', 'wrong claim']);

it('uses the original service fulfillment snapshot without making supplier requests at offline settlement', function (string $type): void {
    $owner = User::factory()->create(['credit' => '3.00']);
    $account = UpstreamApi::factory()->create();
    $product = Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id,
        'markup_percent' => '0.00', 'provisioning_rules' => ['classic_catalog' => []]]);
    $instance = Instance::factory()->active()->create(['user_id' => $owner->id, 'product_id' => $product->id,
        'driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id, 'upstream_instance_id' => '99',
        'next_due_at' => now()->addDay(), 'active_config' => []]);
    if ($type === 'upgrade') {
        $group = ProductOptionGroup::create(['product_id' => $product->id, 'upstream_group_id' => 50,
            'name' => 'CPU', 'type' => 'select', 'sync_source' => 'upstream', 'is_upgradeable' => true,
            'upstream_meta' => ['option_type' => 1]]);
        foreach ([501 => '1.00', 502 => '2.00'] as $id => $price) {
            ProductOptionItem::create(['option_group_id' => $group->id, 'upstream_sub_id' => $id, 'label' => 'CPU '.$id,
                'upstream_base_prices' => ['monthly' => ['price' => $price, 'enabled' => true]],
                'local_prices' => ['monthly' => ['price' => $price, 'enabled' => true]]]);
        }
        $current = app(ResolveProductOptionsAction::class)->execute($product, 'monthly', ['configoption' => [50 => 501]]);
        $instance->update(['active_config' => ['options' => $current['pairs'], 'upstream_configoption' => [50 => 501]]]);
    }
    $activeConfig = $instance->active_config;
    $state = (object) ['calls' => 0];
    Http::fake(function ($request) use ($instance, $state) {
        $state->calls++;

        return Http::response(match (parse_url($request->url(), PHP_URL_PATH)) {
            '/zjmf_api_login' => ['status' => 200, 'jwt' => 'c3-isolated-renewal'],
            '/host/header' => ['status' => 200, 'data' => ['host_data' => ['domainstatus' => 'Active', 'nextduedate' => $instance->next_due_at->toIso8601String()]]],
            '/host/renewpage' => ['status' => 200, 'data' => ['host' => ['amount' => '10.00', 'billingcycle' => 'monthly'], 'currency' => ['code' => 'CNY']]],
            '/upgrade/upgrade_config_post' => ['status' => 200],
            '/upgrade/upgrade_config_page' => ['status' => 200, 'data' => ['total' => '10.00', 'currency' => ['code' => 'CNY']]],
            default => throw new LogicException('Unexpected C3 renewal request'),
        });
    });
    $invoice = $type === 'renew'
        ? app(CreateComboInvoiceAction::class)->renewal($owner, $instance, true)
        : app(CreateComboInvoiceAction::class)->upgrade($owner, $instance, ['configoption' => [50 => 502]], (string) Str::ulid(), true);
    $calls = $state->calls;
    $operation = $invoice->line_items['encrypted_operation'];
    $receipt = app(RecordOfflineInvoiceReceiptAction::class)->execute($invoice, offlineReceiptActor(),
        ['request_id' => (string) Str::ulid(), 'amount' => '7.00', 'external_channel' => 'cash',
            'external_reference' => 'C3-CASH-RENEWAL', 'received_at' => now()->toIso8601String(), 'confirm' => true]);
    $task = FulfillmentTask::findOrFail($receipt->fulfillment_task_id);
    expect($state->calls)->toBe($calls)->and($task->action)->toBe($type)->and($task->instance_id)->toBe($instance->id)
        ->and($task->payload['encrypted_operation'])->toBe($operation)->and($instance->fresh()->next_due_at->eq($instance->next_due_at))->toBeTrue()
        ->and($instance->fresh()->active_config)->toBe($activeConfig)
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)->and($owner->fresh()->frozen_credit)->toBe('0.00');
    $this->assertDatabaseCount('instances', 1);
    $this->assertDatabaseCount('fulfillment_tasks', 1);
})->with(['renew', 'upgrade']);
