<?php

declare(strict_types=1);

use App\Exceptions\ReceivableScanBudgetExceeded;
use App\Jobs\RunReceivableCheckJob;
use App\Models\User;
use App\Services\ReceivableAdmission;
use App\Services\ReceivableCheckRequests;
use App\Services\ReceivableSnapshot;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    config(['receivables.cache_store' => 'array']);
    Queue::fake();
});

it('binds ephemeral check state to the exact user action request algorithm and keyed canonical intent', function (): void {
    $requests = app(ReceivableCheckRequests::class);
    $hmac = $requests->intent(1, 'wallet.clearance.request', 'stable-original', ['amount' => '10.00', 'payee' => ['account' => 'PRIVATE', 'name' => 'Owner']]);
    expect($requests->intent(1, 'wallet.clearance.request', 'stable-original', ['payee' => ['name' => 'Owner', 'account' => 'PRIVATE'], 'amount' => '10.00']))->toBe($hmac);
    $requests->put(1, 'wallet.clearance.request', 'stable-original', $hmac, ['status' => 'queued']);
    expect($requests->get(1, 'wallet.clearance.request', 'stable-original', $hmac)['status'])->toBe('queued')
        ->and($requests->get(2, 'wallet.clearance.request', 'stable-original'))->toBeNull()
        ->and($requests->get(1, 'affiliate.external.request', 'stable-original'))->toBeNull()
        ->and($requests->get(1, 'wallet.clearance.request', 'different-original'))->toBeNull();
    expect(fn () => $requests->get(1, 'wallet.clearance.request', 'stable-original', $requests->intent(1, 'wallet.clearance.request', 'stable-original', ['amount' => '0.01'])))
        ->toThrow(ValidationException::class);
    config(['receivables.algorithm_version' => 2]);
    expect($requests->get(1, 'wallet.clearance.request', 'stable-original'))->toBeNull();
});

it('expires permissions after ten minutes and invalidates them after an application key change', function (): void {
    $requests = app(ReceivableCheckRequests::class);
    $hmac = $requests->intent(1, 'account.close', 'stable-original', ['confirmed' => true]);
    $requests->put(1, 'account.close', 'stable-original', $hmac, ['status' => 'ready']);
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    expect($requests->get(1, 'account.close', 'stable-original'))->toBeNull()
        ->and($requests->intent(1, 'account.close', 'stable-original', ['confirmed' => true]))->not->toBe($hmac);
    $hmac = $requests->intent(1, 'account.close', 'stable-original', ['confirmed' => true]);
    $requests->put(1, 'account.close', 'stable-original', $hmac, ['status' => 'ready']);
    $this->travel(10)->minutes();
    expect($requests->get(1, 'account.close', 'stable-original'))->toBeNull();
});

it('queues long history once and keeps subsequent continuations from restarting the short scan', function (): void {
    $user = User::factory()->create();
    $snapshot = Mockery::mock(ReceivableSnapshot::class);
    $snapshot->shouldReceive('read')->once()->with($user->id, 2, [])->andThrow(new ReceivableScanBudgetExceeded('Isolated budget exhaustion'));
    app()->instance(ReceivableSnapshot::class, $snapshot);
    $admission = app(ReceivableAdmission::class);
    for ($attempt = 0; $attempt < 2; $attempt++) {
        expect(fn () => $admission->prepare($user, 'wallet.clearance.request', 'stable-original', ['amount' => '10.00', 'payee' => 'PRIVATE-ACCOUNT']))
            ->toThrow(ValidationException::class);
    }
    Queue::assertPushed(RunReceivableCheckJob::class, 1);
    Queue::assertPushed(RunReceivableCheckJob::class, function (RunReceivableCheckJob $job) use ($user): bool {
        expect(serialize($job))->not->toContain('PRIVATE-ACCOUNT', '10.00');

        return $job->userId === $user->id && $job->queue === 'receivable-check' && $job->timeout === 120 && $job->tries === 1;
    });
});
