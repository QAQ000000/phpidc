<?php

declare(strict_types=1);

use App\Actions\RefundInvoiceAction;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Models\AffiliateCommission;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| SPECIFICATIONS — NOT PART OF THE DEFAULT TEST RUN
|--------------------------------------------------------------------------
|
| These cases describe behaviour that does not exist yet. They are kept out of
| the default `pest` run so that a red suite always means a real regression,
| never "phase 1 is not written yet".
|
| Run them deliberately:
|
|     ./vendor/bin/pest tests/Specs
|
| Rules for agents (docs/agent-guardrails.md):
|
|  - Deleting or weakening a case here is forbidden. tests/Unit/SpecIntegrityTest
|    asserts the exact case count of this file.
|  - When a case turns green, move it to tests/Feature and update the count in
|    SpecIntegrityTest. That is the only sanctioned way to reduce it.
|
*/

uses(RefreshDatabase::class);

it('claws back frozen affiliate commission on refund and records expense on external refund', function (): void {
    $referrer = User::factory()->create(['affiliate_balance' => '0.00']);
    $buyer = User::factory()->create(['referred_by' => $referrer->id]);
    $invoice = Invoice::factory()->create([
        'user_id' => $buyer->id,
        'total_amount' => '100.00',
        'status' => InvoiceStatus::PAID->value,
    ]);
    $admin = User::factory()->create();
    $reason = 'Refund approved while retaining the service.';
    Gate::define('refund-invoice', fn (User $actor, Invoice $subject): bool => $actor->is($admin) && $subject->is($invoice));
    $this->actingAs($admin);
    $commission = AffiliateCommission::factory()->create([
        'referrer_id' => $referrer->id,
        'buyer_id' => $buyer->id,
        'invoice_id' => $invoice->id,
        'rate_snapshot' => '10.00',
        'amount' => '10.00',
        'status' => 'pending',
    ]);

    app(RefundInvoiceAction::class)->execute(
        $invoice,
        mode: 'external_record',
        keepService: true,
        reason: $reason,
        actor: $admin,
    );

    expect($commission->fresh()->status)->toBe('clawed_back');

    $this->assertDatabaseHas('financial_transactions', [
        'invoice_id' => $invoice->id,
        'type' => 'expense',
        'amount' => '100.00',
    ]);
    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $admin->id,
        'action' => 'invoice.refund',
        'subject_type' => Invoice::class,
        'subject_id' => $invoice->id,
        'reason' => $reason,
    ]);
});

it('refuses to refund while the instance is still running', function (): void {
    $user = User::factory()->create();
    $instance = Instance::factory()->create([
        'user_id' => $user->id,
        'status' => InstanceStatus::ACTIVE->value,
    ]);
    $invoice = Invoice::factory()->create([
        'user_id' => $user->id,
        'instance_id' => $instance->id,
        'total_amount' => '100.00',
        'status' => InvoiceStatus::PAID->value,
    ]);

    expect(fn () => app(RefundInvoiceAction::class)
        ->execute($invoice, mode: 'refund_wallet'))
        ->toThrow(DomainException::class);
});
