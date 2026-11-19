<?php

declare(strict_types=1);

use App\Actions\RecordBindingOrphanCaseAction;
use App\Models\Instance;
use App\Models\InstanceUpstreamBinding;
use App\Models\Product;
use App\Models\ReconciliationEntry;
use App\Models\RedemptionCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    config(['database.connections.delivery_history_upgrade' => [
        ...config('database.connections.sqlite'), 'database' => ':memory:', 'foreign_key_constraints' => true,
    ]]);
    $this->previousConnection = DB::getDefaultConnection();
    DB::setDefaultConnection('delivery_history_upgrade');
    Schema::clearResolvedInstance('db.schema');
    Http::preventStrayRequests();
    Queue::fake();
    $legacy = collect(glob(database_path('migrations/*.php')))->filter(fn (string $path): bool => basename($path) < '2026_11_19_000018')->sort();
    expect($legacy)->toHaveCount(23);
    foreach ($legacy as $path) {
        (require $path)->up();
    }
});

afterEach(function (): void {
    DB::purge('delivery_history_upgrade');
    DB::setDefaultConnection($this->previousConnection);
    Schema::clearResolvedInstance('db.schema');
});

function deliveryHistoryUpgradeMigration(): Migration
{
    return require database_path('migrations/2026_11_19_000018_restrict_delivery_history_deletions.php');
}

function deliveryHistoryLegacyFixture(): array
{
    $products = [];
    foreach (['available', 'reserved', 'sold'] as $status) {
        $product = Product::factory()->create();
        $instance = $status === 'available' ? null : Instance::factory()->create(['product_id' => $product->id]);
        RedemptionCode::factory()->create(['product_id' => $product->id, 'status' => $status,
            'instance_id' => $instance?->id, 'code' => 'retained-'.$status,
            'reserved_until' => $status === 'reserved' ? now()->addMinute() : null, 'sold_at' => $status === 'sold' ? now() : null]);
        $products[] = $product;
    }
    $instances = [];
    foreach ([false, true] as $hasCase) {
        $instance = Instance::factory()->create();
        $binding = InstanceUpstreamBinding::create(['instance_id' => $instance->id, 'driver_type' => $instance->driver_type,
            'upstream_instance_id' => 'retained-host-'.$instance->id, 'bound_at' => now()->subDay(), 'unbound_at' => now(),
            'unbind_reason' => 'hot_swap', 'upstream_disposition' => 'kept_orphan',
            'source_snapshot' => ['base_url' => 'https://retained.example.test', 'original_resource' => $instance->id]]);
        if ($hasCase) {
            DB::transaction(fn () => app(RecordBindingOrphanCaseAction::class)->execute($binding));
        }
        $instances[] = $instance;
    }

    return [$products, $instances];
}

function deliveryHistoryRows(): array
{
    $result = [];
    foreach (['users', 'products', 'instances', 'redemption_codes', 'instance_upstream_bindings', 'reconciliation_cases', 'reconciliation_entries'] as $table) {
        $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    return $result;
}

function deliveryHistoryDeleteRule(string $table, string $column): string
{
    return strtolower(collect(Schema::getForeignKeys($table))->where('columns', [$column])->sole()['on_delete']);
}

it('upgrades genuine legacy delivery rows and preserves ciphertext and reconciliation references', function (): void {
    [$products, $instances] = deliveryHistoryLegacyFixture();
    $before = deliveryHistoryRows();
    $columns = $indexes = [];
    foreach (['redemption_codes', 'instance_upstream_bindings'] as $table) {
        $columns[$table] = Schema::getColumns($table);
        $indexes[$table] = Schema::getIndexes($table);
    }
    deliveryHistoryUpgradeMigration()->up();
    expect(deliveryHistoryRows())->toBe($before)
        ->and(deliveryHistoryDeleteRule('redemption_codes', 'product_id'))->toBe('restrict')
        ->and(deliveryHistoryDeleteRule('instance_upstream_bindings', 'instance_id'))->toBe('restrict')
        ->and(DB::select('PRAGMA foreign_key_check'))->toBe([])
        ->and((int) DB::scalar('PRAGMA foreign_keys'))->toBe(1)
        ->and(ReconciliationEntry::sole()->evidence['version'])->toBe(1);
    foreach ($columns as $table => $expected) {
        expect(Schema::getColumns($table))->toBe($expected)->and(Schema::getIndexes($table))->toBe($indexes[$table]);
    }
    expect(RedemptionCode::orderBy('id')->get()->pluck('code')->all())->toBe(['retained-available', 'retained-reserved', 'retained-sold']);
    foreach ($products as $product) {
        expect(fn () => $product->delete())->toThrow(QueryException::class);
    }
    foreach ($instances as $instance) {
        expect(fn () => $instance->delete())->toThrow(QueryException::class);
    }
    deliveryHistoryUpgradeMigration()->up();
    expect(deliveryHistoryRows())->toBe($before);
});

it('resumes after one delivery table was already upgraded without rebuilding its original rows again', function (): void {
    deliveryHistoryLegacyFixture();
    Schema::table('redemption_codes', function (Blueprint $table): void {
        $table->dropForeign(['product_id']);
        $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
    });
    $before = deliveryHistoryRows();
    deliveryHistoryUpgradeMigration()->up();
    expect(deliveryHistoryRows())->toBe($before)
        ->and(deliveryHistoryDeleteRule('redemption_codes', 'product_id'))->toBe('restrict')
        ->and(deliveryHistoryDeleteRule('instance_upstream_bindings', 'instance_id'))->toBe('restrict');
});

it('preserves the SQLite identity sequence after high historical delivery IDs were deleted', function (): void {
    deliveryHistoryLegacyFixture();
    foreach (['redemption_codes', 'instance_upstream_bindings'] as $table) {
        $row = (array) DB::table($table)->first();
        $row['id'] = 1000;
        if ($table === 'redemption_codes') {
            $row['code_hash'] = hash('sha256', 'deleted-high-card-id');
        }
        DB::table($table)->insert($row);
        DB::table($table)->where('id', 1000)->delete();
        expect((int) DB::table('sqlite_sequence')->where('name', $table)->value('seq'))->toBe(1000);
    }
    $before = deliveryHistoryRows();
    deliveryHistoryUpgradeMigration()->up();
    foreach (['redemption_codes', 'instance_upstream_bindings'] as $table) {
        expect((int) DB::table('sqlite_sequence')->where('name', $table)->value('seq'))->toBe(1000);
    }
    expect(deliveryHistoryRows())->toBe($before);
});

it('refuses a SQLite table rebuild inside a caller transaction before changing either constraint', function (): void {
    deliveryHistoryLegacyFixture();
    $before = deliveryHistoryRows();
    DB::beginTransaction();
    try {
        expect(fn () => deliveryHistoryUpgradeMigration()->up())->toThrow(LogicException::class, 'independent migration transaction');
        expect(deliveryHistoryDeleteRule('redemption_codes', 'product_id'))->toBe('cascade')
            ->and(deliveryHistoryDeleteRule('instance_upstream_bindings', 'instance_id'))->toBe('cascade')
            ->and((int) DB::scalar('PRAGMA foreign_keys'))->toBe(1);
    } finally {
        DB::rollBack();
    }
    expect(deliveryHistoryRows())->toBe($before);
});

it('rolls back both SQLite rebuilds and restores enforcement when old rows contain an orphan', function (): void {
    deliveryHistoryLegacyFixture();
    Schema::disableForeignKeyConstraints();
    DB::table('instance_upstream_bindings')->where('id', 1)->update(['instance_id' => 999999]);
    Schema::enableForeignKeyConstraints();
    $before = deliveryHistoryRows();
    expect(fn () => deliveryHistoryUpgradeMigration()->up())->toThrow(LogicException::class, 'integrity check failed');
    expect(deliveryHistoryRows())->toBe($before)
        ->and(deliveryHistoryDeleteRule('redemption_codes', 'product_id'))->toBe('cascade')
        ->and(deliveryHistoryDeleteRule('instance_upstream_bindings', 'instance_id'))->toBe('cascade')
        ->and((int) DB::scalar('PRAGMA foreign_keys'))->toBe(1);
});
