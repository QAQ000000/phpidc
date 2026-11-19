<?php

declare(strict_types=1);

use App\Actions\GenerateProductProvisioningDefaultsAction;
use App\Models\Product;
use App\Support\ProvisioningValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

function generatedProvisioningRules(array $local = [], array $host = [], array $password = []): array
{
    return $local + ['classic_catalog' => [
        'host' => $host ?: ['show' => '0', 'prefix' => 'ser', 'rule' => ['upper' => '0', 'lower' => '0', 'num' => '1', 'len_num' => 12]],
        'password' => $password ?: ['show' => '1', 'rule' => ['upper' => '1', 'lower' => '1', 'num' => '1', 'special' => '0', 'len_num' => '12']],
    ]];
}

function generatedProvisioningDefaults(array $rules): array
{
    return app(GenerateProductProvisioningDefaultsAction::class)->execute(new Product(['provisioning_rules' => $rules]));
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('generates the classic preview product hostname and password from JSON snapshots without I/O or mutations', function (): void {
    $rules = generatedProvisioningRules();
    foreach (['host', 'password'] as $field) {
        $rules['classic_catalog'][$field] = json_encode($rules['classic_catalog'][$field]);
    }
    $product = new Product(['provisioning_rules' => $rules]);
    $original = $product->getAttributes();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $hostnames = [];
    $passwords = [];
    for ($sample = 0; $sample < 20; $sample++) {
        $defaults = app(GenerateProductProvisioningDefaultsAction::class)->execute($product);
        expect($defaults['errors'])->toBe([])->and($defaults['hostname'])->toMatch('/^ser[0-9]{12}$/')
            ->and(strlen($defaults['password']))->toBe(12)->and($defaults['password'])->toMatch('/^[A-Za-z0-9]+$/')
            ->toMatch('/[A-Z]/')->toMatch('/[a-z]/')->toMatch('/[0-9]/');
        $hostnames[] = $defaults['hostname'];
        $passwords[] = $defaults['password'];
    }
    expect(array_unique($hostnames))->toHaveCount(20)->and(array_unique($passwords))->toHaveCount(20)
        ->and($queries)->toBe([])->and($product->getAttributes())->toBe($original);
    Http::assertNothingSent();
});

it('generates the upstream default for an explicitly empty classic hostname rule without changing the snapshot', function (): void {
    $rules = generatedProvisioningRules();
    $rules['classic_catalog']['host'] = '';
    $product = new Product(['provisioning_rules' => $rules]);
    $original = $product->getAttributes();
    $defaults = app(GenerateProductProvisioningDefaultsAction::class)->execute($product);

    expect($defaults['errors'])->toBe([])->and($defaults['hostname'])->toMatch('/^S[A-Za-z0-9]{12}$/')
        ->and(strlen($defaults['password']))->toBe(12)->and($product->getAttributes())->toBe($original);
    Http::assertNothingSent();
});

it('honors local hostname constraints when the classic hostname rule is explicitly empty', function (): void {
    $rules = generatedProvisioningRules(['hostname_prefix' => 'node-', 'hostname_regex' => '^node-[0-9]{5}$']);
    $rules['classic_catalog']['host'] = '';
    $product = new Product(['provisioning_rules' => $rules]);
    $defaults = app(GenerateProductProvisioningDefaultsAction::class)->execute($product);

    expect($defaults['errors'])->toBe([])->and($defaults['hostname'])->toMatch('/^node-[0-9]{5}$/');
    $input = ['hostname' => $defaults['hostname'], 'password' => $defaults['password']];
    expect(app(ProvisioningValidator::class)->validate($product, $input))->toBe($input);
    Http::assertNothingSent();
});

it('keeps unreadable classic hostname rules invalid when the password rule is valid', function (mixed $host): void {
    $rules = generatedProvisioningRules();
    $rules['classic_catalog']['host'] = $host;
    $defaults = generatedProvisioningDefaults($rules);

    expect($defaults['hostname'])->toBeNull()->and(array_keys($defaults['errors']))->toBe(['hostname'])
        ->and(strlen($defaults['password']))->toBe(12);
})->with([[null], ['{']]);

it('satisfies both local prefixes formats and the upstream suffix length and character rules', function (): void {
    $rules = generatedProvisioningRules([
        'hostname_mode' => 'custom', 'hostname_prefix' => 'vm-ecs-', 'hostname_regex' => '^vm-ecs-[A-Za-z0-9]{8}$',
        'password_min_len' => 18, 'password_max_len' => 18,
        'password_regex' => '^(?=.*[A-Z])(?=.*[a-z])(?=.*[0-9])[A-Za-z0-9/]+$',
    ], ['show' => 1, 'prefix' => 'vm-', 'rule' => ['upper' => 1, 'lower' => 1, 'num' => 1, 'len_num' => 12]]);
    $product = new Product(['provisioning_rules' => $rules]);
    $defaults = app(GenerateProductProvisioningDefaultsAction::class)->execute($product);
    expect($defaults['errors'])->toBe([])->and($defaults['hostname'])->toMatch('/^vm-ecs-[A-Za-z0-9]{8}$/')
        ->and(strlen($defaults['hostname']))->toBe(15)->and(strlen($defaults['password']))->toBe(18);
    $input = ['hostname' => $defaults['hostname'], 'password' => $defaults['password']];
    expect(app(ProvisioningValidator::class)->validate($product, $input))->toBe($input);
});

it('includes every required upstream password character category including special characters', function (): void {
    $rules = generatedProvisioningRules([], [], ['show' => 1, 'rule' => ['upper' => 1, 'lower' => 1, 'num' => 1, 'special' => 1, 'len_num' => 20]]);
    $defaults = generatedProvisioningDefaults($rules);
    expect($defaults['errors'])->toBe([])->and(strlen($defaults['password']))->toBe(20)
        ->and($defaults['password'])->toMatch('/[A-Z]/')->toMatch('/[a-z]/')->toMatch('/[0-9]/')->toMatch('/[~@#$&*(){}\[\]|]/');
});

it('uses the classic twelve character default when configured lengths are empty or zero', function (mixed $length): void {
    $rules = generatedProvisioningRules();
    $rules['classic_catalog']['host']['rule']['len_num'] = $length;
    $rules['classic_catalog']['password']['rule']['len_num'] = $length;
    $defaults = generatedProvisioningDefaults($rules);
    expect($defaults['errors'])->toBe([])->and($defaults['hostname'])->toMatch('/^ser[0-9]{12}$/')
        ->and(strlen($defaults['password']))->toBe(12);
})->with([[0], ['0'], [null], ['']]);

it('honors the local hostname prefix and inclusive password bounds without a classic snapshot', function (): void {
    $rules = ['hostname_mode' => 'custom', 'hostname_prefix' => 'ecs-', 'hostname_regex' => '^[a-z0-9-]{4,16}$',
        'password_min_len' => 12, 'password_max_len' => 20,
        'password_regex' => '^(?=.*[A-Z])(?=.*[0-9])[A-Za-z0-9/]+$'];
    $product = new Product(['provisioning_rules' => $rules]);
    $defaults = app(GenerateProductProvisioningDefaultsAction::class)->execute($product);
    expect($defaults['errors'])->toBe([])->and($defaults['hostname'])->toStartWith('ecs-')->toMatch('/^[a-z0-9-]{4,16}$/')
        ->and(strlen($defaults['password']))->toBeGreaterThanOrEqual(12)->toBeLessThanOrEqual(20);
    $input = ['hostname' => $defaults['hostname'], 'password' => $defaults['password']];
    expect(app(ProvisioningValidator::class)->validate($product, $input))->toBe($input);
});

it('can choose a numeric candidate matching a local format without inventing a regex generator', function (): void {
    $defaults = generatedProvisioningDefaults(['hostname_prefix' => 'node-', 'hostname_regex' => '^node-[0-9]{5}$',
        'password_min_len' => 12, 'password_max_len' => 12, 'password_regex' => '^[0-9]{12}$']);
    expect($defaults['errors'])->toBe([])->and($defaults['hostname'])->toMatch('/^node-[0-9]{5}$/')
        ->and($defaults['password'])->toMatch('/^[0-9]{12}$/');
});

it('returns a clear field error rather than relaxing contradictory local and upstream rules', function (array $local, string $field): void {
    $defaults = generatedProvisioningDefaults(generatedProvisioningRules($local));
    expect($defaults[$field])->toBeNull()->and($defaults['errors'])->toHaveKey($field)
        ->and($defaults['errors'][$field])->toBeString()->not->toBe('');
    $other = $field === 'password' ? 'hostname' : 'password';
    expect($defaults[$other])->toBeString()->not->toBe('')->and($defaults['errors'])->not->toHaveKey($other);
})->with([
    'prefix conflict' => [['hostname_prefix' => 'ecs-'], 'hostname'],
    'hostname format conflict' => [['hostname_regex' => '^unrelated-fixed-hostname$'], 'hostname'],
    'password length conflict' => [['password_min_len' => 21, 'password_max_len' => 32], 'password'],
    'password format conflict' => [['password_regex' => '^[a-z]{12}$'], 'password'],
    'invalid hostname regex' => [['hostname_regex' => '['], 'hostname'],
    'invalid password regex' => [['password_regex' => '['], 'password'],
]);

it('reports unreadable classic snapshots instead of claiming defaults satisfy unknown rules', function (mixed $snapshot): void {
    $defaults = generatedProvisioningDefaults(['classic_catalog' => $snapshot]);
    expect($defaults['hostname'])->toBeNull()->and($defaults['password'])->toBeNull()
        ->and(array_keys($defaults['errors']))->toBe(['hostname', 'password']);
})->with([[null], [[]], ['bad json'], [['host' => '{', 'password' => '{']]]);

it('does not use incomplete visible upstream rules or an empty password character pool', function (array $password): void {
    $defaults = generatedProvisioningDefaults(generatedProvisioningRules([], [], $password));
    expect($defaults['hostname'])->toMatch('/^ser[0-9]{12}$/')->and($defaults['password'])->toBeNull()
        ->and($defaults['errors'])->toHaveKey('password');
})->with([
    [['show' => 1]],
    [['show' => 1, 'rule' => ['upper' => 1, 'len_num' => 12]]],
    [['show' => 1, 'rule' => ['upper' => 0, 'lower' => 0, 'num' => 0, 'special' => 0, 'len_num' => 12]]],
]);

it('provides nonempty defaults when fields have no declared provisioning constraints', function (): void {
    $defaults = generatedProvisioningDefaults([]);
    expect($defaults['errors'])->toBe([])->and($defaults['hostname'])->toMatch('/^ser[a-z0-9]{12}$/')
        ->and(strlen($defaults['password']))->toBe(16);
    $hidden = generatedProvisioningDefaults(['classic_catalog' => ['host' => ['show' => 0], 'password' => ['show' => 0]]]);
    expect($hidden['errors'])->toBe([])->and($hidden['hostname'])->toBeString()->not->toBe('')
        ->and($hidden['password'])->toBeString()->not->toBe('');
});

it('keeps missing required checkout fields invalid because generation never changes validation semantics', function (): void {
    $product = new Product(['provisioning_rules' => ['hostname_mode' => 'custom', 'password_min_len' => 12]]);
    expect(app(GenerateProductProvisioningDefaultsAction::class)->execute($product)['errors'])->toBe([])
        ->and(fn () => app(ProvisioningValidator::class)->validate($product, []))->toThrow(ValidationException::class);
});
