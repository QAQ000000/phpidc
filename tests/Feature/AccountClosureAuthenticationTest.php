<?php

declare(strict_types=1);

use App\Actions\CloseCustomerAccountAction;
use App\Exceptions\ReceivableScanBudgetExceeded;
use App\Jobs\RunReceivableCheckJob;
use App\Models\User;
use App\Services\ReceivableCheckRequests;
use App\Services\ReceivableSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

it('rejects an incorrect closure password before scanning or reserving an original verification request', function (): void {
    $user = User::factory()->create(['password' => 'Closure-Password-123']);
    $requestId = (string) Str::ulid();
    $snapshot = Mockery::mock(ReceivableSnapshot::class);
    $snapshot->shouldNotReceive('read');
    app()->instance(ReceivableSnapshot::class, $snapshot);

    expect(fn () => app(CloseCustomerAccountAction::class)->execute($user, 'incorrect', true, $requestId))
        ->toThrow(ValidationException::class);

    expect(app(ReceivableCheckRequests::class)->get($user->id, 'account-close', $requestId))->toBeNull()
        ->and($user->fresh()->anonymized_at)->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'identity.account_anonymized')->count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('allows the corrected password to complete the same original request without putting it in verification identity', function (): void {
    $password = 'Corrected-Closure-Password-123';
    $user = User::factory()->create(['password' => $password]);
    $requestId = (string) Str::ulid();
    $snapshot = Mockery::mock(ReceivableSnapshot::class);
    $snapshot->shouldReceive('read')->once()->andThrow(new ReceivableScanBudgetExceeded);
    app()->instance(ReceivableSnapshot::class, $snapshot);
    $close = app(CloseCustomerAccountAction::class);

    expect(fn () => $close->execute($user, 'incorrect', true, $requestId))->toThrow(ValidationException::class);
    Queue::assertNothingPushed();
    expect(fn () => $close->execute($user, $password, true, $requestId))->toThrow(ValidationException::class);

    $requests = app(ReceivableCheckRequests::class);
    $hmac = $requests->intent($user->id, 'account-close', $requestId, ['intent' => ['confirmed' => true], 'capture_ids' => []]);
    $queued = $requests->get($user->id, 'account-close', $requestId, $hmac);
    expect($queued['status'])->toBe('queued')->and(json_encode($queued, JSON_THROW_ON_ERROR))->not->toContain($password, 'current_password');
    Queue::assertPushed(RunReceivableCheckJob::class, fn (RunReceivableCheckJob $job): bool => $job->userId === $user->id
        && $job->requestId === $requestId && $job->intentHmac === $hmac);
    Queue::assertPushed(RunReceivableCheckJob::class, 1);

    (new RunReceivableCheckJob($user->id, 'account-close', $requestId, $hmac))->handle($requests, new ReceivableSnapshot);
    expect($requests->get($user->id, 'account-close', $requestId, $hmac)['status'])->toBe('ready');
    $closed = $close->execute($user, $password, true, $requestId);
    expect($closed->trashed())->toBeTrue()->and($closed->anonymized_at)->not->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'identity.account_anonymized')->count())->toBe(1);

    expect($close->execute($user, 'unused completed replay', true, $requestId)->getRawOriginal())->toBe($closed->getRawOriginal())
        ->and(DB::table('audit_logs')->where('action', 'identity.account_anonymized')->count())->toBe(1);
    Http::assertNothingSent();
});

it('authenticates against the current account instead of a stale caller before starting a scan', function (string $change): void {
    $user = User::factory()->create(['password' => 'Original-Closure-Password-123']);
    $current = User::findOrFail($user->id);
    $current->forceFill($change === 'password' ? ['password' => 'Changed-Closure-Password-456'] : ['deleted_at' => now()])->save();
    $snapshot = Mockery::mock(ReceivableSnapshot::class);
    $snapshot->shouldNotReceive('read');
    app()->instance(ReceivableSnapshot::class, $snapshot);

    expect(fn () => app(CloseCustomerAccountAction::class)->execute($user, 'Original-Closure-Password-123', true))
        ->toThrow(ValidationException::class);
    expect(User::withTrashed()->findOrFail($user->id)->anonymized_at)->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'identity.account_anonymized')->count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['password', 'disabled']);

it('rechecks authentication inside the account transaction after verification has observed an account change', function (string $change): void {
    $password = 'Original-Closure-Password-123';
    $user = User::factory()->create(['password' => $password]);
    $reader = new ReceivableSnapshot;
    $snapshot = Mockery::mock(ReceivableSnapshot::class);
    $snapshot->shouldReceive('read')->once()->andReturnUsing(function (int $id, int $seconds, array $captures) use ($reader, $change): array {
        $scan = $reader->read($id, $seconds, $captures);
        User::findOrFail($id)->forceFill(match ($change) {
            'password' => ['password' => 'Changed-Closure-Password-456'],
            'disabled' => ['deleted_at' => now()],
            'anonymized' => ['anonymized_at' => now()],
        })->save();

        return $scan;
    });
    app()->instance(ReceivableSnapshot::class, $snapshot);

    expect(fn () => app(CloseCustomerAccountAction::class)->execute($user, $password, true))->toThrow(ValidationException::class);
    $current = User::withTrashed()->findOrFail($user->id);
    expect($current->email)->toBe($user->email)
        ->and(DB::table('audit_logs')->where('action', 'identity.account_anonymized')->count())->toBe(0);
    if ($change === 'password') {
        expect(Hash::check('Changed-Closure-Password-456', $current->password))->toBeTrue()->and($current->anonymized_at)->toBeNull();
    }
    Queue::assertNothingPushed();
    Http::assertNothingSent();
})->with(['password', 'disabled', 'anonymized']);
