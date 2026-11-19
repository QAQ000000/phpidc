<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\RecordAffiliateReceivableSettlementAction;
use App\Actions\RefundInvoiceAction;
use App\Actions\RequestExternalAffiliateWithdrawalAction;
use App\Actions\RequestWalletClearanceAction;
use App\Actions\ReverseAffiliateReceivableSettlementAction;
use App\Enums\InstanceStatus;
use App\Enums\TransactionType;
use App\Models\AffiliateCommission;
use App\Models\AffiliateReceivableSettlement;
use App\Models\FinancialTransaction;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\ReconciliationCase;
use App\Models\ReconciliationEntry;
use App\Models\User;
use App\Services\AffiliateReceivableBalance;
use App\Support\AdminRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
    Queue::fake();
});

function financialUsabilityActor(array $roles): User
{
    $actor = User::factory()->create();
    foreach ($roles as $role) {
        app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated financial usability acceptance');
    }

    return $actor;
}

function financialUsabilityPayout(string $kind): array
{
    $owner = User::factory()->create(['credit' => '25.00', 'affiliate_balance' => '30.00']);
    $actor = financialUsabilityActor($kind === 'external'
        ? ['external-payout-reviewer', 'external-payout-operator', 'external-payout-confirmer']
        : ['wallet-clearance-reviewer', 'wallet-clearance-operator', 'wallet-clearance-confirmer']);
    $input = ['request_id' => (string) Str::ulid(), 'amount' => '10.00', 'payout_method' => 'alipay',
        'payee_name' => 'Isolated payee', 'payee_account' => 'PRIVATE-USABILITY-ACCOUNT', 'current_password' => 'password', 'confirm' => '1'];
    $record = $kind === 'external'
        ? app(RequestExternalAffiliateWithdrawalAction::class)->execute($owner, $input)
        : app(RequestWalletClearanceAction::class)->execute($owner, $input);
    $path = ($kind === 'external' ? '/admin/external-payouts/' : '/admin/wallet-clearances/').$record->id;

    return [$owner, $actor, $record, $path];
}

function financialUsabilityForm(string $html, string $path): string
{
    expect(preg_match('~<form\b[^>]*action="'.preg_quote($path, '~').'"[^>]*>.*?</form>~s', $html, $matches))->toBe(1);

    return $matches[0];
}

function financialUsabilityReceivable(): array
{
    $actor = financialUsabilityActor(['finance-operator', 'finance-receivable']);
    $owner = User::factory()->create(['credit' => '17.00', 'affiliate_balance' => '0.00']);
    $invoice = Invoice::factory()->paid()->create(['total_amount' => '100.00', 'gateway_amount' => '100.00']);
    AffiliateCommission::factory()->create(['referrer_id' => $owner->id, 'buyer_id' => $invoice->user_id,
        'invoice_id' => $invoice->id, 'status' => 'available', 'amount' => '10.00']);
    app(RefundInvoiceAction::class)->execute($invoice, 'external_record', true, 'Verified fixture refund', $actor);
    $root = FinancialTransaction::where('user_id', $owner->id)->where('type', TransactionType::AFFILIATE_CLAWBACK)->sole();

    return [$owner->fresh(), $root, $actor];
}

function financialUsabilityCollection(FinancialTransaction $root): array
{
    return ['amount' => '4.00', 'source' => 'external_received', 'request_id' => (string) Str::ulid(),
        'external_channel' => 'bank', 'external_reference' => 'USABILITY-RECEIPT-'.Str::ulid(),
        'received_at' => now()->subMinute()->startOfMinute()->toDateTimeString(), 'confirm' => '1',
        'version' => app(AffiliateReceivableBalance::class)->project($root)['version']];
}

it('renders the closed-account clearance form for all existing roles without undefined loop variables', function (): void {
    $actor = financialUsabilityActor(array_keys(AdminRoles::permissions()));
    $this->actingAs($actor)->get('/admin/wallet-clearances')->assertOk()->assertSee('登记清退申请');
    Http::assertNothingSent();
});

it('opens payee details with one audited POST and keeps sensitive data behind its permission', function (string $kind): void {
    [$owner, $actor, $record, $path] = financialUsabilityPayout($kind);
    $before = $record->fresh()->getRawOriginal();
    $balances = $owner->fresh()->only(['credit', 'frozen_credit', 'wallet_clearance_frozen', 'affiliate_balance', 'affiliate_frozen_balance']);
    $page = $this->actingAs($actor)->get($kind === 'external' ? '/admin/external-payouts' : '/admin/wallet-clearances')->assertOk();
    expect(financialUsabilityForm($page->getContent(), $path.'/details'))->not->toContain('name="reason"', 'name="confirm"');
    $page->assertDontSee('PRIVATE-USABILITY-ACCOUNT');
    $this->actingAs($owner)->post($path.'/details')->assertForbidden();
    $this->actingAs($actor)->post($path.'/details')->assertOk()->assertSee('PRIVATE-USABILITY-ACCOUNT')
        ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
    $auditAction = $kind === 'external' ? 'affiliate.external_details_view' : 'wallet.clearance_details_view';
    $this->assertDatabaseHas('audit_logs', ['action' => $auditAction, 'actor_id' => $actor->id, 'subject_id' => $record->id,
        'reason' => $kind === 'external' ? '查看外部佣金提现收款资料' : '查看钱包结清原笔收款资料']);
    expect(DB::table('audit_logs')->where('action', $auditAction)->count())->toBe(1)
        ->and($record->fresh()->getRawOriginal())->toBe($before)
        ->and($owner->fresh()->only(array_keys($balances)))->toBe($balances);
    Http::assertNothingSent();
})->with(['external', 'wallet']);

it('opens reconciliation evidence with automatic access audit and no manual explanation', function (): void {
    $owner = User::factory()->create();
    $actor = financialUsabilityActor(['finance-reconciliation']);
    $case = ReconciliationCase::create(['user_id' => $owner->id, 'kind' => 'payment_attention', 'source_type' => 'callback',
        'source_id' => 'test-'.Str::ulid(), 'source_fingerprint' => str_repeat('a', 64), 'initial_reason' => 'wallet_capacity']);
    $entry = ReconciliationEntry::create(['case_id' => $case->id, 'sequence' => 1, 'request_key' => 'source', 'action' => 'observed',
        'reason' => 'Original evidence', 'evidence' => ['note' => 'PRIVATE-USABILITY-EVIDENCE'], 'created_at' => now()]);
    $before = $entry->fresh()->getRawOriginal();
    $path = '/admin/reconciliations/'.$case->id.'/evidence';
    $page = $this->actingAs($actor)->get('/admin/reconciliations?case='.$case->id)->assertOk();
    expect(financialUsabilityForm($page->getContent(), $path))->not->toContain('name="reason"', 'name="confirm"');
    $this->actingAs($owner)->post($path)->assertForbidden();
    $this->actingAs($actor)->post($path)->assertOk()->assertSee('PRIVATE-USABILITY-EVIDENCE')->assertHeader('Cache-Control', 'no-store, private');
    $this->assertDatabaseHas('audit_logs', ['action' => 'reconciliation.evidence_view', 'actor_id' => $actor->id,
        'subject_id' => $case->id, 'reason' => '查看原笔核验证据']);
    expect($entry->fresh()->getRawOriginal())->toBe($before)->and(FinancialTransaction::count())->toBe(0);
    Http::assertNothingSent();
});

it('uses stable automatic notes for normal payout steps while preserving replay and unique settlement', function (string $kind): void {
    [$owner, $actor, $record, $path] = financialUsabilityPayout($kind);
    $this->actingAs($actor);
    $approve = ['request_id' => (string) Str::ulid(), 'operation' => 'approve', 'confirm' => '1'];
    $this->post($path, $approve)->assertRedirect();
    $this->travel(1)->minutes();
    $this->post($path, $approve)->assertRedirect();
    expect($record->fresh()->status)->toBe('reserved');
    $start = ['request_id' => (string) Str::ulid(), 'operation' => 'start', 'confirm' => '1', 'reason' => ' '];
    $this->post($path, $start)->assertRedirect();
    $key = $record->fresh()->payout_intent_key;
    $paid = ['request_id' => (string) Str::ulid(), 'operation' => 'paid', 'confirm' => '1', 'intent_key' => $key,
        'confirmed_amount' => '10.00', 'currency' => 'CNY', 'external_reference' => 'USABILITY-PAID-'.$kind,
        'evidence' => 'PRIVATE-USABILITY-COMPLETED-PROOF'];
    $this->post($path, $paid)->assertRedirect();
    $this->travel(1)->minutes();
    $this->post($path, $paid)->assertRedirect();
    expect($record->fresh()->status)->toBe('paid');
    $reason = $kind === 'external' ? '核对凭证并确认外部佣金提现已付款' : '核对凭证并确认钱包结清已付款';
    expect($record->fresh()->operation_requests[$paid['request_id']]['intent']['reason'])->toBe($reason);
    $reference = $kind === 'external' ? 'payout:'.$record->id : 'wallet_clearance:'.$record->id.':paid';
    expect(FinancialTransaction::where('reference_key', $reference)->count())->toBe(1);
    $this->assertDatabaseCount('external_payout_receipts', 1);
    $balances = $owner->fresh()->only(['credit', 'wallet_clearance_frozen', 'affiliate_balance', 'affiliate_frozen_balance']);
    expect($balances)->toBe($kind === 'external'
        ? ['credit' => '25.00', 'wallet_clearance_frozen' => '0.00', 'affiliate_balance' => '20.00', 'affiliate_frozen_balance' => '0.00']
        : ['credit' => '15.00', 'wallet_clearance_frozen' => '0.00', 'affiliate_balance' => '30.00', 'affiliate_frozen_balance' => '0.00']);
    $this->postJson($path, $paid + ['reason' => 'Changed replay note'])->assertUnprocessable();
    expect($owner->fresh()->only(array_keys($balances)))->toBe($balances);
    Http::assertNothingSent();
})->with(['external', 'wallet']);

it('retains real confirmation evidence and explanations for exceptional payout outcomes', function (string $kind): void {
    [$owner, $actor, $record, $path] = financialUsabilityPayout($kind);
    $this->actingAs($actor);
    $this->postJson($path, ['request_id' => (string) Str::ulid(), 'operation' => 'approve'])->assertUnprocessable()->assertJsonValidationErrors('confirm');
    foreach (['approve', 'start'] as $operation) {
        $this->post($path, ['request_id' => (string) Str::ulid(), 'operation' => $operation, 'confirm' => '1'])->assertRedirect();
    }
    $key = $record->fresh()->payout_intent_key;
    $balances = $owner->fresh()->only(['credit', 'wallet_clearance_frozen', 'affiliate_balance', 'affiliate_frozen_balance']);
    foreach (['unknown', 'confirm_not_paid'] as $operation) {
        $this->postJson($path, ['request_id' => (string) Str::ulid(), 'operation' => $operation,
            'intent_key' => $key, 'evidence' => 'Observed external result', 'confirm' => '1'])->assertUnprocessable()->assertJsonValidationErrors('reason');
    }
    $paid = ['request_id' => (string) Str::ulid(), 'operation' => 'paid', 'intent_key' => $key,
        'confirmed_amount' => '10.00', 'currency' => 'CNY', 'external_reference' => 'UNCONFIRMED-'.$kind];
    $this->postJson($path, $paid)->assertUnprocessable()->assertJsonValidationErrors('confirm');
    $this->postJson($path, $paid + ['confirm' => '1'])->assertUnprocessable();
    expect($record->fresh()->status)->toBe('processing')->and($owner->fresh()->only(array_keys($balances)))->toBe($balances);
    $this->assertDatabaseCount('external_payout_receipts', 0);
    Http::assertNothingSent();
})->with(['external', 'wallet']);

it('registers ordinary collections with an automatic note and keeps arbitrary existing channel keys supported', function (): void {
    [$owner, $root, $actor] = financialUsabilityReceivable();
    $balances = $owner->only(['credit', 'frozen_credit', 'affiliate_balance']);
    $path = '/admin/finance/receivables/'.$root->id.'/settlements';
    $input = financialUsabilityCollection($root);
    $this->actingAs($actor)->get('/admin/finance/receivables?clawback='.$root->id)->assertOk()
        ->assertSee('收款渠道')->assertSee('银行转账')->assertSee('支付宝')->assertSee('微信支付')->assertDontSee('更正原清偿编号（选填）');
    $this->post($path, $input)->assertRedirect()->assertSessionHasNoErrors();
    $this->post($path, $input)->assertRedirect()->assertSessionHasNoErrors();
    expect(AffiliateReceivableSettlement::sole()->reason)->toBe('登记已核实收到的外部应收款')
        ->and(app(AffiliateReceivableBalance::class)->project($root)['remaining'])->toBe('6.00');
    $this->post($path, array_replace(financialUsabilityCollection($root), ['amount' => '1.00', 'external_channel' => '', 'custom_external_channel' => 'existing_channel']))
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(AffiliateReceivableSettlement::where('external_channel', 'existing_channel')->count())->toBe(1)
        ->and($owner->fresh()->only(array_keys($balances)))->toBe($balances);
    Http::assertNothingSent();
});

it('starts a correction from its reversed receipt and preserves the original evidence and receipt identity', function (): void {
    [$owner, $root, $actor] = financialUsabilityReceivable();
    $input = financialUsabilityCollection($root) + ['reason' => 'Original collection proof'];
    $entry = app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, $input);
    $this->actingAs($actor)->post('/admin/finance/receivable-settlements/'.$entry->id.'/reverse', [
        'request_id' => (string) Str::ulid(), 'reason' => 'Incorrect original registration', 'correction_reference' => 'ACTUAL-CORRECTION-PROOF',
        'confirm' => '1', 'version' => app(AffiliateReceivableBalance::class)->project($root)['version'],
    ])->assertRedirect();
    $before = $entry->fresh()->getRawOriginal();
    $this->get('/admin/finance/receivables?clawback='.$root->id)->assertOk()->assertSee('更正这笔收款');
    $this->get('/admin/finance/receivables?clawback='.$root->id.'&corrects_settlement='.$entry->id)->assertOk()
        ->assertSee('正在更正清偿 #'.$entry->id)->assertSee('name="corrects_settlement_id" value="'.$entry->id.'"', false)
        ->assertSee('name="external_reference" value="'.$entry->external_reference.'"', false);
    $corrected = array_replace(financialUsabilityCollection($root), ['amount' => '3.00', 'external_reference' => $entry->external_reference,
        'external_channel' => $entry->external_channel, 'corrects_settlement_id' => $entry->id]);
    $this->post('/admin/finance/receivables/'.$root->id.'/settlements', $corrected)->assertRedirect();
    expect(AffiliateReceivableSettlement::where('corrects_settlement_id', $entry->id)->sole()->reason)->toBe('登记更正后的外部应收收款')
        ->and($entry->fresh()->getRawOriginal())->toBe($before)
        ->and(app(AffiliateReceivableBalance::class)->project($root)['remaining'])->toBe('7.00');
    Http::assertNothingSent();
});

it('keeps explicit normal-operation notes including zero and rejects malformed notes before money changes', function (string $kind): void {
    [$owner, $actor, $record, $path] = financialUsabilityPayout($kind);
    $input = ['request_id' => (string) Str::ulid(), 'operation' => 'approve', 'confirm' => '1'];
    $this->actingAs($actor)->postJson($path, $input + ['reason' => ['invalid']])->assertUnprocessable()->assertJsonValidationErrors('reason');
    expect($record->fresh()->status)->toBe('pending');
    $this->post($path, $input + ['reason' => '0'])->assertRedirect()->assertSessionHasNoErrors();
    expect($record->fresh()->operation_requests[$input['request_id']]['intent']['reason'])->toBe('0');
    Http::assertNothingSent();
})->with(['external', 'wallet']);

it('retains correction context when selecting a different original receivable owner', function (): void {
    [$oldOwner, $oldRoot, $actor] = financialUsabilityReceivable();
    [$newOwner, $newRoot] = financialUsabilityReceivable();
    $entry = app(RecordAffiliateReceivableSettlementAction::class)->execute($oldRoot, $actor,
        financialUsabilityCollection($oldRoot) + ['reason' => 'Original wrong allocation']);
    app(ReverseAffiliateReceivableSettlementAction::class)->execute($entry, $actor, [
        'request_id' => (string) Str::ulid(), 'reason' => 'Collection allocated to wrong original owner', 'correction_reference' => 'WRONG-OWNER-PROOF',
        'confirm' => '1', 'version' => app(AffiliateReceivableBalance::class)->project($oldRoot)['version'],
    ]);
    $this->actingAs($actor)->get('/admin/finance/receivables?clawback='.$newRoot->id.'&corrects_settlement='.$entry->id)->assertOk()
        ->assertSee('原用户 #'.$oldOwner->id)->assertSee('选择此笔应收')
        ->assertSee('?clawback='.$oldRoot->id.'&amp;corrects_settlement='.$entry->id, false);
    $input = array_replace(financialUsabilityCollection($newRoot), ['amount' => '3.00', 'corrects_settlement_id' => $entry->id,
        'external_channel' => $entry->external_channel, 'external_reference' => $entry->external_reference]);
    $this->post('/admin/finance/receivables/'.$newRoot->id.'/settlements', $input)->assertRedirect()->assertSessionHasNoErrors();
    expect(AffiliateReceivableSettlement::where('corrects_settlement_id', $entry->id)->sole()->user_id)->toBe($newOwner->id)
        ->and(app(AffiliateReceivableBalance::class)->project($oldRoot)['remaining'])->toBe('10.00')
        ->and(app(AffiliateReceivableBalance::class)->project($newRoot)['remaining'])->toBe('7.00')
        ->and($oldOwner->fresh()->credit)->toBe('17.00')->and($newOwner->fresh()->credit)->toBe('17.00');
    Http::assertNothingSent();
});

it('shows a keep-service waiver only when termination is not already confirmed', function (): void {
    $actor = financialUsabilityActor(['finance-operator']);
    foreach ([InstanceStatus::CANCELLED, InstanceStatus::ACTIVE] as $status) {
        $instance = Instance::factory()->create(['status' => $status]);
        $invoice = Invoice::factory()->paid()->create(['user_id' => $instance->user_id, 'product_id' => $instance->product_id, 'instance_id' => $instance->id]);
        $response = $this->actingAs($actor)->get('/admin/finance/invoice?invoice_id='.$invoice->id)->assertOk();
        if ($status->isTerminal()) {
            $response->assertDontSee('name="keep_service"', false)->assertSee('备注（选填）');
        } else {
            $response->assertSee('name="keep_service"', false)->assertSee('退款说明（保留服务时必填）');
        }
        $response->assertSee('x-show="mode === \'external_record\'"', false)
            ->assertSee(':disabled="mode !== \'external_record\'"', false);
    }
    Http::assertNothingSent();
});
