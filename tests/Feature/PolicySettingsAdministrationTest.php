<?php

declare(strict_types=1);

use App\Actions\AccrueAffiliateCommissionAction;
use App\Actions\CreateComboInvoiceAction;
use App\Actions\DispatchClassicCatalogAction;
use App\Actions\SaveAutomationSettingsAction;
use App\Actions\SaveBillingSettingsAction;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\InvoiceStatus;
use App\Jobs\ProbeClassicProductJob;
use App\Models\AffiliateRule;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Services\SettingService;
use App\Support\PolicySettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    $this->travelTo(now()->startOfSecond());
    Http::preventStrayRequests();
    Queue::fake();
});

afterEach(function (): void {
    Http::assertNothingSent();
});

function policySettingsAdministrator(array $permissions = ['settings.view', 'settings.billing.manage']): User
{
    $actor = User::factory()->create();
    foreach ($permissions as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $actor;
}

function policySettingsAction(string $section): SaveBillingSettingsAction|SaveAutomationSettingsAction
{
    return $section === 'billing' ? app(SaveBillingSettingsAction::class) : app(SaveAutomationSettingsAction::class);
}

it('exposes the existing billing and automation consumers without new financial or budget keys', function (): void {
    expect(array_keys(PolicySettings::sections()))->toBe(['billing', 'automation'])
        ->and(PolicySettings::keysForSection('billing'))->toBe(['billing.invoice_expiry_minutes', 'billing.renewal_notice_days', 'billing.renewal_grace_days', 'affiliate.freeze_days'])
        ->and(PolicySettings::keysForSection('automation'))->toBe(['lifecycle.urgent_batch', 'lifecycle.rotation_batch', 'lifecycle.catalog_batch']);
    expect(fn () => PolicySettings::keysForSection('payment'))->toThrow(ValidationException::class);
    foreach (PolicySettings::sections() as $definition) {
        expect($definition['permission'])->toBe('settings.billing.manage');
        foreach ($definition['fields'] as $key => $field) {
            expect(app(SettingService::class)->getInt($key, $field['default']))->toBe($field['default']);
        }
    }
});

it('saves integer policies with stable optional notes and key-only administrator audit', function (string $section, array $settings): void {
    $actor = policySettingsAdministrator();
    policySettingsAction($section)->execute($actor, $settings, '   ');
    foreach ($settings as $key => $value) {
        [$group, $name] = explode('.', $key, 2);
        $this->assertDatabaseHas('system_settings', ['group' => $group, 'key' => $name, 'value' => (string) $value, 'is_encrypted' => false]);
        expect(app(SettingService::class)->getInt($key))->toBe((int) $value);
    }
    $audit = DB::table('audit_logs')->where('action', 'settings.'.$section.'.configure')->sole();
    expect($audit->actor_type)->toBe('user')->and($audit->actor_id)->toBe($actor->id)
        ->and($audit->subject_type)->toBe('system_settings')->and($audit->reason)->toBe($section === 'billing' ? '保存计费策略配置' : '保存自动化策略配置')
        ->and($audit->before)->toBeNull()->and(json_decode($audit->after, true, flags: JSON_THROW_ON_ERROR))->toBe(['keys' => array_keys($settings)])
        ->and($audit->request_id)->not->toBeNull();
})->with([
    ['billing', ['billing.invoice_expiry_minutes' => '90', 'billing.renewal_notice_days' => 0, 'billing.renewal_grace_days' => 365, 'affiliate.freeze_days' => '2']],
    ['automation', ['lifecycle.urgent_batch' => '1', 'lifecycle.rotation_batch' => 100, 'lifecycle.catalog_batch' => '25']],
]);

it('preserves an explicit zero remark when saving policies over HTTP', function (string $section): void {
    $actor = policySettingsAdministrator();
    $key = PolicySettings::keysForSection($section)[0];
    $this->actingAs($actor)->post('/admin/settings/policy/'.$section, ['settings' => [$key => 30], 'reason' => '0'])
        ->assertRedirect('/admin/settings?tab='.$section);
    expect(DB::table('audit_logs')->where('action', 'settings.'.$section.'.configure')->sole()->reason)->toBe('0');
})->with(['billing', 'automation']);

it('rejects invalid scalar types bounds and foreign keys before writing any policy', function (string $section, array $invalid): void {
    $actor = policySettingsAdministrator();
    $valid = $section === 'billing' ? ['billing.renewal_grace_days' => 14] : ['lifecycle.rotation_batch' => 3];
    $before = DB::table('system_settings')->orderBy('id')->get()->toArray();
    expect(fn () => policySettingsAction($section)->execute($actor, [...$valid, ...$invalid]))->toThrow(ValidationException::class);
    expect(DB::table('system_settings')->orderBy('id')->get()->toArray())->toEqual($before)
        ->and(DB::table('audit_logs')->where('action', 'like', 'settings.%.configure')->count())->toBe(0);
})->with([
    'float' => ['billing', ['billing.invoice_expiry_minutes' => 1.0]],
    'array' => ['billing', ['billing.invoice_expiry_minutes' => [1]]],
    'boolean' => ['billing', ['billing.invoice_expiry_minutes' => true]],
    'decimal string' => ['billing', ['billing.invoice_expiry_minutes' => '1.5']],
    'exponent' => ['billing', ['billing.invoice_expiry_minutes' => '1e2']],
    'null' => ['billing', ['billing.invoice_expiry_minutes' => null]],
    'expiry below' => ['billing', ['billing.invoice_expiry_minutes' => 0]],
    'expiry above' => ['billing', ['billing.invoice_expiry_minutes' => 10081]],
    'notice below' => ['billing', ['billing.renewal_notice_days' => -1]],
    'notice above' => ['billing', ['billing.renewal_notice_days' => 366]],
    'grace below' => ['billing', ['billing.renewal_grace_days' => 0]],
    'grace above' => ['billing', ['billing.renewal_grace_days' => 366]],
    'freeze below' => ['billing', ['affiliate.freeze_days' => -1]],
    'freeze above' => ['billing', ['affiliate.freeze_days' => 366]],
    'unbounded integer' => ['billing', ['billing.invoice_expiry_minutes' => str_repeat('9', 30)]],
    'foreign payment key' => ['billing', ['payment.epay_key' => 'PRIVATE-FOREIGN']],
    'foreign amount key' => ['billing', ['wallet.topup_max_amount' => '99999']],
    'cross-domain automation key' => ['billing', ['lifecycle.catalog_batch' => 3]],
    'urgent below' => ['automation', ['lifecycle.urgent_batch' => 0]],
    'urgent above' => ['automation', ['lifecycle.urgent_batch' => 101]],
    'rotation below' => ['automation', ['lifecycle.rotation_batch' => 0]],
    'rotation above' => ['automation', ['lifecycle.rotation_batch' => 101]],
    'catalog above' => ['automation', ['lifecycle.catalog_batch' => 200]],
    'hard budget injection' => ['automation', ['maintenance.budget_seconds' => 60]],
    'cross-domain billing key' => ['automation', ['billing.invoice_expiry_minutes' => 90]],
]);

it('rejects empty policy updates and overlong optional remarks', function (string $section): void {
    $actor = policySettingsAdministrator();
    expect(fn () => policySettingsAction($section)->execute($actor, []))->toThrow(ValidationException::class);
    $key = PolicySettings::keysForSection($section)[0];
    expect(fn () => policySettingsAction($section)->execute($actor, [$key => 30], str_repeat('备', 256)))->toThrow(ValidationException::class);
    expect(DB::table('system_settings')->count())->toBe(0);
})->with(['billing', 'automation']);

it('revalidates active administrators and both required permissions before policy writes', function (string $section, string $case): void {
    $permissions = match ($case) {
        'no-view' => ['settings.billing.manage'],
        'no-manage' => ['settings.view'],
        'other-domain' => ['settings.view', 'settings.payment.manage'],
        default => ['settings.view', 'settings.billing.manage'],
    };
    $actor = policySettingsAdministrator($permissions);
    // Populate the caller permission relation, then change authoritative state.
    $actor->can('settings.billing.manage');
    match ($case) {
        'deleted' => User::whereKey($actor->id)->update(['deleted_at' => now()]),
        'anonymized' => User::whereKey($actor->id)->update(['anonymized_at' => now()]),
        'revoked' => $actor->revokePermissionTo('settings.billing.manage'),
        default => null,
    };
    $key = PolicySettings::keysForSection($section)[0];
    expect(fn () => policySettingsAction($section)->execute($actor, [$key => 3]))->toThrow(AuthorizationException::class);
    expect(DB::table('system_settings')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'like', 'settings.%.configure')->count())->toBe(0);
})->with(['billing', 'automation'])->with(['no-view', 'no-manage', 'other-domain', 'deleted', 'anonymized', 'revoked']);

it('rolls back the whole policy group and preserves warm caches when audit insertion fails', function (string $section): void {
    $actor = policySettingsAdministrator();
    $keys = PolicySettings::keysForSection($section);
    $action = policySettingsAction($section);
    $action->execute($actor, [$keys[0] => 10, $keys[1] => 10]);
    $settings = app(SettingService::class);
    expect($settings->getInt($keys[0]))->toBe(10)->and($settings->getInt($keys[1]))->toBe(10);
    $before = DB::table('system_settings')->orderBy('id')->get()->toArray();
    DB::unprepared("CREATE TRIGGER reject_policy_audit BEFORE INSERT ON audit_logs WHEN NEW.action = 'settings.{$section}.configure' BEGIN SELECT RAISE(ABORT, 'Policy audit unavailable'); END");
    expect(fn () => $action->execute($actor, [$keys[0] => 15, $keys[1] => 15]))->toThrow(QueryException::class, 'Policy audit unavailable');
    expect(DB::table('system_settings')->orderBy('id')->get()->toArray())->toEqual($before)
        ->and($settings->getInt($keys[0]))->toBe(10)->and($settings->getInt($keys[1]))->toBe(10)
        ->and(DB::table('audit_logs')->where('action', 'settings.'.$section.'.configure')->count())->toBe(1);
    app()->forgetScopedInstances();
    expect(app(SettingService::class)->getInt($keys[0]))->toBe(10);
})->with(['billing', 'automation']);

it('invalidates scoped and shared policy caches only after commit', function (string $section): void {
    $actor = policySettingsAdministrator();
    $key = PolicySettings::keysForSection($section)[0];
    $action = policySettingsAction($section);
    $action->execute($actor, [$key => 10]);
    $settings = app(SettingService::class);
    expect($settings->getInt($key))->toBe(10);
    DB::beginTransaction();
    $action->execute($actor, [$key => 20]);
    expect($settings->getInt($key))->toBe(10);
    DB::rollBack();
    expect($settings->getInt($key))->toBe(10);
    $action->execute($actor, [$key => 30], '  更新未来策略  ');
    expect($settings->getInt($key))->toBe(30);
    app()->forgetScopedInstances();
    expect(app(SettingService::class)->getInt($key))->toBe(30);
    [$group, $name] = explode('.', $key, 2);
    expect(Cache::get('setting:'.$group.':'.$name)['value'])->toBe('30')
        ->and(DB::table('audit_logs')->where('action', 'settings.'.$section.'.configure')->latest('id')->value('reason'))->toBe('更新未来策略');
})->with(['billing', 'automation']);

it('uses the changed expiry only for future invoices while replay and stored expiration remain unchanged', function (): void {
    $actor = policySettingsAdministrator();
    $buyer = User::factory()->create();
    $product = Product::factory()->create(['provisioning_rules' => [],
        'local_prices' => ['onetime' => ['price' => '10.00', 'setup_fee' => '0.00', 'enabled' => true]]]);
    $requestId = (string) Str::ulid();
    $creator = app(CreateComboInvoiceAction::class);
    $old = $creator->execute($buyer, $product, BillingCycle::ONETIME, [], false, 'epay_wechat', ['request_id' => $requestId]);
    $oldExpiry = $old->expires_at->copy();
    expect($oldExpiry->equalTo(now()->addMinutes(30)))->toBeTrue();
    app(SaveBillingSettingsAction::class)->execute($actor, ['billing.invoice_expiry_minutes' => 90]);
    $new = $creator->execute($buyer, $product, BillingCycle::ONETIME, [], false, 'epay_wechat', ['request_id' => (string) Str::ulid()]);
    expect($new->expires_at->equalTo(now()->addMinutes(90)))->toBeTrue()
        ->and($old->fresh()->expires_at->equalTo($oldExpiry))->toBeTrue()
        ->and($creator->execute($buyer, $product, BillingCycle::ONETIME, [], false, 'epay_wechat', ['request_id' => $requestId])->id)->toBe($old->id)
        ->and($old->fresh()->expires_at->equalTo($oldExpiry))->toBeTrue();
});

it('keeps historical commission snapshots and maturity dates after changing the future freeze policy', function (): void {
    $actor = policySettingsAdministrator();
    $referrer = User::factory()->create();
    $buyer = User::factory()->create(['credit' => '100.00', 'referred_by' => $referrer->id]);
    $product = Product::factory()->create(['provisioning_rules' => [],
        'local_prices' => ['onetime' => ['price' => '10.00', 'setup_fee' => '0.00', 'enabled' => true]]]);
    AffiliateRule::create(['name' => 'Isolated policy rule', 'commission_rate' => '10.00', 'priority' => 1, 'is_active' => true]);
    $create = fn () => app(CreateComboInvoiceAction::class)->execute($buyer, $product, BillingCycle::ONETIME, [], true, null, ['request_id' => (string) Str::ulid()]);
    $old = $create();
    $lateAccrual = $create();
    $oldCommission = app(AccrueAffiliateCommissionAction::class)->execute($old->id);
    $oldMaturity = $oldCommission->available_at->copy();
    app(SaveBillingSettingsAction::class)->execute($actor, ['affiliate.freeze_days' => 2]);
    $new = $create();
    $lateCommission = app(AccrueAffiliateCommissionAction::class)->execute($lateAccrual->id);
    $newCommission = app(AccrueAffiliateCommissionAction::class)->execute($new->id);
    expect($old->fresh()->line_items['affiliate']['freeze_days'])->toBe(7)
        ->and($lateAccrual->fresh()->line_items['affiliate']['freeze_days'])->toBe(7)
        ->and($new->line_items['affiliate']['freeze_days'])->toBe(2)
        ->and($oldCommission->fresh()->available_at->equalTo($oldMaturity))->toBeTrue()
        ->and($lateCommission->available_at->equalTo($lateAccrual->paid_at->copy()->addDays(7)))->toBeTrue()
        ->and($newCommission->available_at->equalTo($new->paid_at->copy()->addDays(2)))->toBeTrue()
        ->and($new->status)->toBe(InvoiceStatus::PAID)->and($buyer->fresh()->credit)->toBe('70.00');
});

it('applies the saved catalog quota to the existing dispatcher without sending supplier requests', function (): void {
    config(['queue.default' => 'database']);
    $actor = policySettingsAdministrator();
    $account = UpstreamApi::factory()->create();
    Product::factory()->count(3)->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id]);
    $dispatch = app(DispatchClassicCatalogAction::class);
    app(SaveAutomationSettingsAction::class)->execute($actor, ['lifecycle.catalog_batch' => 2]);
    expect($dispatch->execute())->toBe(2);
    app(SaveAutomationSettingsAction::class)->execute($actor, ['lifecycle.catalog_batch' => 1]);
    expect($dispatch->execute())->toBe(1)->and(config('maintenance.limit'))->toBe(100)
        ->and(config('maintenance.budget_seconds'))->toBe(5);
    Queue::assertPushed(ProbeClassicProductJob::class, 3);
});
