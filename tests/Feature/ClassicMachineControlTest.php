<?php

declare(strict_types=1);

use App\Actions\ExecuteCustomerPowerCommandAction;
use App\Enums\DriverType;
use App\Enums\InstanceStatus;
use App\Enums\ProvisionCommand;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Support\DirectVncConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(fn () => $this->installApplicationForTest());

function machineControlInstance(): Instance
{
    $api = UpstreamApi::factory()->create();
    $product = Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $api->id, 'upstream_pid' => 14]);

    return Instance::factory()->create(['product_id' => $product->id, 'driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => $api->id, 'upstream_instance_id' => '4253', 'status' => InstanceStatus::ACTIVE])->fresh();
}

function machineControlHeader(): array
{
    return ['status' => 200, 'data' => ['host_data' => ['id' => 4253, 'domainstatus' => 'Active',
        'password_rule' => ['show' => '1', 'modify' => 0, 'rule' => ['len_num' => '12', 'upper' => '1', 'lower' => '1', 'num' => '1', 'special' => '0']]],
        'module_button' => ['control' => array_map(fn ($func) => ['type' => 'default', 'func' => $func],
            ['on', 'off', 'reboot', 'hard_off', 'hard_reboot', 'reinstall', 'rescue_system']),
            'console' => [['type' => 'default', 'func' => 'vnc']]],
        'cloud_os_group' => [['id' => 'Ubuntu', 'name' => 'Ubuntu'], ['id' => 'Debian', 'name' => 'Debian']],
        'cloud_os' => [['id' => 1173028, 'group' => 'Ubuntu', 'name' => 'Ubuntu-24.04-x64'], ['id' => 1173023, 'group' => 'Debian', 'name' => 'Debian-12.0_x64']],
        'reinstall_random_port' => true, 'reinstall_format_data_disk' => true]];
}

function machineControlInput(array $extra = []): array
{
    return $extra + ['request_id' => (string) Str::ulid(), 'reason' => 'Customer explicitly requested this test operation.', 'confirm' => 1, 'backup_confirm' => 1];
}

function fakeMachineControl(?Closure $override = null): void
{
    $level = DB::transactionLevel();
    Http::preventStrayRequests();
    Http::fake(function ($request) use ($override, $level) {
        expect(DB::transactionLevel())->toBe($level);
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($override !== null && ($response = $override($path, $request)) !== null) {
            return $response;
        }

        return Http::response(match ($path) {
            '/zjmf_api_login' => ['status' => 200, 'jwt' => 'PRIVATE-MACHINE-JWT'],
            '/host/header' => machineControlHeader(),
            '/provision/default' => $request['func'] === 'vnc' ? ['status' => 200, 'data' => ['url' => 'https://supplier.test/dcim/novnc?'.http_build_query([
                'url' => base64_encode('wss://console.test/cloud_ws1?token=PRIVATE-VNC-TOKEN'), 'password' => 'PRIVATE-VNC-PASS', 'host_token' => 'PRIVATE-HOST-TOKEN']), 'pass' => 'PRIVATE-VNC-PASS', 'zjmfcloud_out_vnc' => false]] : ['status' => 200, 'msg' => 'Accepted'],
            default => throw new RuntimeException('Unexpected machine control request: '.$path),
        });
    });
}

it('shows only advertised machine controls and grouped OS versions to the service owner', function (): void {
    $instance = machineControlInstance();
    fakeMachineControl();
    $this->actingAs($instance->user)->post('/console/instances/'.$instance->id.'/machine')->assertOk()
        ->assertSee('开机')->assertSee('硬重启')->assertSee('重装系统')->assertSee('救援系统')->assertSee('打开 VNC 控制台')
        ->assertSee('name="os_group"', false)->assertSee('name="os"', false)->assertSee('Ubuntu-24.04-x64')->assertSee('Debian-12.0_x64')
        ->assertSee('backup_confirm')->assertSee('format_data_disk')->assertSee('name="port"', false);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/provision/default'));
});

it('reads machine controls without waiting for advertised power telemetry charts or panel login', function (): void {
    $instance = machineControlInstance();
    fakeMachineControl(fn ($path) => $path === '/host/header' ? Http::response(array_replace_recursive(machineControlHeader(), ['data' => [
        'module_power_status' => true,
        'module_chart' => array_map(fn ($type) => ['type' => $type, 'title' => $type, 'select' => []], ['cpu', 'disk', 'memory', 'flow']),
        'module_client_area' => [['key' => 'information']],
    ]])) : null);

    $this->actingAs($instance->user)->post('/console/instances/'.$instance->id.'/machine')->assertOk()
        ->assertSee('开机')->assertSee('关机')->assertSee('重启')->assertSee('硬关机')->assertSee('硬重启')
        ->assertSee('重装系统')->assertSee('救援系统')->assertSee('打开 VNC 控制台')
        ->assertSee('Ubuntu-24.04-x64')->assertSee('Debian-12.0_x64')->assertSee('backup_confirm');

    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => parse_url($r->url(), PHP_URL_PATH) === '/host/header' && $r['host_id'] === '4253');
    Http::assertNotSent(fn ($r) => ! in_array(parse_url($r->url(), PHP_URL_PATH), ['/zjmf_api_login', '/host/header'], true));
    expect(DB::table('fulfillment_tasks')->count())->toBe(0)
        ->and(DB::table('financial_transactions')->count())->toBe(0)
        ->and($instance->fresh()->active_config)->toBe($instance->active_config);
});

it('discards machine controls when the owner status or host binding changes during the header read', function (string $field): void {
    $instance = machineControlInstance();
    $changed = match ($field) {
        'user_id' => User::factory()->create()->id,
        'status' => InstanceStatus::SUSPENDED,
        'upstream_instance_id' => '9999',
    };
    fakeMachineControl(function ($path) use ($instance, $field, $changed) {
        if ($path === '/host/header') {
            $instance->fresh()->update([$field => $changed]);

            return Http::response(machineControlHeader());
        }

        return null;
    });

    $this->actingAs($instance->user)->post('/console/instances/'.$instance->id.'/machine')
        ->assertSessionHasErrors('panel')->assertDontSee('Ubuntu-24.04-x64');
    Http::assertSentCount(2);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/provision/default'));
})->with(['user_id', 'status', 'upstream_instance_id']);

it('submits each advertised power command once with durable audit and no billing mutation', function (ProvisionCommand $command): void {
    $instance = machineControlInstance();
    $before = $instance->getRawOriginal();
    $wallet = $instance->user->credit;
    fakeMachineControl();
    $input = machineControlInput();
    $result = app(ExecuteCustomerPowerCommandAction::class)->execute($instance->user, $instance, $command, $input);
    expect($result->success)->toBeTrue()->and($instance->fresh()->status)->toBe(InstanceStatus::ACTIVE)
        ->and($instance->user->fresh()->credit)->toBe($wallet)->and(DB::table('invoices')->count())->toBe(0)
        ->and(DB::table('financial_transactions')->count())->toBe(0)->and(DB::table('fulfillment_tasks')->count())->toBe(0);
    expect($instance->fresh()->only(['primary_ip', 'encrypted_credentials', 'recurring_amount']))->toBe($instance->only(['primary_ip', 'encrypted_credentials', 'recurring_amount']));
    expect(DB::table('audit_logs')->where('action', 'instance.machine.request.'.$input['request_id'])->value('reason'))->toBe($input['reason']);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/provision/default') && $r['func'] === $command->value && $r['id'] === '4253' && $r['is_api'] === 1);
    expect(fn () => app(ExecuteCustomerPowerCommandAction::class)->execute($instance->user, $instance->fresh(), $command, $input))->toThrow(ValidationException::class);
    Http::assertSentCount(3);
})->with([ProvisionCommand::POWER_ON, ProvisionCommand::POWER_OFF, ProvisionCommand::REBOOT, ProvisionCommand::HARD_OFF, ProvisionCommand::HARD_REBOOT]);

it('validates the current OS pairing and sends only the verified reinstall fields', function (): void {
    $instance = machineControlInstance();
    fakeMachineControl();
    $result = app(ExecuteCustomerPowerCommandAction::class)->execute($instance->user, $instance, ProvisionCommand::REINSTALL,
        machineControlInput(['os_group' => 'Ubuntu', 'os' => 1173028, 'port' => 22, 'format_data_disk' => 1, 'func' => 'terminate']));
    expect($result->success)->toBeTrue();
    Http::assertSent(fn ($r) => str_contains($r->url(), '/provision/default') && $r['func'] === 'reinstall'
        && $r['os'] === 1173028 && $r['port'] === 22 && $r['format_data_disk'] === 1 && ! isset($r['os_group']));
    expect($instance->fresh()->encrypted_credentials)->toBe($instance->encrypted_credentials);
});

it('rejects invalid OS pairings or unsupported reinstall fields before submitting the command', function (array $extra): void {
    $instance = machineControlInstance();
    fakeMachineControl(fn ($path) => $path === '/host/header' ? Http::response(array_replace_recursive(machineControlHeader(), ['data' => ['reinstall_random_port' => false, 'reinstall_format_data_disk' => false]])) : null);
    expect(fn () => app(ExecuteCustomerPowerCommandAction::class)->execute($instance->user, $instance, ProvisionCommand::REINSTALL, machineControlInput($extra)))->toThrow(ValidationException::class);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/provision/default'));
    expect($instance->fresh()->active_config['cloud_operation']['status'])->toBe('rejected');
})->with([
    [['os_group' => 'Debian', 'os' => 1173028]],
    [['os_group' => 'Ubuntu', 'os' => 99]],
    [['os_group' => 'Ubuntu', 'os' => 1173028, 'port' => 2222]],
    [['os_group' => 'Ubuntu', 'os' => 1173028, 'format_data_disk' => 1]],
]);

it('requires the command-specific interruption or destructive backup confirmation before any HTTP', function (ProvisionCommand $command, array $extra): void {
    $instance = machineControlInstance();
    fakeMachineControl();
    expect(fn () => app(ExecuteCustomerPowerCommandAction::class)->execute($instance->user, $instance, $command, machineControlInput($extra)))->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->with([[ProvisionCommand::REBOOT, ['confirm' => 0]], [ProvisionCommand::REINSTALL, ['backup_confirm' => 0]], [ProvisionCommand::RESCUE_SYSTEM, ['backup_confirm' => 0]]]);

it('uses the classic rescue wire spelling and redacts the temporary password', function (): void {
    $instance = machineControlInstance();
    fakeMachineControl();
    $result = app(ExecuteCustomerPowerCommandAction::class)->execute($instance->user, $instance, ProvisionCommand::RESCUE_SYSTEM,
        machineControlInput(['system' => 2, 'temp_pass' => 'PrivateRescue1!']));
    expect($result->success)->toBeTrue();
    Http::assertSent(fn ($r) => str_contains($r->url(), '/provision/default') && $r['func'] === 'rescue_system' && $r['system'] === 2 && $r['temp_pass'] === 'PrivateRescue1!');
    expect(DB::table('upstream_operations')->get()->toJson().DB::table('audit_logs')->get()->toJson().$instance->fresh()->toJson())->not->toContain('PrivateRescue1!');
});

it('rejects rescue passwords violating upstream or local rules without flashing them', function (): void {
    $instance = machineControlInstance();
    $instance->product->update(['provisioning_rules' => ['password_regex' => '^MustStart/']]);
    fakeMachineControl();
    $this->actingAs($instance->user)->post('/console/instances/'.$instance->id.'/machine/command',
        machineControlInput(['command' => 'rescueSystem', 'system' => 2, 'temp_pass' => 'PrivateRescue1!']))->assertSessionHasErrors('temp_pass')->assertSessionMissing('_old_input');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/provision/default'));
});

it('rejects another user guests and nonactive instances without sending HTTP', function (): void {
    $instance = machineControlInstance();
    fakeMachineControl();
    $path = '/console/instances/'.$instance->id;
    $this->post($path.'/machine')->assertRedirect('/login');
    $this->actingAs(User::factory()->create())->post($path.'/machine')->assertForbidden();
    $this->post($path.'/machine/command', machineControlInput(['command' => 'on']))->assertForbidden();
    $instance->update(['status' => InstanceStatus::SUSPENDED]);
    $this->actingAs($instance->user)->post($path.'/machine/command', machineControlInput(['command' => 'on']))->assertSessionHasErrors('command');
    Http::assertNothingSent();
});

it('blocks stale or unadvertised commands including customer suspension and password reset', function (string $command): void {
    $instance = machineControlInstance();
    fakeMachineControl(fn ($path) => $path === '/host/header' ? Http::response(['status' => 200, 'data' => ['host_data' => ['domainstatus' => 'Active']]]) : null);
    $this->actingAs($instance->user)->post('/console/instances/'.$instance->id.'/machine/command', machineControlInput(['command' => $command]))->assertSessionHasErrors('command');
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/provision/default'));
})->with(['off', 'suspend', 'crack_pass']);

it('blocks an existing fulfillment task and an interrupted durable command', function (): void {
    $instance = machineControlInstance();
    fakeMachineControl();
    FulfillmentTask::factory()->create(['instance_id' => $instance->id, 'status' => 'running']);
    expect(fn () => app(ExecuteCustomerPowerCommandAction::class)->execute($instance->user, $instance, ProvisionCommand::REBOOT, machineControlInput()))->toThrow(ValidationException::class);
    FulfillmentTask::query()->delete();
    $instance->update(['active_config' => ['cloud_operation' => ['status' => 'requested']]]);
    expect(fn () => app(ExecuteCustomerPowerCommandAction::class)->execute($instance->user, $instance, ProvisionCommand::REBOOT, machineControlInput()))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

it('keeps an ambiguous command for manual reconciliation and never automatically replays it', function (): void {
    $instance = machineControlInstance();
    fakeMachineControl(function ($path) {
        if ($path === '/provision/default') {
            throw new ConnectionException('Lost response');
        }

        return null;
    });
    $result = app(ExecuteCustomerPowerCommandAction::class)->execute($instance->user, $instance, ProvisionCommand::REBOOT, machineControlInput());
    expect($result->success)->toBeFalse()->and($result->retryable)->toBeTrue()->and($instance->fresh()->active_config['cloud_operation']['status'])->toBe('unknown');
    expect(fn () => app(ExecuteCustomerPowerCommandAction::class)->execute($instance->user, $instance->fresh(), ProvisionCommand::REBOOT, machineControlInput()))->toThrow(ValidationException::class);
    expect(DB::table('upstream_operations')->where('path', '/provision/default')->value('outcome'))->toBe('ambiguous');
});

it('revalidates a binding changed during the authenticated header read', function (): void {
    $instance = machineControlInstance();
    fakeMachineControl(function ($path) use ($instance) {
        if ($path === '/host/header') {
            $instance->fresh()->update(['upstream_instance_id' => '9999']);

            return Http::response(machineControlHeader());
        }

        return null;
    });
    expect(fn () => app(ExecuteCustomerPowerCommandAction::class)->execute($instance->user, $instance, ProvisionCommand::REBOOT, machineControlInput()))->toThrow(ValidationException::class);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/provision/default'));
    expect(DB::table('audit_logs')->where('action', 'like', 'instance.machine.result.%')->count())->toBe(1);
});

it('opens a signed local B64 fragment page with direct RFB assets and no server proxy', function (): void {
    $instance = machineControlInstance();
    fakeMachineControl();
    $response = $this->actingAs($instance->user)->post('/console/instances/'.$instance->id.'/machine/command', machineControlInput(['command' => 'vnc']));
    $response->assertRedirect();
    $location = $response->headers->get('Location');
    [$page, $fragment] = explode('#', $location, 2);
    expect(parse_url($page, PHP_URL_PATH))->toBe('/console/instances/'.$instance->id.'/vnc');
    expect($page)->not->toContain('PRIVATE-', 'console.test');
    expect(json_decode(base64_decode($fragment), true))->toBe(['url' => 'wss://console.test/cloud_ws1?token=PRIVATE-VNC-TOKEN', 'password' => 'PRIVATE-VNC-PASS']);
    $this->get($page)->assertOk()->assertSee('/js/direct-vnc.js')->assertHeader('Referrer-Policy', 'no-referrer')->assertDontSee('PRIVATE-');
    Http::assertSentCount(3);
    $logs = DB::table('upstream_operations')->get()->toJson().DB::table('audit_logs')->get()->toJson().$instance->fresh()->toJson();
    expect($logs)->not->toContain('PRIVATE-VNC-', 'PRIVATE-HOST-TOKEN', base64_encode('wss://console.test/cloud_ws1?token=PRIVATE-VNC-TOKEN'));
    $this->actingAs(User::factory()->create())->get($page)->assertForbidden();
    $this->actingAs($instance->user)->get(str_replace('fingerprint=', 'fingerprint=0', $page))->assertForbidden();
    $instance->update(['upstream_instance_id' => '9999']);
    $this->get($page)->assertForbidden();
});

it('refuses unsupported console redirects and insecure or malformed socket addresses', function (string $url): void {
    expect(fn () => DirectVncConnection::fromClassic(['data' => ['url' => $url]]))->toThrow(ValidationException::class);
})->with([
    'https://supplier.test/external-console?url='.base64_encode('wss://console.test/ws'),
    'https://supplier.test/dcim/novnc?url='.base64_encode('ws://console.test/ws'),
    'https://supplier.test/dcim/novnc?url=invalid',
    'javascript:alert(1)',
]);

it('opens VNC or powers on with only the stable request and an automatic audit description', function (ProvisionCommand $command, string $reason): void {
    $instance = machineControlInstance();
    $wallet = $instance->user->credit;
    fakeMachineControl();
    $requestId = (string) Str::ulid();
    $result = app(ExecuteCustomerPowerCommandAction::class)->execute($instance->user, $instance, $command, ['request_id' => $requestId]);
    expect($result->success)->toBeTrue()->and($instance->user->fresh()->credit)->toBe($wallet);
    $this->assertDatabaseHas('audit_logs', ['action' => 'instance.machine.request.'.$requestId, 'actor_id' => $instance->user_id, 'reason' => $reason]);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('invoices', 0);
    expect(fn () => app(ExecuteCustomerPowerCommandAction::class)->execute($instance->user, $instance->fresh(), $command, ['request_id' => $requestId]))->toThrow(ValidationException::class);
    Http::assertSentCount(3);
})->with([[ProvisionCommand::POWER_ON, '客户开机'], [ProvisionCommand::VNC, '客户打开 VNC 控制台']]);

it('uses only the backup confirmation for destructive machine commands while retaining their current input validation', function (ProvisionCommand $command, array $parameters): void {
    $instance = machineControlInstance();
    fakeMachineControl();
    $requestId = (string) Str::ulid();
    $result = app(ExecuteCustomerPowerCommandAction::class)->execute($instance->user, $instance, $command,
        ['request_id' => $requestId, 'backup_confirm' => 1] + $parameters);
    expect($result->success)->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'instance.machine.request.'.$requestId,
        'reason' => $command === ProvisionCommand::REINSTALL ? '客户确认备份并重装系统' : '客户确认备份并进入救援系统']);
    $this->assertDatabaseCount('financial_transactions', 0);
})->with([[ProvisionCommand::REINSTALL, ['os_group' => 'Ubuntu', 'os' => 1173028]],
    [ProvisionCommand::RESCUE_SYSTEM, ['system' => 2, 'temp_pass' => 'PrivateRescue1!']]]);

it('preserves an optional zero remark on a confirmed interruption instead of substituting the default', function (): void {
    $instance = machineControlInstance();
    fakeMachineControl();
    $requestId = (string) Str::ulid();
    $result = app(ExecuteCustomerPowerCommandAction::class)->execute($instance->user, $instance, ProvisionCommand::REBOOT,
        ['request_id' => $requestId, 'confirm' => 1, 'reason' => ' 0 ']);
    expect($result->success)->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'instance.machine.request.'.$requestId, 'reason' => '0']);
});

it('renders direct VNC and power-on buttons and a single command-specific confirmation for each interruption', function (): void {
    $instance = machineControlInstance();
    fakeMachineControl();
    $response = $this->actingAs($instance->user)->post('/console/instances/'.$instance->id.'/machine')->assertOk();
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $xpath = new DOMXPath($dom);
    foreach (['on', 'vnc', 'off', 'reboot', 'reinstall', 'rescueSystem'] as $command) {
        $form = $xpath->query('//form[input[@name="command" and @value="'.$command.'"]]')->item(0);
        expect($form)->not->toBeNull();
        expect($xpath->query('.//input[@name="reason" and @required]', $form)->length)->toBe(0);
        expect($xpath->query('.//input[@type="checkbox" and @required]', $form)->length)->toBe(in_array($command, ['on', 'vnc'], true) ? 0 : 1);
        expect($xpath->query('.//input[@name="request_id"]', $form)->length)->toBe(1);
        expect($xpath->query('.//input[@name="_token"]', $form)->length)->toBe(1);
    }
});
