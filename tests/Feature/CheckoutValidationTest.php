<?php

declare(strict_types=1);

use App\Actions\ValidateCheckoutAction;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $storage = sys_get_temp_dir().'/laravel13idc-checkout-'.Str::uuid();
    File::ensureDirectoryExists($storage);
    $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($storage));
    $this->app->useStoragePath($storage);
    file_put_contents(storage_path('installed.lock'), 'isolated checkout test');
});

it('blocks checkout before wallet deduction when hostname or password violates provisioning_rules', function (): void {
    $user = User::factory()->create(['credit' => '100.00']);
    $product = Product::factory()->create([
        'provisioning_rules' => [
            'hostname_mode' => 'custom',
            'hostname_regex' => '^[a-z0-9-]{4,16}$',
        ],
    ]);

    $this->actingAs($user)
        ->post('/checkout', ['request_id' => (string) Str::ulid(),
            'product_id' => $product->id,
            'hostname' => 'INVALID_HOST!@#',
        ])
        ->assertSessionHasErrors('hostname');

    expect($user->fresh()->credit)->toBe('100.00');
});

it('rejects invalid provisioning inputs without attempting financial writes or external work', function (array $input, array $errors): void {
    Http::fake();
    Queue::fake();
    $user = User::factory()->create(['credit' => '100.00', 'frozen_credit' => '5.00']);
    $product = Product::factory()->create([
        'provisioning_rules' => [
            'hostname_mode' => 'custom',
            'hostname_regex' => '^[a-z0-9/-]{4,16}$',
            'password_min_len' => 12,
            'password_max_len' => 20,
            'password_regex' => '^(?=.*[A-Z])(?=.*[0-9])[A-Za-z0-9/]+$',
        ],
    ]);
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $this->actingAs($user)
        ->postJson('/checkout', ['request_id' => (string) Str::ulid(), 'product_id' => $product->id, ...$input])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);

    expect($writes)->toBe([])
        ->and($user->fresh()->credit)->toBe('100.00')
        ->and($user->fresh()->frozen_credit)->toBe('5.00');
    foreach (['invoices', 'financial_transactions', 'payment_attempts', 'instances', 'fulfillment_tasks'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with([
    'hostname format' => [['hostname' => 'INVALID_HOST!', 'password' => 'StrongPass1/'], ['hostname']],
    'custom hostname missing' => [['password' => 'StrongPass1/'], ['hostname']],
    'hostname is an array' => [['hostname' => ['node'], 'password' => 'StrongPass1/'], ['hostname']],
    'password below minimum' => [['hostname' => 'node/one', 'password' => 'Aa123/'], ['password']],
    'password above maximum' => [['hostname' => 'node/one', 'password' => 'StrongPassword123456/'], ['password']],
    'password format' => [['hostname' => 'node/one', 'password' => 'lowercase123'], ['password']],
    'password missing' => [['hostname' => 'node/one'], ['password']],
    'password is an array' => [['hostname' => 'node/one', 'password' => ['StrongPass1/']], ['password']],
    'both fields invalid' => [['hostname' => 'BAD!', 'password' => 'bad'], ['hostname', 'password']],
    'client cannot replace product rules' => [[
        'hostname' => 'INVALID_HOST!',
        'password' => 'StrongPass1/',
        'provisioning_rules' => ['hostname_regex' => '.*'],
    ], ['hostname']],
]);

it('accepts product-specific patterns containing slashes and inclusive password length boundaries', function (string $hostname, string $password): void {
    $user = User::factory()->create(['credit' => '100.00']);
    $product = Product::factory()->create([
        'provisioning_rules' => [
            'hostname_mode' => 'custom',
            'hostname_regex' => '^[a-z0-9/-]{4,16}$',
            'password_min_len' => 12,
            'password_max_len' => 20,
            'password_regex' => '^(?=.*[A-Z])(?=.*[0-9])[A-Za-z0-9/]+$',
        ],
    ]);
    $input = ['hostname' => $hostname, 'password' => $password];

    expect(app(ValidateCheckoutAction::class)->execute($user, $product->id, $input))->toBe($input)
        ->and($user->fresh()->credit)->toBe('100.00');
})->with([
    'minimum password length' => ['node/one', 'StrongPass1/'],
    'maximum password length' => ['node/two', 'StrongPassword12345/'],
]);

it('allows an upstream-generated hostname and products without provisioning constraints', function (): void {
    $user = User::factory()->create();
    $auto = Product::factory()->create([
        'provisioning_rules' => [
            'hostname_mode' => 'upstream_auto',
            'hostname_prefix' => 'ecs-',
            'hostname_regex' => '^[a-z0-9-]{4,32}$',
            'password_min_len' => 12,
        ],
    ]);
    $unrestricted = Product::factory()->create(['provisioning_rules' => null]);
    $validate = app(ValidateCheckoutAction::class);

    expect($validate->execute($user, $auto->id, ['password' => 'StrongPass1/']))
        ->toBe(['password' => 'StrongPass1/'])
        ->and($validate->execute($user, $unrestricted->id, []))->toBe([]);
});

it('validates product inputs without reading the wallet owner or opening a transaction', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'provisioning_rules' => ['hostname_mode' => 'custom'],
    ]);
    $initialLevel = DB::transactionLevel();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = [$query->sql, $query->connection->transactionLevel()];
    });

    expect(fn () => app(ValidateCheckoutAction::class)->execute($user, $product->id, []))
        ->toThrow(ValidationException::class);

    expect($queries)->toHaveCount(1)
        ->and($queries[0][0])->toContain('products')->not->toContain('users')
        ->and($queries[0][1])->toBe($initialLevel)
        ->and(DB::transactionLevel())->toBe($initialLevel);
});

it('keeps checkout behind installation and authentication', function (): void {
    $this->postJson('/checkout', [])->assertUnauthorized();

    File::delete(storage_path('installed.lock'));
    $this->post('/checkout', [])->assertRedirect('/install');
});
