<?php

declare(strict_types=1);

use App\Actions\DispatchStalePendingTasksAction;
use App\Actions\ExpireUnpaidInvoicesAction;
use App\Actions\ReapRedemptionReservationsAction;
use App\Contracts\MaintenanceBatchWork;
use App\Exceptions\NotificationExpansionFailed;
use App\Jobs\RunMaintenanceBatchJob;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Support\FairMaintenanceBatch;
use App\Support\MaintenanceBudget;
use App\Support\MaintenanceLockWait;
use App\Support\MaintenanceState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config(['maintenance.cache_store' => 'array']);
});

function maintenanceInvoices(int $count): void
{
    $owner = User::factory()->create();
    $product = Product::factory()->create();
    Invoice::factory()->count($count)->create(['user_id' => $owner->id, 'product_id' => $product->id]);
}

it('limits a batch to one hundred candidates and preserves both oldest and rotation opportunities', function (): void {
    maintenanceInvoices(160);
    $consumed = [];
    $result = app(FairMaintenanceBatch::class)->execute('invoices', Invoice::query(), 'id', function (int $id) use (&$consumed): void {
        $consumed[] = $id;
    });
    expect($result['selected'])->toBe(100)->and($result['attempted'])->toBe(100)
        ->and($result['failed'])->toBe(0)->and($result['budget_exhausted'])->toBeFalse()
        ->and($consumed[0])->toBe(51)->and($consumed[1])->toBe(1)->and(count(array_unique($consumed)))->toBe(100);
    $next = [];
    app(FairMaintenanceBatch::class)->execute('invoices', Invoice::query(), 'id', function (int $id) use (&$next): void {
        $next[] = $id;
    });
    expect($next[0])->toBe(101)->and($next)->toContain(150);
});

it('advances past one hundred poisoned low ids without losing their visible bounded retry state', function (): void {
    maintenanceInvoices(160);
    $good = [];
    $consumer = function (int $id) use (&$good): void {
        if ($id <= 100) {
            throw new DomainException('PRIVATE-DB-OR-RECIPIENT-DETAILS');
        }
        $good[] = $id;
    };
    $first = app(FairMaintenanceBatch::class)->execute('invoices', Invoice::query(), 'id', $consumer);
    $second = app(FairMaintenanceBatch::class)->execute('invoices', Invoice::query(), 'id', $consumer);
    $failures = app(MaintenanceState::class)->failures('invoices');
    expect($first['failed'])->toBe(100)->and($second['deferred'])->toBe(50)->and($second['succeeded'])->toBe(50)
        ->and($good)->toContain(101, 150)->and($failures)->toHaveCount(100)
        ->and(json_encode($failures, JSON_THROW_ON_ERROR))->not->toContain('PRIVATE-')
        ->and($failures[0])->toHaveKeys(['kind', 'id', 'code', 'retry_at', 'attempts']);
});

it('wraps its cursor and finds an old id that became eligible later', function (): void {
    maintenanceInvoices(110);
    $batch = app(FairMaintenanceBatch::class);
    $state = app(MaintenanceState::class);
    $state->advance('invoices', 99999);
    $seen = [];
    $batch->execute('invoices', Invoice::where('id', '>=', 60), 'id', function (int $id) use (&$seen): void {
        $seen[] = $id;
    });
    expect($seen)->toContain(60, 110)->and($state->cursor('invoices'))->toBe(110);
    $state->store()->forget('maintenance:cursor:invoices');
    $seen = [];
    $batch->execute('invoices', Invoice::query(), 'id', function (int $id) use (&$seen): void {
        $seen[] = $id;
    });
    expect($seen)->toContain(1, 51);
});

it('stops on the soft time budget after finishing the current item', function (): void {
    maintenanceInvoices(120);
    $time = 0.0;
    $seen = [];
    $result = app(FairMaintenanceBatch::class)->execute('invoices', Invoice::query(), 'id', function (int $id) use (&$time, &$seen): void {
        $seen[] = $id;
        $time += 2.0;
    }, function () use (&$time): float {
        return $time;
    });
    expect($seen)->toBe([51, 1, 52])->and($result['attempted'])->toBe(3)->and($result['budget_exhausted'])->toBeTrue()
        ->and(app(MaintenanceState::class)->cursor('invoices'))->toBe(52);
});

it('charges scanned recipient units against one budget shared across four notification phases', function (): void {
    maintenanceInvoices(120);
    $budget = new MaintenanceBudget;
    $results = [];
    foreach (['expand', 'recover', 'dispatch', 'incidents'] as $phase) {
        $results[] = app(FairMaintenanceBatch::class)->execute('notifications-'.$phase, Invoice::query(), 'id',
            fn (int $id, int $quota): int => $quota, budget: $budget, priorityLimit: 12, rotationLimit: 13);
    }
    expect(array_sum(array_column($results, 'units')))->toBe(100)->and($budget->used())->toBe(100)
        ->and($budget->available())->toBeFalse();
    foreach ($results as $result) {
        expect($result['units'])->toBe(25)->and($result['attempted'])->toBe(3);
    }
});

it('charges a partially committed failed recipient page before allowing another notification phase', function (): void {
    maintenanceInvoices(120);
    $budget = new MaintenanceBudget;
    $first = app(FairMaintenanceBatch::class)->execute('notifications-expand', Invoice::query(), 'id',
        fn (int $id, int $quota) => throw new NotificationExpansionFailed($quota, new RuntimeException('page failure')),
        budget: $budget, priorityLimit: 12, rotationLimit: 13);
    $later = app(FairMaintenanceBatch::class)->execute('notifications-dispatch', Invoice::query(), 'id',
        fn (int $id, int $quota): int => $quota, budget: $budget, priorityLimit: 12, rotationLimit: 13);
    expect($first['units'])->toBe(25)->and($first['failed'])->toBe(3)
        ->and($later['units'])->toBe(25)->and($budget->used())->toBe(50);
});

it('shares the deadline across phases and resumes a later phase without resetting its time allowance', function (): void {
    maintenanceInvoices(100);
    $time = 0.0;
    $budget = new MaintenanceBudget(function () use (&$time): float {
        return $time;
    });
    $first = app(FairMaintenanceBatch::class)->execute('notifications-expand', Invoice::query(), 'id',
        function () use (&$time): void {
            $time += 2.0;
        }, budget: $budget, priorityLimit: 12, rotationLimit: 13);
    $later = app(FairMaintenanceBatch::class)->execute('notifications-dispatch', Invoice::query(), 'id',
        fn () => throw new RuntimeException('Expired phase must not run'), budget: $budget, priorityLimit: 12, rotationLimit: 13);
    expect($first['attempted'])->toBe(3)->and($first['budget_exhausted'])->toBeTrue()
        ->and($later['selected'])->toBe(0)->and($later['attempted'])->toBe(0)->and($budget->used())->toBe(3);
});

it('keeps affiliate accrual and maturity budgets and cursors independent', function (): void {
    maintenanceInvoices(120);
    $state = app(MaintenanceState::class);
    $state->advance('affiliate-accrue', 100);
    $accrue = $mature = [];
    app(FairMaintenanceBatch::class)->execute('affiliate-accrue', Invoice::query(), 'id', function (int $id) use (&$accrue): void {
        $accrue[] = $id;
    });
    app(FairMaintenanceBatch::class)->execute('affiliate-mature', Invoice::query(), 'id', function (int $id) use (&$mature): void {
        $mature[] = $id;
    });
    expect($accrue[0])->toBe(101)->and($mature[0])->toBe(51);
});

it('keeps poison retry backoff separate from business execution attempts and removes recovered failures', function (): void {
    $state = app(MaintenanceState::class);
    $first = $state->failed('fulfillment', 24, new DomainException('private'));
    expect($first['retry_at'] - $first['failed_at'])->toBe(60)->and($state->retryDue('fulfillment', 24))->toBeFalse();
    $this->travel(61)->seconds();
    expect($state->retryDue('fulfillment', 24))->toBeTrue();
    $second = $state->failed('fulfillment', 24, new DomainException('private'));
    expect($second['attempts'])->toBe(2)->and($second['retry_at'] - $second['failed_at'])->toBe(300);
    $state->succeeded('fulfillment', 24);
    expect($state->failures('fulfillment'))->toBe([])->and($state->retryDue('fulfillment', 24))->toBeTrue();
});

it('restores the database lock wait after successful work and after an exception', function (): void {
    DB::statement('PRAGMA busy_timeout = 3210');
    app(MaintenanceLockWait::class)->execute(function (): void {
        expect((int) DB::selectOne('PRAGMA busy_timeout')->timeout)->toBe(2000);
    });
    expect((int) DB::selectOne('PRAGMA busy_timeout')->timeout)->toBe(3210);
    expect(fn () => app(MaintenanceLockWait::class)->execute(fn () => throw new RuntimeException('test interruption')))->toThrow(RuntimeException::class);
    expect((int) DB::selectOne('PRAGMA busy_timeout')->timeout)->toBe(3210);
});

it('stores independent dispatch start and completion heartbeats without replacing prior phases', function (): void {
    $state = app(MaintenanceState::class);
    $state->heartbeat('cards', 'dispatched');
    $state->heartbeat('cards', 'started');
    $state->heartbeat('cards', 'completed', ['attempted' => 12]);
    expect($state->snapshot('cards'))->toHaveKeys(['dispatched_at', 'started_at', 'completed_at'])
        ->and($state->snapshot('cards')['attempted'])->toBe(12)->and($state->snapshot('invoices'))->toBe([]);
});

it('enforces maintenance execution exclusion independently from scheduler and queued uniqueness', function (): void {
    $handler = new class implements MaintenanceBatchWork
    {
        public int $calls = 0;

        public function execute(string $kind): array
        {
            $this->calls++;

            return ['attempted' => 4];
        }
    };
    $job = new RunMaintenanceBatchJob('invoices');
    $held = Cache::store('array')->lock('maintenance:execution:invoices', 30);
    expect($held->get())->toBeTrue();
    $job->handle($handler, app(MaintenanceState::class), app(MaintenanceLockWait::class));
    expect($handler->calls)->toBe(0);
    $held->release();
    $job->handle($handler, app(MaintenanceState::class), app(MaintenanceLockWait::class));
    expect($handler->calls)->toBe(1)->and(app(MaintenanceState::class)->snapshot('invoices')['attempted'])->toBe(4)
        ->and($job->timeout)->toBe(25)->and($job->tries)->toBe(1)->and($job->uniqueFor)->toBe(180)->and($job->queue)->toBe('maintenance');
});

it('releases execution exclusion and preserves failure heartbeat when a batch crashes', function (): void {
    $handler = new class implements MaintenanceBatchWork
    {
        public function execute(string $kind): array
        {
            throw new RuntimeException('PRIVATE-NETWORK-OR-SQL-DETAILS');
        }
    };
    $job = new RunMaintenanceBatchJob('cards');
    expect(fn () => $job->handle($handler, app(MaintenanceState::class), app(MaintenanceLockWait::class)))->toThrow(RuntimeException::class);
    $lock = Cache::store('array')->lock('maintenance:execution:cards', 30);
    expect($lock->get())->toBeTrue();
    $lock->release();
    $state = app(MaintenanceState::class)->snapshot('cards');
    expect($state['error_code'])->toBe('batch_failed')->and($state)->not->toHaveKey('completed_at')
        ->and(json_encode($state, JSON_THROW_ON_ERROR))->not->toContain('PRIVATE-');
});

it('keeps legacy recovery command names and uses their real maintenance option to dispatch bounded jobs', function (): void {
    config(['queue.default' => 'database']);
    Queue::fake();
    $this->mock(ExpireUnpaidInvoicesAction::class)->shouldNotReceive('execute');
    $this->mock(DispatchStalePendingTasksAction::class)->shouldNotReceive('execute');
    $this->mock(ReapRedemptionReservationsAction::class)->shouldNotReceive('execute');
    foreach (['invoices:expire' => 'invoices', 'fulfillment:dispatch-stale' => 'fulfillment', 'cards:reap-reservations' => 'cards'] as $command => $kind) {
        expect(Artisan::call($command, ['--maintenance' => true]))->toBe(0);
        Queue::assertPushed(RunMaintenanceBatchJob::class, fn (RunMaintenanceBatchJob $job): bool => $job->kind === $kind);
    }
    Queue::assertPushed(RunMaintenanceBatchJob::class, 3);
});

it('dispatches affiliate phases separately and rejects a synchronous maintenance queue', function (): void {
    Queue::fake();
    $this->app->bind(MaintenanceBatchWork::class, fn () => new class implements MaintenanceBatchWork
    {
        public function execute(string $kind): array
        {
            return [];
        }
    });
    config(['queue.default' => 'database']);
    expect(Artisan::call('maintenance:dispatch', ['kind' => 'affiliate']))->toBe(0);
    Queue::assertPushed(RunMaintenanceBatchJob::class, fn (RunMaintenanceBatchJob $job): bool => $job->kind === 'affiliate-accrue');
    Queue::assertPushed(RunMaintenanceBatchJob::class, fn (RunMaintenanceBatchJob $job): bool => $job->kind === 'affiliate-mature');
    Queue::assertPushed(RunMaintenanceBatchJob::class, 2);
    config(['queue.default' => 'sync']);
    expect(fn () => Artisan::call('maintenance:dispatch', ['kind' => 'affiliate']))->toThrow(LogicException::class);
});
