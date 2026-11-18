<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\ReadWalletClearanceDetailsAction;
use App\Actions\ReencryptSecretsAction;
use App\Actions\RefundInvoiceAction;
use App\Actions\RequestClosedAccountWalletClearanceAction;
use App\Models\Invoice;
use App\Models\ReconciliationCase;
use App\Models\ReconciliationEntry;
use App\Models\User;
use App\Models\WalletClearance;
use App\Services\PaymentAttentionCases;
use App\Support\InstallationState;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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

function clearanceEntryInput(array $changes = []): array
{
    return array_replace(['request_id' => (string) Str::ulid(), 'amount' => '0.01', 'payout_method' => 'alipay',
        'payee_name' => 'PRIVATE CLEARANCE PAYEE', 'payee_account' => 'PRIVATE-CLEARANCE-ACCOUNT',
        'current_password' => 'password', 'confirm' => '1'], $changes);
}

it('exposes a working owner clearance page and keeps payee data behind audited finance authority', function (): void {
    $owner = User::factory()->create(['credit' => '0.01']);
    $other = User::factory()->create();
    $actor = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($actor, 'wallet-clearance-operator', 'Isolated clearance details');
    $this->actingAs($owner)->get('/console/wallet')->assertOk()->assertSee('/console/wallet/clearances');
    $this->get('/console/wallet/clearances')->assertOk()->assertSee('钱包结清');
    $this->post('/console/wallet/clearances', clearanceEntryInput())->assertRedirect('/console/wallet/clearances');
    $clearance = WalletClearance::sole();
    $this->get('/console/wallet/clearances')->assertOk()->assertDontSee('PRIVATE-CLEARANCE-ACCOUNT');
    $this->actingAs($other)->get('/console/wallet/clearances')->assertOk()->assertDontSee('申请 #'.$clearance->id);
    $this->post('/admin/wallet-clearances/'.$clearance->id.'/details', ['reason' => 'Unauthorized', 'confirm' => '1'])->assertForbidden();
    $this->actingAs($actor)->get('/admin/wallet-clearances')->assertOk()->assertDontSee('PRIVATE-CLEARANCE-ACCOUNT');
    $this->post('/admin/wallet-clearances/'.$clearance->id.'/details', ['reason' => 'Verify original payee', 'confirm' => '1'])
        ->assertOk()->assertSee('PRIVATE-CLEARANCE-ACCOUNT')->assertHeader('Cache-Control', 'no-store, private');
    expect(DB::table('audit_logs')->where('action', 'wallet.clearance_details_view')->count())->toBe(1);
    app(AssignAdminRoleAction::class)->execute($actor, 'wallet-clearance-operator', 'Revoke isolated details', revoke: true);
    expect(fn () => app(ReadWalletClearanceDetailsAction::class)->execute($clearance, $actor, 'Recheck revoked permission'))->toThrow(AuthorizationException::class);
});

it('does not flash payee details or passwords when a clearance request fails validation', function (): void {
    $owner = User::factory()->create(['credit' => '1.00']);
    $this->actingAs($owner)->from('/console/wallet/clearances')->post('/console/wallet/clearances', clearanceEntryInput(['amount' => '0.00']))
        ->assertRedirect('/console/wallet/clearances')->assertSessionHasErrors('operation');
    foreach (['current_password', 'payee_name', 'payee_account', 'bank_name', 'evidence'] as $field) {
        expect(session()->getOldInput($field))->toBeNull();
    }
});

it('rotates new clearance and reconciliation ciphertext without changing original source identity or funds', function (): void {
    $owner = User::factory()->create(['deleted_at' => now(), 'anonymized_at' => now()]);
    $actor = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($actor, 'finance-operator', 'Isolated refund');
    app(AssignAdminRoleAction::class)->execute($actor, 'wallet-clearance-confirmer', 'Isolated source review');
    $invoice = Invoice::factory()->paid()->create(['user_id' => $owner->id]);
    app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', true, 'Original wallet refund', $actor);
    $case = ReconciliationCase::sole();
    $clearance = app(RequestClosedAccountWalletClearanceAction::class)->execute($owner, $actor,
        clearanceEntryInput(['reconciliation_case_id' => $case->id, 'reason' => 'Original user rights review', 'evidence' => 'PRIVATE-RIGHTS-PROOF']));
    $clearance->update(['operation_requests' => ['PRIVATE-REQUEST' => ['intent' => 'PRIVATE-INTENT']]]);
    $columns = ['payout_details', 'payout_evidence', 'operation_requests'];
    $before = $clearance->fresh()->only($columns);
    $raw = (array) DB::table('wallet_clearances')->where('id', $clearance->id)->first($columns);
    $entry = ReconciliationEntry::sole();
    $entryBefore = $entry->evidence;
    $entryRaw = DB::table('reconciliation_entries')->where('id', $entry->id)->value('evidence');
    $oldKey = config('app.key');
    $newKey = 'base64:'.base64_encode(random_bytes(32));
    $installation = app(InstallationState::class);
    $installation->write('installed.lock', $installation->seal(['format' => 1, 'admin_id' => $actor->id], $oldKey));
    File::put(storage_path('framework/down'), '{}');
    app(ReencryptSecretsAction::class)->execute($oldKey, $newKey, 'Isolated W2 evidence rotation', true);
    $previous = app('encrypter');
    try {
        config(['app.key' => $newKey]);
        app()->instance('encrypter', $installation->encrypter($newKey));
        Crypt::clearResolvedInstance('encrypter');
        expect($clearance->fresh()->only($columns))->toBe($before)->and($entry->fresh()->evidence)->toBe($entryBefore)
            ->and(app(PaymentAttentionCases::class)->assertSource($case)->id)->toBe($case->source_credit_transaction_id)
            ->and(User::withTrashed()->findOrFail($owner->id)->credit)->toBe('10.00');
        foreach ($columns as $column) {
            expect(DB::table('wallet_clearances')->where('id', $clearance->id)->value($column))->not->toBe($raw[$column]);
        }
        expect(DB::table('reconciliation_entries')->where('id', $entry->id)->value('evidence'))->not->toBe($entryRaw);
    } finally {
        config(['app.key' => $oldKey]);
        app()->instance('encrypter', $previous);
        Crypt::clearResolvedInstance('encrypter');
    }
});
