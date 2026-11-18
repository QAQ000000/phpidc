<?php

declare(strict_types=1);

use App\Actions\ApplyInstanceMappingReviewAction;
use App\Actions\ConfirmInstanceMappingPriceAction;
use App\Actions\DowngradeCalculatorAction;
use App\Actions\ExecuteFulfillmentTaskAction;
use App\Actions\HotSwapInstanceDriverAction;
use App\Actions\InspectInstanceMappingAction;
use App\Actions\PreviewInstanceMappingPriceAction;
use App\Actions\ReconcileBindingOrphanAction;
use App\Actions\ResolveProductOptionsAction;
use App\Actions\SyncClassicCatalogAction;
use App\Actions\UpgradeCalculatorAction;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\InstanceMappingReview;
use App\Models\InstanceUpstreamBinding;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\ReconciliationCase;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Services\BindingReconciliationCases;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Queue::fake();
    Http::preventStrayRequests();
});

function mappingReviewFixture(string $recurring = '12.00'): array
{
    $user = User::factory()->create(['password' => 'Mapping-Password-123']);
    $actor = User::factory()->create();
    Gate::define('upstream.view', fn (User $candidate): bool => $candidate->id === $actor->id);
    Gate::define('upstream.mapping-review', fn (User $candidate): bool => $candidate->id === $actor->id);
    $account = UpstreamApi::factory()->create();
    $old = Product::factory()->create();
    ProductOptionGroup::create(['product_id' => $old->id, 'name' => 'Old CPU', 'type' => 'select', 'sync_source' => 'local']);
    $target = Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id,
        'upstream_pid' => '17', 'markup_percent' => '20.00']);
    $catalog = ['products' => ['id' => 17, 'location_version' => 1, 'pay_type' => ['pay_type' => 'recurring', 'pay_monthly_cycle' => 1]],
        'product_pricings' => [['code' => 'CNY', 'monthly' => '8.00', 'msetupfee' => '0.00']],
        'config_groups' => [['id' => 10, 'options' => [['id' => 50, 'option_type' => 1, 'option_name' => 'CPU', 'upgrade' => 1,
            'sub' => [['id' => 501, 'option_name' => 'Small', 'pricings' => [['code' => 'CNY', 'monthly' => '1.00', 'msetupfee' => '0.00']]],
                ['id' => 502, 'option_name' => 'Large', 'pricings' => [['code' => 'CNY', 'monthly' => '3.00', 'msetupfee' => '0.00']]],
                ['id' => 503, 'option_name' => 'Minimal', 'pricings' => [['code' => 'CNY', 'monthly' => '0.00', 'msetupfee' => '0.00']]]]]]]],
        'customfields' => [], 'advanced' => []];
    $state = (object) ['catalog' => $catalog, 'cost' => '9.00', 'pid' => 17, 'currency' => 'CNY', 'cycle' => 'monthly',
        'hostStatus' => 'Active', 'hostId' => 99, 'actual' => [['oid' => 50, 'subid' => 501, 'qty' => 1, 'option_type' => 1]],
        'extra' => [], 'calls' => [], 'hook' => null];
    $level = DB::transactionLevel();
    Http::fake(function ($request) use ($state, $level) {
        expect(DB::transactionLevel())->toBe($level);
        $path = parse_url($request->url(), PHP_URL_PATH);
        $state->calls[] = [$request->method(), $path];
        if ($state->hook !== null) {
            ($state->hook)($path, $request);
        }

        return Http::response(match ($path) {
            '/zjmf_api_login' => ['status' => 200, 'jwt' => 'PRIVATE-MAPPING-JWT'],
            '/host/setdownstream' => ['status' => 200],
            '/cart/get_product_config' => ['status' => 200, 'data' => $state->catalog],
            '/host/header' => ['status' => 200, 'data' => ['host_data' => ['id' => $state->hostId, 'productid' => $state->pid,
                'billingcycle' => $state->cycle, 'domainstatus' => $state->hostStatus, 'password' => 'PRIVATE-HOST-PASSWORD'],
                'currency' => ['code' => $state->currency], 'config_options' => []]],
            '/upgrade/index/99' => ['status' => 200, 'data' => ['pid' => $state->pid, 'currency' => ['code' => $state->currency],
                'host' => $state->actual, 'host_config_options' => $state->extra]],
            '/host/renewpage' => ['status' => 200, 'data' => ['currency' => ['code' => $state->currency],
                'host' => ['billingcycle' => $state->cycle, 'amount' => $state->cost]]],
            default => throw new RuntimeException('Unexpected mapping review request: '.$path),
        });
    });
    app(SyncClassicCatalogAction::class)->execute($target, 'Prepare independently synchronized target catalog');
    $target->refresh();
    $instance = Instance::factory()->create(['user_id' => $user->id, 'product_id' => $old->id, 'driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => $account->id, 'upstream_instance_id' => '99', 'downstream_token' => 'PRIVATE-DOWNSTREAM-TOKEN',
        'status' => InstanceStatus::ACTIVE, 'billing_cycle' => BillingCycle::MONTHLY, 'currency' => 'CNY',
        'next_due_at' => now()->addMonth()->startOfMinute(), 'recurring_amount' => $recurring, 'is_price_locked' => false,
        'active_config' => ['upstream_pid' => 17, 'mapping_requires_review' => true, 'options' => [], 'upstream_configoption' => []]]);
    InstanceUpstreamBinding::create(['instance_id' => $instance->id, 'driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => $account->id, 'upstream_instance_id' => '99', 'bound_at' => now()]);
    $invoice = Invoice::factory()->paid()->create(['user_id' => $user->id, 'instance_id' => $instance->id, 'product_id' => $old->id]);

    return [$user, $target, $instance, $actor, $state, $invoice];
}

function mappingReviewApply(Instance $instance, Product $target, User $actor): InstanceMappingReview
{
    $id = (string) Str::ulid();
    $preview = app(InspectInstanceMappingAction::class)->execute($instance, $target, $actor, $id);

    return app(ApplyInstanceMappingReviewAction::class)->execute($instance, $actor, $id, $preview['review_token'], 'Verified complete replacement configuration', true);
}

it('rebuilds explicit target IDs and immutable evidence while retaining original invoices price and due', function (): void {
    [$user,$target,$instance,$actor,$state,$invoice] = mappingReviewFixture();
    $beforeInvoice = $invoice->fresh()->getRawOriginal();
    $due = $instance->next_due_at->toIso8601String();
    $review = mappingReviewApply($instance, $target, $actor);
    $instance->refresh();
    $pair = $instance->active_config['options'][0];
    expect($instance->product_id)->toBe($target->id)->and($instance->recurring_amount)->toBe('12.00')
        ->and($instance->next_due_at->toIso8601String())->toBe($due)->and($instance->is_price_locked)->toBeTrue()
        ->and($instance->active_config['mapping_requires_review'])->toBeFalse()->and($instance->active_config['price_requires_review'])->toBeTrue()
        ->and($instance->active_config['mapping_review_id'])->toBe($review->id)->and($pair['local_group_id'])->toBe($target->optionGroups->sole()->id)
        ->and($pair['upstream_group_id'])->toBe(50)->and($pair['unit_price'])->toBe('1.20')
        ->and($invoice->fresh()->getRawOriginal())->toBe($beforeInvoice)->and($review->snapshot['new_recurring_amount'])->toBe('10.80')
        ->and($review->snapshot['catalog_cost'])->toBe('9.00')->and($review->snapshot['renewal_cost'])->toBe('9.00');
    $encoded = json_encode($review->snapshot, JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain('PRIVATE-', 'encrypted_secret', 'password', 'Authorization');
    expect(collect($state->calls)->filter(fn (array $call): bool => $call[0] === 'POST' && $call[1] !== '/zjmf_api_login'))->toBeEmpty();
    $this->assertDatabaseHas('audit_logs', ['action' => 'instance.mapping_review.applied', 'subject_id' => $instance->id]);
});

it('clears the price gate when the complete target price already matches the retained price', function (): void {
    [$user,$target,$instance,$actor] = mappingReviewFixture('10.80');
    mappingReviewApply($instance, $target, $actor);
    $instance->refresh();
    expect($instance->active_config['price_requires_review'])->toBeFalse();
    $options = app(ResolveProductOptionsAction::class)->execute($target, 'monthly', ['configoption' => [50 => 502]], false);
    expect(app(UpgradeCalculatorAction::class)->execute($instance, $options))->toBe('13.20');
});

it('provides an owner password confirmed exact price closure and restores upgrade and bounded downgrade calculations', function (): void {
    [$user,$target,$instance,$actor,$state,$invoice] = mappingReviewFixture();
    $mapping = mappingReviewApply($instance, $target, $actor);
    $instance->refresh();
    $options = app(ResolveProductOptionsAction::class)->execute($target, 'monthly', ['configoption' => [50 => 502]], false);
    expect(fn () => app(UpgradeCalculatorAction::class)->execute($instance, $options))->toThrow(DomainException::class);
    expect(fn () => app(DowngradeCalculatorAction::class)->execute($instance, ['configoption' => [50 => 503]], $user, 'Pending price must block'))->toThrow(DomainException::class);
    $due = $instance->next_due_at->toIso8601String();
    $beforeInvoice = $invoice->fresh()->getRawOriginal();
    $id = (string) Str::ulid();
    $preview = app(PreviewInstanceMappingPriceAction::class)->execute($user, $instance, $id);
    expect($preview['snapshot']['base_price'])->toBe('9.60')->and($preview['snapshot']['option_price'])->toBe('1.20');
    $review = app(ConfirmInstanceMappingPriceAction::class)->execute($user, $instance, $id, $preview['review_token'], 'Mapping-Password-123', true);
    $instance->refresh();
    expect($instance->recurring_amount)->toBe('10.80')->and($instance->active_config['price_requires_review'])->toBeFalse()
        ->and($instance->active_config['mapping_review_id'])->toBe($mapping->id)->and($instance->active_config['price_confirmation_id'])->toBe($review->id)
        ->and($review->snapshot['parent_mapping_review_id'])->toBe($mapping->id)->and($instance->next_due_at->toIso8601String())->toBe($due)
        ->and($invoice->fresh()->getRawOriginal())->toBe($beforeInvoice)->and(app(UpgradeCalculatorAction::class)->execute($instance, $options))->toBe('13.20');
    $group = $target->optionGroups->sole();
    $group->update(['downgrade_bounds' => ['allow_downgrade' => true, 'allowed_item_ids' => [$group->items->firstWhere('upstream_sub_id', 503)->id]]]);
    expect(app(DowngradeCalculatorAction::class)->execute($instance, ['configoption' => [50 => 503]], $user, 'Verified lower future billing'))->toBe('9.60');
    expect(collect($state->calls)->filter(fn (array $call): bool => $call[0] === 'POST' && $call[1] !== '/zjmf_api_login'))->toBeEmpty();
});

it('replays the original mapping and price confirmations without rereading or overwriting history', function (): void {
    [$user,$target,$instance,$actor,$state] = mappingReviewFixture();
    $id = (string) Str::ulid();
    $preview = app(InspectInstanceMappingAction::class)->execute($instance, $target, $actor, $id);
    $action = app(ApplyInstanceMappingReviewAction::class);
    $first = $action->execute($instance, $actor, $id, $preview['review_token'], 'Stable mapping intent', true);
    $calls = count($state->calls);
    $this->travel(6)->minutes();
    expect($action->execute($instance, $actor, $id, $preview['review_token'], 'Stable mapping intent', true)->id)->toBe($first->id)
        ->and(count($state->calls))->toBe($calls)->and(InstanceMappingReview::count())->toBe(1);
    expect(fn () => $action->execute($instance, $actor, $id, $preview['review_token'], 'Changed reason', true))->toThrow(ValidationException::class);
    $instance->refresh();
    $priceId = (string) Str::ulid();
    $price = app(PreviewInstanceMappingPriceAction::class)->execute($user, $instance, $priceId);
    $confirm = app(ConfirmInstanceMappingPriceAction::class);
    $firstPrice = $confirm->execute($user, $instance, $priceId, $price['review_token'], 'Mapping-Password-123', true);
    $calls = count($state->calls);
    expect($confirm->execute($user, $instance, $priceId, $price['review_token'], 'Mapping-Password-123', true)->id)->toBe($firstPrice->id)
        ->and(count($state->calls))->toBe($calls)->and(InstanceMappingReview::count())->toBe(2);
});

it('rejects incomplete unknown duplicate conflicting or incorrectly typed actual configuration evidence', function (string $problem): void {
    [$user,$target,$instance,$actor,$state] = mappingReviewFixture();
    match ($problem) {
        'missing' => $state->actual = [],
        'unknown' => $state->actual[0]['oid'] = 5000,
        'duplicate' => $state->actual[] = $state->actual[0],
        'conflict' => $state->extra = [['configid' => 50, 'optionid' => 502, 'qty' => 1]],
        'type' => $state->actual[0]['option_type'] = 4,
        'sub' => $state->actual[0]['subid'] = 777,
    };
    expect(fn () => app(InspectInstanceMappingAction::class)->execute($instance, $target, $actor, (string) Str::ulid()))->toThrow(ValidationException::class);
    expect(InstanceMappingReview::count())->toBe(0)->and($instance->fresh()->active_config['mapping_requires_review'])->toBeTrue();
})->with(['missing', 'unknown', 'duplicate', 'conflict', 'type', 'sub']);

it('rejects unauthenticated target product cycle currency or terminal state', function (string $problem): void {
    [$user,$target,$instance,$actor,$state] = mappingReviewFixture();
    match ($problem) {
        'pid' => $state->pid = 18,
        'currency' => $state->currency = 'USD',
        'cycle' => $state->cycle = 'annually',
        'state' => $state->hostStatus = 'Deleted',
        'host' => $state->hostId = 100,
        'account' => $target->update(['upstream_api_id' => UpstreamApi::factory()->create()->id]),
    };
    expect(fn () => app(InspectInstanceMappingAction::class)->execute($instance, $target, $actor, (string) Str::ulid()))->toThrow(ValidationException::class);
})->with(['pid', 'currency', 'cycle', 'state', 'host', 'account']);

it('rejects stale versions prices constraints dependencies and unsupported tier pricing', function (string $problem): void {
    [$user,$target,$instance,$actor,$state] = mappingReviewFixture();
    match ($problem) {
        'version' => $state->catalog['products']['location_version'] = 2,
        'base' => $state->catalog['product_pricings'][0]['monthly'] = '9.00',
        'option' => $state->catalog['config_groups'][0]['options'][0]['sub'][0]['pricings'][0]['monthly'] = '2.00',
        'tier' => $state->catalog['config_groups'][0]['options'][0]['option_type'] = 15,
        'dependency' => $state->catalog['advanced'] = [['id' => 9, 'type' => 'condition']],
        'cycle-rule' => $state->catalog['products']['pay_type']['pay_monthly_cycle'] = 2,
    };
    expect(fn () => app(InspectInstanceMappingAction::class)->execute($instance, $target, $actor, (string) Str::ulid()))->toThrow(ValidationException::class);
})->with(['version', 'base', 'option', 'tier', 'dependency', 'cycle-rule']);

it('rejects expired tokens and local state drift between inspection and application', function (string $problem): void {
    [$user,$target,$instance,$actor,$state] = mappingReviewFixture();
    $id = (string) Str::ulid();
    $preview = app(InspectInstanceMappingAction::class)->execute($instance, $target, $actor, $id);
    match ($problem) {
        'expiry' => $this->travel(5)->minutes(),
        'price' => $instance->update(['recurring_amount' => '13.00']),
        'due' => $instance->update(['next_due_at' => now()->addYear()]),
        'binding' => $instance->update(['upstream_instance_id' => '199']),
        'token' => $instance->update(['downstream_token' => 'replacement-token']),
        'catalog' => $target->update(['local_prices' => ['monthly' => ['price' => '10.00', 'enabled' => true]]]),
        'invoice' => Invoice::factory()->create(['user_id' => $user->id, 'instance_id' => $instance->id]),
        'task' => FulfillmentTask::factory()->create(['user_id' => $user->id, 'instance_id' => $instance->id, 'status' => FulfillmentTaskStatus::FAILED]),
        'account' => $target->upstreamApi->update(['base_url' => 'https://changed.test']),
    };
    expect(fn () => app(ApplyInstanceMappingReviewAction::class)->execute($instance, $actor, $id, $preview['review_token'], 'Apply only unchanged facts', true))->toThrow(ValidationException::class);
    expect(InstanceMappingReview::count())->toBe(0);
})->with(['expiry', 'price', 'due', 'binding', 'token', 'catalog', 'invoice', 'task', 'account']);

it('rejects authority loss and mutations that race the final authenticated reads', function (string $problem): void {
    [$user,$target,$instance,$actor,$state] = mappingReviewFixture();
    $id = (string) Str::ulid();
    $preview = app(InspectInstanceMappingAction::class)->execute($instance, $target, $actor, $id);
    $state->hook = function (string $path) use ($problem, $target, $user): void {
        if ($path !== '/host/renewpage') {
            return;
        }
        match ($problem) {
            'product' => $target->update(['markup_percent' => '30.00']),
            'user' => $user->forceFill(['anonymized_at' => now()])->save(),
            'authority' => Gate::define('upstream.mapping-review', fn (): bool => false),
        };
    };
    $exception = $problem === 'authority' ? AuthorizationException::class : ValidationException::class;
    expect(fn () => app(ApplyInstanceMappingReviewAction::class)->execute($instance, $actor, $id, $preview['review_token'], 'Reject racing changes', true))->toThrow($exception);
    expect(InstanceMappingReview::count())->toBe(0);
})->with(['product', 'user', 'authority']);

it('requires owner current password explicit consent and stable identity for price confirmation', function (): void {
    [$user,$target,$instance,$actor] = mappingReviewFixture();
    mappingReviewApply($instance, $target, $actor);
    $instance->refresh();
    $id = (string) Str::ulid();
    $preview = app(PreviewInstanceMappingPriceAction::class)->execute($user, $instance, $id);
    $action = app(ConfirmInstanceMappingPriceAction::class);
    expect(fn () => $action->execute($user, $instance, $id, $preview['review_token'], 'wrong', true))->toThrow(ValidationException::class);
    expect(fn () => $action->execute($user, $instance, $id, $preview['review_token'], 'Mapping-Password-123', false))->toThrow(ValidationException::class);
    expect(fn () => $action->execute($user, $instance, (string) Str::ulid(), $preview['review_token'], 'Mapping-Password-123', true))->toThrow(ValidationException::class);
    expect(fn () => app(PreviewInstanceMappingPriceAction::class)->execute($actor, $instance, (string) Str::ulid()))->toThrow(HttpException::class);
    expect(InstanceMappingReview::count())->toBe(1)->and($instance->fresh()->recurring_amount)->toBe('12.00');
});

it('rolls the mapping back if its audit cannot be persisted', function (): void {
    [$user,$target,$instance,$actor] = mappingReviewFixture();
    $id = (string) Str::ulid();
    $preview = app(InspectInstanceMappingAction::class)->execute($instance, $target, $actor, $id);
    DB::statement("CREATE TRIGGER reject_mapping_audit BEFORE INSERT ON audit_logs WHEN NEW.action = 'instance.mapping_review.applied' BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
    expect(fn () => app(ApplyInstanceMappingReviewAction::class)->execute($instance, $actor, $id, $preview['review_token'], 'Audit must be atomic', true))->toThrow(QueryException::class);
    expect(InstanceMappingReview::count())->toBe(0)->and($instance->fresh()->active_config['mapping_requires_review'])->toBeTrue()
        ->and($instance->fresh()->product_id)->not->toBe($target->id);
});

it('uses direct supplementary IDs for nonupgradeable options without guessing display names', function (): void {
    [$user,$target,$instance,$actor,$state] = mappingReviewFixture();
    $state->catalog['config_groups'][0]['options'][0]['upgrade'] = 0;
    $state->actual = [];
    $state->extra = [['configid' => 50, 'optionid' => 501, 'qty' => 1]];
    app(SyncClassicCatalogAction::class)->execute($target, 'Synchronize nonupgradeable direct ID evidence');
    $review = mappingReviewApply($instance, $target, $actor);
    expect($review->snapshot['resolved_pairs'][0]['upstream_sub_id'])->toBe(501)
        ->and($review->snapshot['new_recurring_amount'])->toBe('10.80');
});

it('proves complete zero option products without invoking the partial upgrade endpoint', function (): void {
    [$user,$target,$instance,$actor,$state] = mappingReviewFixture();
    $state->catalog['config_groups'] = [];
    app(SyncClassicCatalogAction::class)->execute($target, 'Synchronize explicitly empty configuration');
    $review = mappingReviewApply($instance, $target, $actor);
    expect($review->snapshot['resolved_pairs'])->toBe([])->and($review->snapshot['new_recurring_amount'])->toBe('9.60')
        ->and(collect($state->calls)->filter(fn (array $call): bool => $call[1] === '/upgrade/index/99'))->toBeEmpty();
});

it('calculates quantity and disabled toggle prices from exact complete actual quantities', function (string $type): void {
    [$user,$target,$instance,$actor,$state] = mappingReviewFixture();
    $option = &$state->catalog['config_groups'][0]['options'][0];
    $option['sub'] = [$option['sub'][0]];
    $option['option_type'] = $type === 'quantity' ? 4 : 3;
    if ($type === 'quantity') {
        $option['qty_minimum'] = 0;
        $option['qty_maximum'] = 10;
        $option['qty_step'] = 1;
        $option['sub'][0]['qty_minimum'] = 0;
        $option['sub'][0]['qty_maximum'] = 10;
    }
    $state->actual = [['oid' => 50, 'subid' => $type === 'quantity' ? 501 : 0, 'qty' => $type === 'quantity' ? 2 : 0,
        'option_type' => $option['option_type']]];
    app(SyncClassicCatalogAction::class)->execute($target, 'Synchronize exact quantity semantics');
    $review = mappingReviewApply($instance, $target, $actor);
    expect($review->snapshot['resolved_pairs'][0]['qty'])->toBe($type === 'quantity' ? 2 : 0)
        ->and($review->snapshot['new_recurring_amount'])->toBe($type === 'quantity' ? '12.00' : '9.60')
        ->and($review->snapshot['catalog_cost'])->toBe($type === 'quantity' ? '10.00' : '8.00');
})->with(['quantity', 'toggle']);

it('rejects price confirmation if the catalog exact price or certified cost changed after preview', function (string $problem): void {
    [$user,$target,$instance,$actor,$state] = mappingReviewFixture();
    mappingReviewApply($instance, $target, $actor);
    $instance->refresh();
    $id = (string) Str::ulid();
    $preview = app(PreviewInstanceMappingPriceAction::class)->execute($user, $instance, $id);
    match ($problem) {
        'catalog' => $target->update(['local_prices' => ['monthly' => ['price' => '99.00', 'enabled' => true]]]),
        'cost' => $state->cost = '10.00',
        'actual' => $state->actual[0]['subid'] = 502,
        'invoice' => Invoice::factory()->create(['user_id' => $user->id, 'instance_id' => $instance->id]),
        'expires' => $this->travel(5)->minutes(),
    };
    expect(fn () => app(ConfirmInstanceMappingPriceAction::class)->execute($user, $instance, $id, $preview['review_token'], 'Mapping-Password-123', true))
        ->toThrow(ValidationException::class);
    expect($instance->fresh()->recurring_amount)->toBe('12.00')->and($instance->fresh()->active_config['price_requires_review'])->toBeTrue()
        ->and(InstanceMappingReview::count())->toBe(1);
})->with(['catalog', 'cost', 'actual', 'invoice', 'expires']);

it('completes the real admin check apply and owner preview password confirmation HTTP journey', function (): void {
    [$user, $target, $instance, $actor, $state] = mappingReviewFixture();
    $adminPath = '/admin/upstream-instances/'.$instance->id.'/mapping-review';
    $ownerPath = '/console/instances/'.$instance->id.'/mapping-price';
    $calls = count($state->calls);
    $this->actingAs($actor)->get($adminPath)->assertOk()->assertSee('检查完整映射')->assertHeader('Cache-Control', 'no-store, private');
    expect(count($state->calls))->toBe($calls);
    $id = (string) Str::ulid();
    $checked = $this->post($adminPath.'/check', ['request_id' => $id, 'target_product_id' => $target->id]);
    $checked->assertOk()->assertSee('10.80')->assertSee('等待客户本人');
    $preview = $checked->viewData('preview');
    $this->post($adminPath, ['request_id' => $id, 'review_token' => $preview['review_token'], 'reason' => 'HTTP complete mapping evidence', 'confirm' => '1'])
        ->assertRedirect($adminPath);
    $this->actingAs($user)->withSession(['password_hash_web' => $user->password])->get('/console/instances/'.$instance->id)
        ->assertOk()->assertSee($ownerPath, false)->assertSee('未来价格基线待本人确认');
    $calls = count($state->calls);
    $this->get($ownerPath)->assertOk()->assertSee('预览完整配置与未来续费价格');
    expect(count($state->calls))->toBe($calls);
    $priceId = (string) Str::ulid();
    $quoted = $this->post($ownerPath.'/preview', ['request_id' => $priceId, 'amount' => '0.01']);
    $quoted->assertOk()->assertSee('10.80')->assertSee('1.20')->assertSee('当前密码');
    $price = $quoted->viewData('preview');
    $this->post($ownerPath, ['request_id' => $priceId, 'review_token' => $price['review_token'], 'current_password' => 'wrong', 'confirm' => '1'])
        ->assertRedirect($ownerPath)->assertSessionHasErrors('current_password');
    expect(session()->getOldInput())->not->toHaveKey('current_password');
    $this->post($ownerPath, ['request_id' => $priceId, 'review_token' => $price['review_token'], 'current_password' => 'Mapping-Password-123',
        'confirm' => '1', 'recurring_amount' => '0.01', 'target_product_id' => 999999])->assertRedirect($ownerPath);
    expect($instance->fresh()->recurring_amount)->toBe('10.80')->and(InstanceMappingReview::count())->toBe(2);
    $this->get($ownerPath)->assertOk()->assertSee('当前未来续费价格已核对');
});

it('protects admin authority and owner identity before performing any remote HTTP reads', function (): void {
    [$user, $target, $instance, $actor, $state] = mappingReviewFixture();
    $calls = count($state->calls);
    $this->actingAs($user)->get('/admin/upstream-instances/'.$instance->id.'/mapping-review')->assertForbidden();
    $this->post('/admin/upstream-instances/'.$instance->id.'/mapping-review/check', ['request_id' => (string) Str::ulid(), 'target_product_id' => $target->id])->assertForbidden();
    $other = User::factory()->create();
    $this->actingAs($other)->withSession(['password_hash_web' => $other->password])->get('/console/instances/'.$instance->id.'/mapping-price')->assertForbidden();
    $this->post('/console/instances/'.$instance->id.'/mapping-price/preview', ['request_id' => (string) Str::ulid()])->assertForbidden();
    expect(count($state->calls))->toBe($calls);
});

it('keeps mapping and price mutations behind real CSRF verification', function (): void {
    [$user, $target, $instance, $actor, $state] = mappingReviewFixture();
    $calls = count($state->calls);
    $this->app->detectEnvironment(fn (): string => 'local');
    $this->actingAs($actor)->post('/admin/upstream-instances/'.$instance->id.'/mapping-review/check', [
        'request_id' => (string) Str::ulid(), 'target_product_id' => $target->id])->assertStatus(419);
    $this->actingAs($user)->withSession(['password_hash_web' => $user->password])->post('/console/instances/'.$instance->id.'/mapping-price', [
        'request_id' => (string) Str::ulid(), 'confirm' => '1', 'current_password' => 'Mapping-Password-123'])->assertStatus(419);
    expect(count($state->calls))->toBe($calls)->and(InstanceMappingReview::count())->toBe(0);
});

it('enforces the independent sensitive price confirmation rate limit', function (): void {
    [$user, $target, $instance, $actor, $state] = mappingReviewFixture();
    $calls = count($state->calls);
    $this->actingAs($user);
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $this->post('/console/instances/'.$instance->id.'/mapping-price', [])->assertRedirect();
    }
    $this->post('/console/instances/'.$instance->id.'/mapping-price', [])->assertStatus(429);
    expect(count($state->calls))->toBe($calls);
});

it('completes hot swap original resource disposition callback registration mapping and owner pricing as one journey', function (): void {
    [$owner, $target, $instance, $actor, $state, $invoice] = mappingReviewFixture();
    Gate::define('upstream.hotswap', fn (User $candidate): bool => $candidate->id === $actor->id);
    Gate::define('upstream.orphan-reconcile', fn (User $candidate): bool => $candidate->id === $actor->id);
    $instance->update(['upstream_instance_id' => '88']);
    $original = InstanceUpstreamBinding::where('instance_id', $instance->id)->sole();
    $original->update(['upstream_instance_id' => '88']);
    $invoiceBefore = $invoice->fresh()->getRawOriginal();
    app(HotSwapInstanceDriverAction::class)->execute($instance, $target->upstream_api_id, '99', '17', 'keep_orphan',
        'Move this service to the authenticated target and retain the original resource for explicit disposition', $actor, true);
    $case = ReconciliationCase::where('instance_upstream_binding_id', $original->id)->sole();
    expect($case->status)->toBe('open')->and(app(BindingReconciliationCases::class)->hasUnresolvedForUser($owner->id))->toBeTrue();
    $newBinding = InstanceUpstreamBinding::where('instance_id', $instance->id)->whereNull('unbound_at')->sole();
    expect($newBinding->source_snapshot['base_url'])->toBe('https://classic.test')
        ->and($newBinding->source_snapshot['upstream_instance_id'])->toBe('99')->and($original->fresh()->source_snapshot)->toBeNull();
    $task = FulfillmentTask::where('instance_id', $instance->id)->where('action', 'register_callback')->sole();
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS);
    $closed = app(ReconcileBindingOrphanAction::class)->execute($case, $actor, [
        'request_id' => (string) Str::ulid(), 'expected_revision' => $case->revision, 'operation' => 'transfer_verified',
        'reason' => 'Verified completed transfer of the original retained resource', 'evidence' => 'PRIVATE-ORIGINAL-HOST-TRANSFER',
        'recipient' => 'Original supplier confirmed recipient', 'target_resource' => 'Original host 88',
        'acceptance_reference' => 'PRIVATE-RESPONSIBILITY-ACCEPTANCE', 'accepted_at' => now()->subMinute()->toIso8601String(),
        'responsibility_accepted' => '1', 'confirm' => '1']);
    expect(app(BindingReconciliationCases::class)->isResolved($closed))->toBeTrue();
    $mapping = mappingReviewApply($instance->fresh(), $target, $actor);
    $instance->refresh();
    expect($instance->active_config['price_requires_review'])->toBeTrue()->and($instance->recurring_amount)->toBe('12.00');
    $requestId = (string) Str::ulid();
    $preview = app(PreviewInstanceMappingPriceAction::class)->execute($owner, $instance, $requestId);
    app(ConfirmInstanceMappingPriceAction::class)->execute($owner, $instance, $requestId, $preview['review_token'], 'Mapping-Password-123', true);
    $instance->refresh();
    $options = app(ResolveProductOptionsAction::class)->execute($target, 'monthly', ['configoption' => [50 => 502]], false);
    expect($instance->active_config['mapping_review_id'])->toBe($mapping->id)->and($instance->active_config['mapping_requires_review'])->toBeFalse()
        ->and($instance->active_config['price_requires_review'])->toBeFalse()->and($instance->recurring_amount)->toBe('10.80')
        ->and(app(UpgradeCalculatorAction::class)->execute($instance, $options))->toBe('13.20')
        ->and($invoice->fresh()->getRawOriginal())->toBe($invoiceBefore)->and($original->fresh()->upstream_disposition)->toBe('kept_orphan')
        ->and(app(BindingReconciliationCases::class)->hasUnresolvedForUser($owner->id))->toBeFalse();
    expect(collect($state->calls)->filter(fn (array $call): bool => $call[0] === 'POST')
        ->every(fn (array $call): bool => in_array($call[1], ['/zjmf_api_login', '/host/setdownstream'], true)))->toBeTrue();
});
