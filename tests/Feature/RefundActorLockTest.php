<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\RefundInvoiceAction;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Queue::fake();
    Http::preventStrayRequests();
});

it('revalidates the locked refund operator after stale authorization or account closure', function (string $change): void {
    $actor = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($actor, 'finance-operator', 'Isolated refund operator fixture');
    $staleActor = User::findOrFail($actor->id)->load('roles.permissions', 'permissions');
    $buyer = User::factory()->create(['credit' => '20.00']);
    $instance = Instance::factory()->create(['user_id' => $buyer->id, 'status' => InstanceStatus::CANCELLED]);
    $invoice = Invoice::factory()->paid()->create(['user_id' => $buyer->id, 'instance_id' => $instance->id]);
    expect(Gate::forUser($staleActor)->allows('refund-invoice', $invoice))->toBeTrue();

    match ($change) {
        'permission-revoked' => app(AssignAdminRoleAction::class)->execute($actor, 'finance-operator', 'Revoke operator before financial transaction', revoke: true),
        'soft-deleted' => $actor->delete(),
        'anonymized' => $actor->forceFill(['anonymized_at' => now()])->save(),
    };
    expect($staleActor->trashed())->toBeFalse()->and($staleActor->anonymized_at)->toBeNull();
    expect(fn () => app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', reason: 'Attempt with stale authorization', actor: $staleActor))
        ->toThrow(DomainException::class, 'Refund is not authorized for this invoice.');
    expect($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)->and($buyer->fresh()->credit)->toBe('20.00')
        ->and(DB::table('audit_logs')->where('action', 'invoice.refund')->count())->toBe(0);
    $this->assertDatabaseCount('financial_transactions', 0);
    Http::assertNothingSent();
})->with(['permission-revoked', 'soft-deleted', 'anonymized']);
