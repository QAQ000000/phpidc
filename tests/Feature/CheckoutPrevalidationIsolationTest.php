<?php

declare(strict_types=1);

use App\Actions\ValidateCheckoutAction;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('validates checkout fields inside an existing caller transaction without entering the financial lock sequence', function (): void {
    Http::preventStrayRequests();
    Queue::fake();
    $owner = User::factory()->create(['credit' => '100.00', 'frozen_credit' => '5.00']);
    $product = Product::factory()->create(['provisioning_rules' => ['hostname_mode' => 'custom',
        'hostname_regex' => '^[a-z0-9-]{4,16}$', 'password_min_len' => 12]]);
    $input = ['hostname' => 'node-one', 'password' => 'StrongPass12'];
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = [$query->sql, $query->connection->transactionLevel()];
    });

    DB::transaction(function () use ($owner, $product, $input, &$queries): void {
        $level = DB::transactionLevel();
        expect(app(ValidateCheckoutAction::class)->execute($owner, $product->id, $input))->toBe($input)
            ->and(DB::transactionLevel())->toBe($level);
        expect($queries)->toHaveCount(1)
            ->and($queries[0][0])->toContain('products')->not->toContain('users')
            ->and($queries[0][1])->toBe($level);
    });

    expect($owner->fresh()->credit)->toBe('100.00')->and($owner->fresh()->frozen_credit)->toBe('5.00');
    foreach (['invoices', 'financial_transactions', 'payment_attempts', 'instances', 'fulfillment_tasks'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});
