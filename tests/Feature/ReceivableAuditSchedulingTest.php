<?php

declare(strict_types=1);

use App\Jobs\AuditReceivableEvidenceJob;
use App\Models\User;
use App\Services\ReceivableReconciliation;
use App\Services\ReceivableSnapshot;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('dispatches bounded independent audits without scanning history or performing financial changes in the scheduler', function (): void {
    config(['maintenance.cache_store' => 'array', 'queue.default' => 'database']);
    User::factory()->count(150)->create(['receivable_reconciliation_required' => true]);
    $this->mock(ReceivableSnapshot::class)->shouldNotReceive('read');
    Queue::fake();
    Http::preventStrayRequests();
    expect(Artisan::call('app:receivables-audit'))->toBe(0);
    Queue::assertPushed(AuditReceivableEvidenceJob::class, 100);
    Http::assertNothingSent();
    expect(User::where('receivable_reconciliation_required', true)->count())->toBe(150);
    config(['queue.default' => 'sync']);
    expect(fn () => Artisan::call('app:receivables-audit'))->toThrow(LogicException::class);
});

it('passes only a completed full user scan to the revision guarded reconciliation service', function (): void {
    $scan = ['user_id' => 17, 'revision' => '4', 'valid' => false];
    $snapshot = Mockery::mock(ReceivableSnapshot::class);
    $snapshot->shouldReceive('read')->with(17, 90)->once()->andReturn($scan);
    $reconciliation = Mockery::mock(ReceivableReconciliation::class);
    $reconciliation->shouldReceive('flagIfUnchanged')->with($scan)->once();
    $job = new AuditReceivableEvidenceJob(17);
    $job->handle($snapshot, $reconciliation);
    expect($job->timeout)->toBe(120)->and($job->tries)->toBe(1)->and($job->queue)->toBe('receivable-check');
});

it('preserves incomplete audit failure and never sends it to the financial flag writer', function (): void {
    $snapshot = Mockery::mock(ReceivableSnapshot::class);
    $snapshot->shouldReceive('read')->with(17, 90)->once()->andThrow(new RuntimeException('incomplete scan'));
    $reconciliation = Mockery::mock(ReceivableReconciliation::class);
    $reconciliation->shouldNotReceive('flagIfUnchanged');
    expect(fn () => (new AuditReceivableEvidenceJob(17))->handle($snapshot, $reconciliation))->toThrow(RuntimeException::class);
});
