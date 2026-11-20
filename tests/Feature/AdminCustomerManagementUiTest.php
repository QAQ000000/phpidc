<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\KycVerification;
use App\Models\User;
use App\Services\AdminCustomerQueries;
use App\Services\AdminGlobalSearch;
use App\Support\AdminRoles;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Http::preventStrayRequests();
});

afterEach(function (): void {
    Http::assertNothingSent();
});

function customerManagementUiActor(string ...$permissions): User
{
    $actor = User::factory()->create();
    foreach ($permissions as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $actor;
}

function customerManagementUiXpath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

    return new DOMXPath($document);
}

function customerManagementUiForm(DOMXPath $xpath, int $customerId, string $action): DOMElement
{
    $form = $xpath->query('//main//form[@action="/admin/customers/'.$customerId.'/'.$action.'"]')->item(0);
    expect($form)->toBeInstanceOf(DOMElement::class);

    return $form;
}

function customerManagementUiValue(DOMXPath $xpath, DOMElement $form, string $name): string
{
    $field = $xpath->query('.//input[@name="'.$name.'"]', $form)->item(0);
    expect($field)->toBeInstanceOf(DOMElement::class);

    return $field->getAttribute('value');
}

it('adds exactly the approved customer operator and keeps financial readonly users unchanged', function (): void {
    $viewer = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($viewer, 'customer-viewer', 'Isolated C2 viewer');
    $operator = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($operator, 'customer-operator', 'Isolated C2 operator');
    expect(AdminRoles::permissions()['customer-operator'])->toBe(['customers.view', 'customers.manage', 'customers.password-reset', 'customers.status.manage'])
        ->and(AdminRoles::labels()['customer-operator'])->toBe('客户基础管理')
        ->and(AdminRoles::presets()['operations']['roles'])->toContain('customer-operator')->not->toContain('customer-viewer')
        ->and(AdminRoles::presets()['finance_readonly']['roles'])->toContain('customer-viewer')->not->toContain('customer-operator')
        ->and(AdminRoles::presets()['all_business']['roles'])->toContain('customer-operator')
        ->and($viewer->fresh()->getAllPermissions()->pluck('name')->all())->toBe(['customers.view'])
        ->and($operator->getAllPermissions()->pluck('name')->all())->toEqualCanonicalizing(AdminRoles::permissions()['customer-operator']);
});

it('renders only the specifically authorized customer dialog with CSRF and optional notes', function (string $permission, array $actions): void {
    $actor = customerManagementUiActor('customers.view', ...($permission === '' ? [] : [$permission]));
    $owner = User::factory()->create();
    $response = $this->actingAs($actor)->get('/admin/customers/'.$owner->id)->assertOk();
    $xpath = customerManagementUiXpath($response->getContent());
    expect($xpath->query('//main//form[@method="post"]'))->toHaveCount(count($actions));
    foreach ($actions as $action => $marker) {
        $form = customerManagementUiForm($xpath, $owner->id, $action);
        expect($xpath->query('ancestor::dialog[@aria-labelledby]', $form))->toHaveCount(1)
            ->and(customerManagementUiValue($xpath, $form, '_admin_form'))->toBe($marker)
            ->and(customerManagementUiValue($xpath, $form, 'customer_id'))->toBe((string) $owner->id)
            ->and($xpath->query('.//input[@name="_token"]', $form))->toHaveCount(1)
            ->and($xpath->query('.//input[@name="reason"][@maxlength="255"][not(@required)]', $form))->toHaveCount(1)
            ->and($xpath->query('.//input[@type="password" or @name="token" or @name="recipient" or @name="auth_revision"]', $form))->toHaveCount(0);
    }
    if (array_key_exists('profile', $actions)) {
        $form = customerManagementUiForm($xpath, $owner->id, 'profile');
        foreach (['name', 'email', 'phone'] as $name) {
            expect(customerManagementUiValue($xpath, $form, $name))->toBe((string) $owner->getAttribute($name));
        }
    }
})->with([
    'viewer' => ['', []],
    'profile only' => ['customers.manage', ['profile' => 'customer-profile']],
    'reset only' => ['customers.password-reset', ['password-reset' => 'customer-password-reset']],
    'status only' => ['customers.status.manage', ['access' => 'customer-access']],
]);

it('does not inspect target roles or direct permissions for a customer-only viewer', function (): void {
    $actor = customerManagementUiActor('customers.view');
    $owner = User::factory()->create();
    $actor->getAllPermissions();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    $data = app(AdminCustomerQueries::class)->detail($actor, $owner->id, []);
    expect($data['management'])->toBe(['profile' => false, 'password_reset' => false, 'access' => false]);
    foreach (['model_has_roles', 'model_has_permissions'] as $table) {
        expect(collect($queries)->contains(fn (string $sql): bool => str_contains($sql, $table)))->toBeFalse();
    }
});

it('hides every management form for self closed and backend identities', function (string $kind): void {
    $actor = customerManagementUiActor('customers.view', 'customers.manage', 'customers.password-reset', 'customers.status.manage');
    $owner = $kind === 'self' ? $actor : User::factory()->create();
    if ($kind === 'role') {
        app(AssignAdminRoleAction::class)->execute($owner, 'customer-viewer', 'Isolated protected role');
    } elseif ($kind === 'direct permission') {
        $owner->givePermissionTo(Permission::findOrCreate('support.view', 'web'));
    } elseif ($kind === 'closed') {
        $owner->forceFill(['anonymized_at' => now(), 'deleted_at' => now()])->save();
    }
    $response = $this->actingAs($actor)->get('/admin/customers/'.$owner->id)->assertOk();
    expect(customerManagementUiXpath($response->getContent())->query('//main//form[@method="post"]'))->toHaveCount(0);
    $response->assertDontSee('编辑资料')->assertDontSee('发送重置链接')->assertDontSee('停用账户访问');
})->with(['self', 'role', 'direct permission', 'closed']);

it('shows disabled customers as a separate state and offers restore without a reset form', function (): void {
    $actor = customerManagementUiActor('customers.view', 'customers.manage', 'customers.password-reset', 'customers.status.manage');
    $owner = User::factory()->create(['disabled_at' => now()]);
    $closed = User::factory()->create(['disabled_at' => now(), 'anonymized_at' => now(), 'deleted_at' => now()]);
    $query = app(AdminCustomerQueries::class);
    expect($query->listing($actor, ['user_id' => $owner->id])['customers']->total())->toBe(0)
        ->and($query->listing($actor, ['status' => 'disabled'])['customers']->pluck('id')->all())->toBe([$owner->id])
        ->and($query->listing($actor, ['status' => 'closed'])['customers']->pluck('id')->all())->toBe([$closed->id]);
    $response = $this->actingAs($actor)->get('/admin/customers/'.$owner->id)->assertOk()->assertSee('已停用')->assertSee('恢复账户访问')
        ->assertSee('已付款履约及自动生命周期继续按原业务处理')->assertDontSee('发送重置链接');
    $xpath = customerManagementUiXpath($response->getContent());
    expect($xpath->query('//main//form[@method="post"]'))->toHaveCount(2);
    expect(customerManagementUiValue($xpath, customerManagementUiForm($xpath, $owner->id, 'access'), 'disabled'))->toBe('0');
    $this->get('/admin/customers?status=disabled')->assertOk()->assertSee('已停用')->assertSee('value="disabled" selected', false);
});

it('adds disabled status to customer search without projecting the authentication revision', function (): void {
    $actor = customerManagementUiActor('customers.view');
    $owner = User::factory()->create(['disabled_at' => now(), 'auth_revision' => 71]);
    $groups = app(AdminGlobalSearch::class)->search($actor, (string) $owner->id);
    expect($groups)->toHaveCount(1)->and($groups[0]['items'][0]['description'])->toBe('客户 #'.$owner->id.' · 已停用')
        ->and(array_keys($groups[0]['items'][0]))->toBe(['id', 'label', 'description', 'url']);
    $data = app(AdminCustomerQueries::class)->detail($actor, $owner->id, []);
    expect($data['customer']->getAttributes())->toHaveKey('disabled_at')->not->toHaveKey('auth_revision');
});

it('uses ordinary URL links for exactly the permitted customer tabs and renders only the selected panel', function (array $permissions, array $tabs): void {
    $actor = customerManagementUiActor('customers.view', ...$permissions);
    $owner = User::factory()->create();
    $xpath = customerManagementUiXpath($this->actingAs($actor)->get('/admin/customers/'.$owner->id)->assertOk()->getContent());
    $anchors = $xpath->query('//main//nav[@aria-label="客户详情页签"]//a');
    expect($anchors)->toHaveCount(count($tabs));
    foreach ($anchors as $index => $anchor) {
        expect($anchor->getAttribute('href'))->toBe('/admin/customers/'.$owner->id.'?tab='.$tabs[$index])
            ->and($anchor->hasAttribute('onclick'))->toBeFalse()->and($anchor->hasAttribute('x-on:click'))->toBeFalse()
            ->and($anchor->getAttribute('aria-current'))->toBe($tabs[$index] === 'overview' ? 'page' : '');
    }
    expect($xpath->query('//main//section[@id="customer-profile"]'))->toHaveCount(1)
        ->and($xpath->query('//main//section[@id="customer-services" or @id="customer-invoices" or @id="customer-ledger" or @id="customer-tickets" or @id="customer-kyc" or @id="customer-notifications" or @id="customer-audit"]'))->toHaveCount(0)
        ->and($xpath->query('//main//*[@role="tab" or @role="tablist"]'))->toHaveCount(0);
})->with([
    'customer only' => [[], ['overview', 'identity']],
    'all view domains' => [['finance.view', 'upstream.view', 'support.view', 'notification.view', 'audit.view'], ['overview', 'services', 'invoices', 'ledger', 'tickets', 'identity', 'notifications', 'audit']],
]);

it('returns each selected detail paginator to its own tab and section without unrelated page parameters', function (): void {
    $actor = customerManagementUiActor('customers.view', 'finance.view', 'upstream.view', 'support.view', 'notification.view', 'audit.view');
    $owner = User::factory()->create();
    Instance::factory()->count(11)->active()->create(['user_id' => $owner->id]);
    Invoice::factory()->count(11)->create(['user_id' => $owner->id]);
    for ($index = 0; $index < 11; $index++) {
        KycVerification::create(['user_id' => $owner->id, 'driver' => 'idcsmartali', 'status' => 'failed', 'masked_summary' => ['name' => '*明', 'id_last4' => '012X']]);
    }
    foreach (['services' => ['services', 'services'], 'invoices' => ['invoices', 'invoices'], 'ledger' => ['ledger', 'ledger'], 'tickets' => ['tickets', 'tickets'], 'identity' => ['kycRecords', 'kyc'], 'notifications' => ['notifications', 'notifications'], 'audit' => ['audits', 'audit']] as $tab => [$key, $section]) {
        $data = app(AdminCustomerQueries::class)->detail($actor, $owner->id, ['tab' => $tab, 'services_page' => 2, 'invoices_page' => 2, 'kyc_page' => 2]);
        $url = $data[$key]->url(2);
        expect($url)->toEndWith('#customer-'.$section)->toContain('tab='.$tab);
        foreach (['services' => 'services_page', 'invoices' => 'invoices_page', 'identity' => 'kyc_page'] as $otherTab => $page) {
            expect(str_contains($url, $page.'=2'))->toBe($otherTab === $tab);
        }
    }
    $xpath = customerManagementUiXpath($this->actingAs($actor)->get('/admin/customers/'.$owner->id.'?kyc_page=2')->assertOk()->getContent());
    expect($xpath->query('//nav[@aria-label="客户详情页签"]//a[@data-customer-tab="identity"][@aria-current="page"]'))->toHaveCount(1)
        ->and($xpath->query('//section[@id="customer-kyc"]'))->toHaveCount(1)
        ->and($xpath->query('//section[@id="customer-services" or @id="customer-invoices"]'))->toHaveCount(0);
});

it('restores a failed profile only to the original customer editor and preserves all editable contact fields', function (): void {
    $actor = customerManagementUiActor('customers.view', 'customers.manage', 'customers.password-reset', 'customers.status.manage');
    $owner = User::factory()->create(['phone' => '+8613800000000']);
    $other = User::factory()->create();
    $payload = ['_admin_form' => 'malicious-marker', 'customer_id' => $other->id, 'name' => '待保存姓名', 'email' => 'invalid-email', 'phone' => '+8613900000000', 'reason' => '待保存备注'];
    $this->actingAs($actor)->from('/admin/customers/'.$owner->id)->post('/admin/customers/'.$owner->id.'/profile', $payload)
        ->assertRedirect('/admin/customers/'.$owner->id)->assertSessionHasErrors('email')
        ->assertSessionHas('_old_input._admin_form', 'customer-profile')->assertSessionHas('_old_input.customer_id', $owner->id);
    $xpath = customerManagementUiXpath($this->get('/admin/customers/'.$owner->id)->assertOk()->getContent());
    $form = customerManagementUiForm($xpath, $owner->id, 'profile');
    foreach (['name', 'email', 'phone', 'reason'] as $key) {
        expect(customerManagementUiValue($xpath, $form, $key))->toBe($payload[$key]);
    }
    $dialog = $xpath->query('ancestor::dialog', $form)->item(0);
    expect($dialog->getAttribute('x-init'))->toContain('showModal')
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $form))->toHaveCount(1);
    foreach (['password-reset', 'access'] as $action) {
        $otherForm = customerManagementUiForm($xpath, $owner->id, $action);
        expect(customerManagementUiValue($xpath, $otherForm, 'reason'))->toBe('')
            ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $otherForm))->toHaveCount(0);
    }
    expect($owner->fresh()->phone)->toBe('+8613800000000');
});

it('preserves the current customer tab its page and a safe source when a profile dialog succeeds or fails', function (): void {
    $actor = customerManagementUiActor('customers.view', 'customers.manage', 'upstream.view');
    $owner = User::factory()->create();
    $input = ['tab' => 'services', 'services_page' => 2, 'kyc_page' => 3,
        'from' => ['kind' => 'customers', 'query' => ['name' => '目标客户', 'page' => 2]]];
    $path = '/admin/customers/'.$owner->id;
    $response = $this->actingAs($actor)->get($path.'?'.http_build_query($input))->assertOk();
    $xpath = customerManagementUiXpath($response->getContent());
    $form = $xpath->query('//dialog[@id="customer-profile-editor"]//form')->item(0);
    $action = $form->getAttribute('action');
    parse_str(parse_url($action, PHP_URL_QUERY), $formQuery);
    expect($formQuery)->toEqual(['tab' => 'services', 'services_page' => '2', 'from' => $input['from']]);
    $payload = ['name' => '保留页签的新姓名', 'email' => 'invalid-email', 'phone' => '', 'reason' => '保留非秘密备注'];
    $failed = $this->post($action, $payload)->assertRedirect()->assertSessionHasErrors('email');
    $location = $failed->headers->get('Location');
    parse_str(parse_url($location, PHP_URL_QUERY), $redirectQuery);
    expect(parse_url($location, PHP_URL_PATH))->toBe($path)->and($redirectQuery)->toEqual($formQuery);
    $xpath = customerManagementUiXpath($this->get($location)->assertOk()->getContent());
    expect($xpath->query('//nav[@aria-label="客户详情页签"]//a[@data-customer-tab="services"][@aria-current="page"]'))->toHaveCount(1)
        ->and($xpath->query('//section[@id="customer-profile"]'))->toHaveCount(0)
        ->and($xpath->query('//dialog[@id="customer-profile-editor"]//input[@name="name"]')->item(0)->getAttribute('value'))->toBe('保留页签的新姓名')
        ->and($xpath->query('//dialog[@id="customer-profile-editor"]')->item(0)->getAttribute('x-init'))->toContain('showModal');
    $payload['email'] = $owner->email;
    $saved = $this->post($action, $payload)->assertRedirect()->assertSessionHas('customer_status');
    parse_str(parse_url($saved->headers->get('Location'), PHP_URL_QUERY), $savedQuery);
    expect($savedQuery)->toEqual($formQuery)->and($owner->fresh()->name)->toBe('保留页签的新姓名');
});

it('never copies failed profile input into another customer or an unrecognized form', function (string $kind): void {
    $actor = customerManagementUiActor('customers.view', 'customers.manage');
    $owner = User::factory()->create();
    $source = $kind === 'other customer' ? User::factory()->create() : $owner;
    $this->actingAs($actor)->withSession(['errors' => (new ViewErrorBag)->put('default', new MessageBag(['email' => ['需要核对邮箱。']])),
        '_old_input' => ['_admin_form' => $kind === 'other customer' ? 'customer-profile' : 'unknown-form', 'customer_id' => $source->id, 'name' => 'WRONG-CUSTOMER-INPUT', 'email' => 'WRONG-EMAIL-INPUT', 'phone' => '+8613999999999', 'reason' => 'WRONG-NOTE-INPUT']]);
    $xpath = customerManagementUiXpath($this->get('/admin/customers/'.$owner->id)->assertOk()->getContent());
    $form = customerManagementUiForm($xpath, $owner->id, 'profile');
    expect(customerManagementUiValue($xpath, $form, 'name'))->toBe($owner->name)
        ->and(customerManagementUiValue($xpath, $form, 'email'))->toBe($owner->email)
        ->and(customerManagementUiValue($xpath, $form, 'phone'))->toBe((string) $owner->phone)
        ->and(customerManagementUiValue($xpath, $form, 'reason'))->toBe('')
        ->and($xpath->query('ancestor::dialog', $form)->item(0)->getAttribute('x-init'))->toBe('');
})->with(['other customer', 'unknown form']);

it('restores only the failed reset or access dialog without sending mail or changing access', function (string $action, string $marker, array $input, string $error): void {
    $actor = customerManagementUiActor('customers.view', 'customers.manage', 'customers.password-reset', 'customers.status.manage');
    $owner = User::factory()->create();
    $input['reason'] = $action === 'password-reset' ? str_repeat('x', 256) : '保留操作备注';
    $this->actingAs($actor)->from('/admin/customers/'.$owner->id)->post('/admin/customers/'.$owner->id.'/'.$action, $input)
        ->assertRedirect('/admin/customers/'.$owner->id)->assertSessionHasErrors($error)->assertSessionHas('_old_input._admin_form', $marker);
    $xpath = customerManagementUiXpath($this->get('/admin/customers/'.$owner->id)->assertOk()->getContent());
    $form = customerManagementUiForm($xpath, $owner->id, $action);
    expect(customerManagementUiValue($xpath, $form, 'reason'))->toBe($input['reason'])
        ->and($xpath->query('ancestor::dialog', $form)->item(0)->getAttribute('x-init'))->toContain('showModal')
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $form))->toHaveCount(1);
    $profile = customerManagementUiForm($xpath, $owner->id, 'profile');
    expect(customerManagementUiValue($xpath, $profile, 'reason'))->toBe('')->and($owner->fresh()->disabled_at)->toBeNull();
    $this->assertDatabaseCount('password_reset_tokens', 0);
})->with([
    'reset reason' => ['password-reset', 'customer-password-reset', [], 'reason'],
    'invalid status' => ['access', 'customer-access', ['disabled' => 'not-a-boolean'], 'disabled'],
]);

it('keeps readonly customer viewers forbidden from all management posts', function (string $action, array $input): void {
    $actor = customerManagementUiActor('customers.view');
    $owner = User::factory()->create();
    $before = $owner->fresh()->getRawOriginal();
    $this->actingAs($actor)->post('/admin/customers/'.$owner->id.'/'.$action, $input)->assertForbidden();
    expect($owner->fresh()->getRawOriginal())->toBe($before);
    $this->assertDatabaseCount('password_reset_tokens', 0);
})->with([
    'profile' => ['profile', ['name' => '不允许', 'email' => 'blocked@example.invalid', 'phone' => '']],
    'reset' => ['password-reset', []],
    'status' => ['access', ['disabled' => '1']],
]);

it('submits optional-note profile and access forms and returns feedback to the native profile anchor', function (): void {
    $actor = customerManagementUiActor('customers.view', 'customers.manage', 'customers.status.manage');
    $owner = User::factory()->create();
    $this->actingAs($actor)->post('/admin/customers/'.$owner->id.'/profile', ['name' => '更新后的姓名', 'email' => $owner->email, 'phone' => ''])
        ->assertRedirect('/admin/customers/'.$owner->id.'?tab=overview#customer-profile')->assertSessionHas('customer_status');
    $this->get('/admin/customers/'.$owner->id)->assertOk()->assertSee('更新后的姓名')->assertSee('客户资料已保存');
    $this->post('/admin/customers/'.$owner->id.'/access', ['disabled' => '1'])->assertRedirect('/admin/customers/'.$owner->id.'?tab=overview#customer-profile');
    $response = $this->get('/admin/customers/'.$owner->id)->assertOk()->assertSee('已停用')->assertSee('恢复账户访问');
    $xpath = customerManagementUiXpath($response->getContent());
    expect(customerManagementUiValue($xpath, customerManagementUiForm($xpath, $owner->id, 'access'), 'disabled'))->toBe('0');
    $this->post('/admin/customers/'.$owner->id.'/access', ['disabled' => '0'])->assertRedirect('/admin/customers/'.$owner->id.'?tab=overview#customer-profile');
    $this->get('/admin/customers/'.$owner->id)->assertOk()->assertSee('账户访问已恢复');
    expect($owner->fresh()->disabled_at)->toBeNull()->and($owner->fresh()->auth_revision)->toBe(1);
});

it('reopens validation failures containing array input without rendering arrays into text fields', function (string $action, string $field): void {
    $actor = customerManagementUiActor('customers.view', 'customers.manage', 'customers.password-reset', 'customers.status.manage');
    $owner = User::factory()->create(['phone' => '+8613800000000']);
    $input = $action === 'profile' ? ['name' => $owner->name, 'email' => $owner->email, 'phone' => $owner->phone] : ($action === 'access' ? ['disabled' => '1'] : []);
    $input[$field] = ['invalid-type'];
    $this->actingAs($actor)->from('/admin/customers/'.$owner->id)->post('/admin/customers/'.$owner->id.'/'.$action, $input)
        ->assertRedirect('/admin/customers/'.$owner->id)->assertSessionHasErrors($field);
    $xpath = customerManagementUiXpath($this->get('/admin/customers/'.$owner->id)->assertOk()->getContent());
    $form = customerManagementUiForm($xpath, $owner->id, $action);
    expect(customerManagementUiValue($xpath, $form, $field))->toBe($field === 'reason' ? '' : (string) $owner->getAttribute($field))
        ->and($xpath->query('ancestor::dialog', $form)->item(0)->getAttribute('x-init'))->toContain('showModal');
})->with([
    'name array' => ['profile', 'name'], 'email array' => ['profile', 'email'], 'phone array' => ['profile', 'phone'],
    'profile note array' => ['profile', 'reason'], 'reset note array' => ['password-reset', 'reason'], 'access note array' => ['access', 'reason'],
]);
