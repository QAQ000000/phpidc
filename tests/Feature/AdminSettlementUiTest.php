<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\RecordAffiliateReceivableSettlementAction;
use App\Actions\RefundInvoiceAction;
use App\Actions\RequestExternalAffiliateWithdrawalAction;
use App\Actions\RequestWalletClearanceAction;
use App\Actions\ReverseAffiliateReceivableSettlementAction;
use App\Enums\TransactionType;
use App\Models\AffiliateCommission;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AffiliateReceivableBalance;
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

afterEach(function (): void {
    Http::assertNothingSent();
});

function b2SettlementActor(string ...$roles): User
{
    $actor = User::factory()->create();
    foreach ($roles as $role) {
        app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated B2 settlement UI acceptance');
    }

    return $actor;
}

function b2SettlementXPath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

    return new DOMXPath($document);
}

function b2SettlementForm(DOMXPath $xpath, string $path, ?string $operation = null): DOMElement
{
    $expression = '//main//form[@method="post"][@action="'.$path.'"]';
    if ($operation !== null) {
        $expression .= '[.//input[@name="operation"][@value="'.$operation.'"]]';
    }
    $forms = $xpath->query($expression);
    expect($forms)->toHaveCount(1);
    $form = $forms->item(0);
    expect($form)->toBeInstanceOf(DOMElement::class);

    return $form;
}

function b2SettlementField(DOMXPath $xpath, DOMElement $form, string $name): DOMElement
{
    $fields = $xpath->query('.//*[@name="'.$name.'"]', $form);
    expect($fields)->toHaveCount(1);
    $field = $fields->item(0);
    expect($field)->toBeInstanceOf(DOMElement::class);

    return $field;
}

function b2SettlementValue(DOMXPath $xpath, DOMElement $form, string $name): string
{
    $field = b2SettlementField($xpath, $form, $name);

    return $field->tagName === 'textarea' ? $field->textContent : $field->getAttribute('value');
}

function b2SettlementPayout(string $kind, ?User $actor = null): array
{
    $owner = User::factory()->create(['credit' => '25.00', 'affiliate_balance' => '30.00']);
    $actor ??= b2SettlementActor(...($kind === 'external'
        ? ['external-payout-reviewer', 'external-payout-operator', 'external-payout-confirmer']
        : ['wallet-clearance-reviewer', 'wallet-clearance-operator', 'wallet-clearance-confirmer']));
    $input = ['request_id' => (string) Str::ulid(), 'amount' => '10.00', 'payout_method' => 'alipay',
        'payee_name' => 'Isolated payee', 'payee_account' => 'B2-PRIVATE-PAYEE', 'current_password' => 'password', 'confirm' => '1'];
    $record = $kind === 'external'
        ? app(RequestExternalAffiliateWithdrawalAction::class)->execute($owner, $input)
        : app(RequestWalletClearanceAction::class)->execute($owner, $input);
    $page = $kind === 'external' ? '/admin/external-payouts' : '/admin/wallet-clearances';

    return [$owner, $actor, $record, $page];
}

function b2SettlementReceivable(?User $actor = null): array
{
    $actor ??= b2SettlementActor('finance-operator', 'finance-receivable', 'finance-receivable-reviewer');
    $owner = User::factory()->create(['credit' => '17.00', 'affiliate_balance' => '0.00']);
    $invoice = Invoice::factory()->paid()->create(['total_amount' => '100.00', 'gateway_amount' => '100.00']);
    AffiliateCommission::factory()->create(['referrer_id' => $owner->id, 'buyer_id' => $invoice->user_id,
        'invoice_id' => $invoice->id, 'status' => 'available', 'amount' => '10.00']);
    app(RefundInvoiceAction::class)->execute($invoice, 'external_record', true, 'Isolated original completed refund', $actor);
    $root = FinancialTransaction::where('user_id', $owner->id)->where('type', TransactionType::AFFILIATE_CLAWBACK)->sole();

    return [$owner->fresh(), $root, $actor];
}

function b2SettlementCollection(FinancialTransaction $root, array $changes = []): array
{
    return array_replace(['_admin_form' => 'receivable-settlement', 'clawback_id' => $root->id,
        'amount' => '4.00', 'source' => 'external_received', 'request_id' => (string) Str::ulid(),
        'external_channel' => 'bank', 'external_reference' => 'B2-RECEIPT-'.Str::ulid(),
        'received_at' => now()->subMinute()->startOfMinute()->toDateTimeString(), 'confirm' => '1',
        'version' => app(AffiliateReceivableBalance::class)->project($root)['version'], 'reason' => 'Verified isolated received collection'], $changes);
}

it('uses local semantic settlement tables and exposes no financial write forms to viewers', function (string $path): void {
    $actor = b2SettlementActor('finance-viewer');
    $audits = DB::table('audit_logs')->count();
    $response = $this->actingAs($actor)->get($path)->assertOk();
    $xpath = b2SettlementXPath($response->getContent());
    expect($xpath->query('//main//form[@method="post"]'))->toHaveCount(0)
        ->and($xpath->query('//main//table/caption'))->not->toHaveCount(0)
        ->and($xpath->query('//main//*[contains(concat(" ",normalize-space(@class)," ")," inventory-box ")]'))->toHaveCount(0)
        ->and(DB::table('audit_logs')->count())->toBe($audits);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('notification_events', 0);
    $this->actingAs(User::factory()->create())->get($path)->assertForbidden();
})->with(['/admin/finance/ledger', '/admin/finance/receivables', '/admin/wallet-clearances', '/admin/external-payouts']);

it('scopes rejected payout input to its record and operation inside native dialogs', function (string $kind): void {
    [, $actor, $record, $page] = b2SettlementPayout($kind);
    [, , $other] = b2SettlementPayout($kind, $actor);
    $requestId = (string) Str::ulid();
    $marker = $kind === 'external' ? 'external-payout' : 'wallet-clearance';
    $path = $page.'/'.$record->id;
    $this->actingAs($actor)->from($page)->post($path, ['_admin_form' => $marker, 'record_id' => $record->id,
        'operation' => 'reject', 'request_id' => $requestId, 'reason' => '', 'confirm' => '1'])
        ->assertRedirect($page)->assertSessionHasErrors('reason');
    $xpath = b2SettlementXPath($this->get($page)->assertOk()->assertDontSee('B2-PRIVATE-PAYEE')->getContent());
    $form = b2SettlementForm($xpath, $path, 'reject');
    expect(b2SettlementValue($xpath, $form, '_admin_form'))->toBe($marker)
        ->and(b2SettlementValue($xpath, $form, 'request_id'))->toBe($requestId)
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $form))->toHaveCount(1)
        ->and(b2SettlementValue($xpath, $form, '_token'))->not->toBe('');
    foreach ([[$path, 'approve'], [$page.'/'.$other->id, 'reject']] as [$otherPath, $operation]) {
        $otherForm = b2SettlementForm($xpath, $otherPath, $operation);
        expect(b2SettlementValue($xpath, $otherForm, 'request_id'))->not->toBe($requestId)
            ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $otherForm))->toHaveCount(0);
    }
    expect($record->fresh()->status)->toBe('pending')->and($other->fresh()->status)->toBe('pending');
})->with(['external', 'wallet']);

it('keeps protected payout proofs blank while restoring the failed amount and request only for its action', function (string $kind): void {
    [$owner, $actor, $record, $page] = b2SettlementPayout($kind);
    $path = $page.'/'.$record->id;
    $this->actingAs($actor);
    foreach (['approve', 'start'] as $operation) {
        $this->post($path, ['request_id' => (string) Str::ulid(), 'operation' => $operation, 'confirm' => '1'])->assertRedirect();
    }
    $before = $owner->fresh()->only(['credit', 'wallet_clearance_frozen', 'affiliate_balance', 'affiliate_frozen_balance']);
    $requestId = (string) Str::ulid();
    $this->from($page)->post($path, ['_admin_form' => $kind === 'external' ? 'external-payout' : 'wallet-clearance',
        'record_id' => $record->id, 'operation' => 'paid', 'request_id' => $requestId, 'confirm' => '1',
        'intent_key' => $record->fresh()->payout_intent_key, 'confirmed_amount' => '9.99', 'currency' => 'CNY',
        'external_reference' => 'B2-PRIVATE-TRANSACTION', 'evidence' => 'B2-PRIVATE-TRANSFER-PROOF', 'reason' => 'B2 permitted retry note'])
        ->assertRedirect($page)->assertSessionHasErrors()->assertSessionMissing('_old_input.evidence')->assertSessionMissing('_old_input.external_reference');
    $xpath = b2SettlementXPath($this->get($page)->assertOk()->assertDontSee('B2-PRIVATE-TRANSACTION')->assertDontSee('B2-PRIVATE-TRANSFER-PROOF')->getContent());
    $paid = b2SettlementForm($xpath, $path, 'paid');
    expect(b2SettlementValue($xpath, $paid, 'confirmed_amount'))->toBe('9.99')
        ->and(b2SettlementValue($xpath, $paid, 'request_id'))->toBe($requestId)
        ->and(b2SettlementValue($xpath, $paid, 'reason'))->toBe('B2 permitted retry note')
        ->and(b2SettlementValue($xpath, $paid, 'evidence'))->toBe('')
        ->and(b2SettlementValue($xpath, $paid, 'external_reference'))->toBe('')
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $paid))->toHaveCount(1);
    $unknown = b2SettlementForm($xpath, $path, 'unknown');
    expect(b2SettlementValue($xpath, $unknown, 'reason'))->toBe('')
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $unknown))->toHaveCount(0)
        ->and($record->fresh()->status)->toBe('processing')
        ->and($owner->fresh()->only(array_keys($before)))->toBe($before);
    $this->assertDatabaseCount('external_payout_receipts', 0);
})->with(['external', 'wallet']);

it('restores a rejected collection from its own root while explicit navigation never copies the old amount', function (): void {
    [$owner, $root, $actor] = b2SettlementReceivable();
    [, $other] = b2SettlementReceivable($actor);
    $input = b2SettlementCollection($root, ['amount' => '11.00', 'reason' => 'B2 retry this collection', 'external_reference' => 'B2-PRIVATE-COLLECTION']);
    $this->actingAs($actor)->from('/admin/finance/receivables')->post('/admin/finance/receivables/'.$root->id.'/settlements', $input)
        ->assertRedirect('/admin/finance/receivables')->assertSessionHasErrors()->assertSessionMissing('_old_input.external_reference');
    $xpath = b2SettlementXPath($this->get('/admin/finance/receivables')->assertOk()->assertDontSee('B2-PRIVATE-COLLECTION')->getContent());
    $form = b2SettlementForm($xpath, '/admin/finance/receivables/'.$root->id.'/settlements');
    expect(b2SettlementValue($xpath, $form, 'amount'))->toBe('11.00')
        ->and(b2SettlementValue($xpath, $form, 'request_id'))->toBe($input['request_id'])
        ->and(b2SettlementValue($xpath, $form, 'external_reference'))->toBe('')
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $form))->toHaveCount(1);
    $review = b2SettlementForm($xpath, '/admin/finance/receivables/'.$owner->id.'/review');
    expect(b2SettlementValue($xpath, $review, 'reason'))->toBe('')
        ->and(b2SettlementValue($xpath, $review, 'request_id'))->not->toBe($input['request_id']);
    $this->withSession(['errors' => session('errors'), '_old_input' => $input]);
    $otherXPath = b2SettlementXPath($this->get('/admin/finance/receivables?clawback='.$other->id)->assertOk()->getContent());
    $otherForm = b2SettlementForm($otherXPath, '/admin/finance/receivables/'.$other->id.'/settlements');
    expect(b2SettlementValue($otherXPath, $otherForm, 'amount'))->toBe('')
        ->and(b2SettlementValue($otherXPath, $otherForm, 'reason'))->toBe('')
        ->and($otherXPath->query('ancestor::dialog//*[@role="alert"]', $otherForm))->toHaveCount(0);
    $this->assertDatabaseCount('affiliate_receivable_settlements', 0);
});

it('reopens only the rejected reversal and keeps all other settlement actions clean', function (): void {
    [, $root, $actor] = b2SettlementReceivable();
    $first = app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, b2SettlementCollection($root));
    $second = app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, b2SettlementCollection($root, ['amount' => '2.00']));
    $requestId = (string) Str::ulid();
    $this->actingAs($actor)->from('/admin/finance/receivables')->post('/admin/finance/receivable-settlements/'.$first->id.'/reverse', [
        '_admin_form' => 'receivable-reversal', 'clawback_id' => $root->id, 'settlement_id' => $first->id,
        'request_id' => $requestId, 'version' => app(AffiliateReceivableBalance::class)->project($root)['version'],
        'reason' => '', 'correction_reference' => 'B2-PRIVATE-REVERSAL', 'confirm' => '1',
    ])->assertSessionHasErrors('reason')->assertSessionMissing('_old_input.correction_reference');
    $xpath = b2SettlementXPath($this->get('/admin/finance/receivables')->assertOk()->assertDontSee('B2-PRIVATE-REVERSAL')->getContent());
    $firstForm = b2SettlementForm($xpath, '/admin/finance/receivable-settlements/'.$first->id.'/reverse');
    $secondForm = b2SettlementForm($xpath, '/admin/finance/receivable-settlements/'.$second->id.'/reverse');
    expect(b2SettlementValue($xpath, $firstForm, 'request_id'))->toBe($requestId)
        ->and(b2SettlementValue($xpath, $firstForm, 'correction_reference'))->toBe('')
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $firstForm))->toHaveCount(1)
        ->and(b2SettlementValue($xpath, $secondForm, 'request_id'))->not->toBe($requestId)
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $secondForm))->toHaveCount(0);
    $settle = b2SettlementForm($xpath, '/admin/finance/receivables/'.$root->id.'/settlements');
    expect(b2SettlementValue($xpath, $settle, 'request_id'))->not->toBe($requestId);
    $this->assertDatabaseCount('affiliate_receivable_reversals', 0);
});

it('keeps a failed closed-account registration separate from payout actions and never restores payee details', function (): void {
    [, $actor, $record, $page] = b2SettlementPayout('wallet');
    $requestId = (string) Str::ulid();
    $input = ['_admin_form' => 'closed-wallet-clearance', 'user_id' => '', 'reconciliation_case_id' => '99',
        'request_id' => $requestId, 'amount' => '7.25', 'payout_method' => 'alipay', 'reason' => 'B2 original identity note',
        'payee_name' => 'B2-PRIVATE-NAME', 'payee_account' => 'B2-PRIVATE-ACCOUNT', 'bank_name' => 'B2-PRIVATE-BANK', 'evidence' => 'B2-PRIVATE-RIGHTS', 'confirm' => '1'];
    $this->actingAs($actor)->from($page)->post('/admin/wallet-clearances/closed-account', $input)
        ->assertSessionHasErrors('user_id')->assertSessionMissing('_old_input.payee_name')->assertSessionMissing('_old_input.payee_account')->assertSessionMissing('_old_input.bank_name')->assertSessionMissing('_old_input.evidence');
    $response = $this->get($page)->assertOk()->assertDontSee('B2-PRIVATE-NAME')->assertDontSee('B2-PRIVATE-ACCOUNT')->assertDontSee('B2-PRIVATE-BANK')->assertDontSee('B2-PRIVATE-RIGHTS');
    $xpath = b2SettlementXPath($response->getContent());
    $form = b2SettlementForm($xpath, '/admin/wallet-clearances/closed-account');
    expect(b2SettlementValue($xpath, $form, 'request_id'))->toBe($requestId)
        ->and(b2SettlementValue($xpath, $form, 'amount'))->toBe('7.25')
        ->and(b2SettlementValue($xpath, $form, 'reason'))->toBe('B2 original identity note')
        ->and(b2SettlementValue($xpath, $form, 'payee_name'))->toBe('')
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $form))->toHaveCount(1);
    $approve = b2SettlementForm($xpath, $page.'/'.$record->id, 'approve');
    expect(b2SettlementValue($xpath, $approve, 'reason'))->toBe('')
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $approve))->toHaveCount(0);
});

it('does not restore a failed collection into a different explicit correction on the same root', function (): void {
    [, $root, $actor] = b2SettlementReceivable();
    $entries = [];
    foreach (['4.00', '2.00'] as $amount) {
        $entry = app(RecordAffiliateReceivableSettlementAction::class)->execute($root, $actor, b2SettlementCollection($root, ['amount' => $amount]));
        app(ReverseAffiliateReceivableSettlementAction::class)->execute($entry, $actor, [
            'request_id' => (string) Str::ulid(), 'reason' => 'Original test registration is incorrect',
            'correction_reference' => 'B2-CORRECTION-'.Str::ulid(), 'confirm' => '1',
            'version' => app(AffiliateReceivableBalance::class)->project($root)['version'],
        ]);
        $entries[] = $entry;
    }
    [$first, $second] = $entries;
    $input = b2SettlementCollection($root, ['amount' => '11.00', 'reason' => 'B2 first correction retry',
        'corrects_settlement_id' => $first->id, 'external_channel' => $first->external_channel, 'external_reference' => $first->external_reference]);
    $this->actingAs($actor)->from('/admin/finance/receivables?clawback='.$root->id.'&corrects_settlement='.$first->id)
        ->post('/admin/finance/receivables/'.$root->id.'/settlements', $input)->assertSessionHasErrors();
    $xpath = b2SettlementXPath($this->get('/admin/finance/receivables?clawback='.$root->id.'&corrects_settlement='.$second->id)->assertOk()->getContent());
    $form = b2SettlementForm($xpath, '/admin/finance/receivables/'.$root->id.'/settlements');
    expect(b2SettlementValue($xpath, $form, 'corrects_settlement_id'))->toBe((string) $second->id)
        ->and(b2SettlementValue($xpath, $form, 'amount'))->toBe('2.00')
        ->and(b2SettlementValue($xpath, $form, 'reason'))->toBe('')
        ->and(b2SettlementValue($xpath, $form, 'request_id'))->not->toBe($input['request_id'])
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $form))->toHaveCount(0);
});
