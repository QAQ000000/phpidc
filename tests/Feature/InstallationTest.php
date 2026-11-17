<?php

declare(strict_types=1);

use App\Actions\InstallApplicationAction;
use App\Actions\PrepareInstallationAction;
use App\Models\User;
use App\Services\DeploymentRequirements;
use App\Support\InstallationState;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->originalStorage = storage_path();
    $this->originalConnection = config('database.default');
    $this->temporaryInstallation = sys_get_temp_dir().'/cloud-install-'.Str::uuid();
    foreach (['framework/views', 'framework/sessions', 'framework/cache/data', 'logs'] as $path) {
        File::ensureDirectoryExists($this->temporaryInstallation.'/'.$path);
    }
    touch($this->temporaryInstallation.'/database.sqlite');
    $this->app->useStoragePath($this->temporaryInstallation);
    config(['view.compiled' => storage_path('framework/views'), 'session.driver' => 'array', 'cache.default' => 'array',
        'queue.default' => 'database', 'database.default' => 'installation',
        'database.connections.installation' => ['driver' => 'sqlite', 'database' => storage_path('database.sqlite'), 'foreign_key_constraints' => true]]);
    $this->installationInput = ['install_token' => app(PrepareInstallationAction::class)->execute(),
        'admin_name' => 'Initial administrator', 'admin_email' => 'initial@example.test',
        'admin_password' => ' Isolated-Install-123! ', 'admin_password_confirmation' => ' Isolated-Install-123! ', 'reason' => 'Isolated first install'];
});

afterEach(function (): void {
    DB::purge('installation');
    config(['database.default' => $this->originalConnection]);
    $this->app->useStoragePath($this->originalStorage);
    File::deleteDirectory($this->temporaryInstallation);
});

it('installs an empty configured database with one audited access manager and closes the installer', function (): void {
    $this->get('/install')->assertOk()->assertSee('安装令牌')->assertDontSee($this->installationInput['install_token']);
    $this->post('/install', $this->installationInput)->assertRedirect('/admin/login');
    $admin = User::sole();
    expect(Hash::check($this->installationInput['admin_password'], $admin->password))->toBeTrue()
        ->and($admin->getRoleNames()->all())->toBe(['access-manager'])
        ->and($admin->can('finance.refund'))->toBeFalse()
        ->and($admin->can('finance.payout'))->toBeFalse();
    $this->assertDatabaseHas('audit_logs', ['action' => 'installation.bootstrap', 'actor_type' => 'system', 'reason' => 'Isolated first install']);
    app(InstallationState::class)->assertKey(requireSeal: true);
    expect(is_file(storage_path('installation.token')))->toBeFalse()
        ->and(DB::table('migrations')->count())->toBe(count(File::files(database_path('migrations'))));
    $this->get('/install')->assertNotFound();
    $this->post('/install', $this->installationInput)->assertNotFound();
    $this->get('/up')->assertOk();
});

it('rejects missing invalid or expired installation tokens before database access', function (string $kind): void {
    if ($kind === 'expired') {
        $this->travel(61)->minutes();
    } else {
        $this->installationInput['install_token'] = $kind === 'missing' ? null : 'incorrect';
    }
    DB::listen(fn () => throw new RuntimeException('Unauthorized installation touched database'));
    $this->post('/install', $this->installationInput)->assertSessionHasErrors('install');
    expect(is_file(storage_path('installed.lock')))->toBeFalse();
})->with(['missing', 'invalid', 'expired']);

it('never flashes installation credentials when validation fails', function (): void {
    $this->installationInput['admin_password_confirmation'] = 'Mismatch';
    $this->post('/install', $this->installationInput)->assertSessionHasErrors('admin_password');
    expect(session()->getOldInput())->not->toHaveKeys(['install_token', 'admin_password', 'admin_password_confirmation']);
    expect(DB::connection()->getSchemaBuilder()->getTableListing())->toBe([]);
});

it('refuses an existing database without an installation progress manifest', function (): void {
    DB::statement('create table unrelated_data (id integer primary key, value text)');
    DB::table('unrelated_data')->insert(['id' => 1, 'value' => 'preserve']);
    $this->post('/install', $this->installationInput)->assertSessionHasErrors('install');
    expect(DB::table('unrelated_data')->value('value'))->toBe('preserve')
        ->and(is_file(storage_path('installed.lock')))->toBeFalse()
        ->and(is_file(storage_path('installation.progress')))->toBeFalse();
});

it('resumes after database commit without recreating the admin or resetting its password', function (): void {
    $state = Mockery::mock(InstallationState::class)->makePartial();
    $state->shouldReceive('write')->with('installed.lock', Mockery::type('array'))->once()->andThrow(new RuntimeException('Simulated lock publication failure'));
    $this->app->instance(InstallationState::class, $state);
    expect(fn () => app(InstallApplicationAction::class)->execute($this->installationInput))->toThrow(RuntimeException::class, 'Simulated lock');
    expect(User::count())->toBe(1)->and(is_file(storage_path('installed.lock')))->toBeFalse();
    $this->app->instance(InstallationState::class, new InstallationState);
    $retry = array_replace($this->installationInput, ['admin_password' => 'Different-Password-123!', 'admin_password_confirmation' => 'Different-Password-123!']);
    app(InstallApplicationAction::class)->execute($retry);
    expect(User::count())->toBe(1)
        ->and(Hash::check($this->installationInput['admin_password'], User::sole()->password))->toBeTrue()
        ->and(DB::table('audit_logs')->where('action', 'installation.bootstrap')->count())->toBe(1);
});

it('serializes installation attempts and refuses a token reset while installation is running', function (): void {
    $handle = fopen(storage_path('installation.mutex'), 'c');
    flock($handle, LOCK_EX);
    try {
        $this->post('/install', $this->installationInput)->assertSessionHasErrors('install');
        expect(fn () => app(PrepareInstallationAction::class)->execute())->toThrow(DomainException::class);
        expect(DB::connection()->getSchemaBuilder()->getTableListing())->toBe([]);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
});

it('does not contact database or Redis to render the preinstall requirements page', function (): void {
    DB::listen(fn () => throw new RuntimeException('Requirements page touched database'));
    $this->get('/install')->assertOk();
});

it('rejects a production configuration using SQLite or insecure infrastructure before migration', function (): void {
    expect(fn () => app(DeploymentRequirements::class)->assertInstallable(production: true))->toThrow(DomainException::class, '生产环境');
    expect(DB::connection()->getSchemaBuilder()->getTableListing())->toBe([]);
});

it('rejects unsupported MySQL versions before migration', function (string $version): void {
    $connection = Mockery::mock(MySqlConnection::class);
    $connection->shouldReceive('getDriverName')->andReturn('mysql');
    $connection->shouldReceive('selectOne')->with('select version() as version')->andReturn((object) ['version' => $version]);
    $manager = DB::getFacadeRoot();
    DB::shouldReceive('connection')->andReturn($connection);
    try {
        expect(fn () => app(DeploymentRequirements::class)->assertInstallable())->toThrow(DomainException::class, 'MySQL 8.0+');
    } finally {
        DB::swap($manager);
    }
})->with(['5.7.43', '10.11.9-MariaDB']);
