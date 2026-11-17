<?php

declare(strict_types=1);

use App\Actions\RefundInvoiceAction;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
