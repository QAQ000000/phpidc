<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\AssignCmsRoleAction;
use App\Actions\DispatchClassicCatalogAction;
use App\Actions\DispatchInstanceLifecycleAction;
use App\Actions\ProbeClassicCatalogAction;
use App\Actions\ProcessInstanceLifecycleAction;
use App\Actions\ReadInstanceTelemetryAction;
use App\Actions\SaveCmsArticleAction;
use App\Actions\SaveCmsCategoryAction;
use App\Drivers\Notification\LogMailDriver;
use App\Enums\DriverType;
use App\Jobs\ProcessInstanceLifecycleJob;
use App\Jobs\SendEventNotificationJob;
use App\Jobs\SendNotificationJob;
use App\Models\Category;
use App\Models\CmsArticle;
use App\Models\CmsCategory;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\NotificationTemplate;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Services\SettingService;
use App\Support\CmsRenderer;
use App\Support\MachineOperation;
use App\Support\RetryableNotificationException;
use App\Support\UncertainNotificationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
});

function remediationSetting(string $key, string $value): void
{
    [$group, $name] = explode('.', $key, 2);
    DB::table('system_settings')->updateOrInsert(['group' => $group, 'key' => $name], ['value' => $value, 'is_encrypted' => false]);
    app(SettingService::class)->forget($key);
}

function remediationQueue(): void
{
    config(['queue.default' => 'database', 'queue.connections.database.after_commit' => false,
        'queue.failed.driver' => 'database-uuids', 'queue.failed.database' => config('database.default')]);
}

function remediationTelegramJob(): SendEventNotificationJob
{
    $user = User::factory()->create();
    $binding = UserNotificationBinding::create(['user_id' => $user->id, 'channel' => 'telegram', 'target' => 'test-chat', 'verified_at' => now()]);
    remediationSetting('telegram.bot_token', 'PRIVATE-TEST-TOKEN');

    return new SendEventNotificationJob($user->id, 'renewal.invoice', 'telegram', 'test-chat', [], $binding->id);
}

it('uses separate real middleware quotas and shares each user quota across IPs', function (): void {
    $product = Product::factory()->create();
    $instance = Instance::factory()->create();
    $this->actingAs($instance->user);
    for ($n = 0; $n < 30; $n++) {
        $this->postJson('/console/products/'.$product->id.'/quote', [])->assertUnprocessable();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
        ->postJson('/console/products/'.$product->id.'/quote', [])->assertTooManyRequests()->assertHeader('Retry-After');
    $this->postJson('/console/instances/'.$instance->id.'/power-status')->assertUnprocessable();
    for ($n = 1; $n < 10; $n++) {
        $this->postJson('/console/instances/'.$instance->id.'/power-status')->assertUnprocessable();
    }
    $this->postJson('/console/instances/'.$instance->id.'/power-status')->assertTooManyRequests();
    $this->post('/console/instances/'.$instance->id.'/power-status')->assertTooManyRequests()->assertSee('秒后手动重试');
    Http::assertNothingSent();
});

it('isolates guest login and registration quotas while sharing login quota for the same IP', function (): void {
    for ($n = 0; $n < 10; $n++) {
        $this->postJson('/login', [])->assertUnprocessable();
    }
    $this->postJson('/login', [])->assertTooManyRequests();
    $this->postJson('/register', [])->assertUnprocessable();
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.8'])->postJson('/login', [])->assertUnprocessable();
});

it('keeps category type and public prefix immutable while allowing equal slugs in news and help', function (): void {
    $category = app(SaveCmsCategoryAction::class)->execute(['type' => 'news', 'slug' => 'same', 'name' => 'News'], 'Initial category');
    app(SaveCmsCategoryAction::class)->execute(['type' => 'help', 'slug' => 'same', 'name' => 'Help'], 'Separate namespace');
    try {
        app(SaveCmsCategoryAction::class)->execute(['type' => 'help', 'slug' => 'changed', 'name' => 'Illegal move'], 'Tamper type', categoryId: $category->id);
        $this->fail('Type change must be rejected.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('type');
    }
    expect($category->fresh()->publicPath())->toBe('/news/same')->and(CmsCategory::count())->toBe(2);
});

it('dispatches bounded lifecycle work without HTTP and rotates past already queued services', function (): void {
    remediationQueue();
    remediationSetting('lifecycle.urgent_batch', '1');
    remediationSetting('lifecycle.rotation_batch', '2');
    $user = User::factory()->create();
    $product = Product::factory()->create();
    $instances = Instance::factory()->count(5)->active()->create(['user_id' => $user->id, 'product_id' => $product->id, 'next_due_at' => now()->addMonth()]);
    $instances[4]->update(['next_due_at' => null]);
    $instances[0]->update(['next_due_at' => now()->addHour()]);
    $dispatch = app(DispatchInstanceLifecycleAction::class);
    expect($dispatch->execute())->toBe(2)->and(DB::table('jobs')->count())->toBe(2);
    expect($dispatch->execute())->toBe(3)->and(DB::table('jobs')->count())->toBe(4);
    $dispatch->execute();
    expect(DB::table('jobs')->count())->toBe(5);
    $ids = DB::table('jobs')->get()->map(function ($row) {
        $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);
        expect($payload['data']['command'])->not->toContain('downstream_token');

        return unserialize($payload['data']['command'])->instanceId;
    });
    expect($ids->unique()->count())->toBe(5)->and($ids->all())->toContain($instances[4]->id);
    Http::assertNothingSent();
});

it('rejects synchronous dispatch configuration instead of blocking minute scheduling', function (): void {
    expect(fn () => app(DispatchInstanceLifecycleAction::class)->execute())->toThrow(LogicException::class)
        ->and(fn () => app(DispatchClassicCatalogAction::class)->execute())->toThrow(LogicException::class);
    Http::assertNothingSent();
});

it('drops queued lifecycle reads after a service binding changes', function (): void {
    $instance = Instance::factory()->active()->create();
    $job = new ProcessInstanceLifecycleJob($instance->id, app(MachineOperation::class)->binding($instance));
    $instance->update(['downstream_token' => 'changed-binding']);
    $action = Mockery::mock(ProcessInstanceLifecycleAction::class);
    $action->shouldNotReceive('process');
    $job->handle($action);
    Http::assertNothingSent();
});

it('bounds and rotates catalog dispatch and deduplicates queued products', function (): void {
    remediationQueue();
    remediationSetting('lifecycle.catalog_batch', '2');
    $account = UpstreamApi::factory()->create();
    Product::factory()->count(5)->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id]);
    $dispatch = app(DispatchClassicCatalogAction::class);
    expect($dispatch->execute())->toBe(2)->and($dispatch->execute())->toBe(2)->and($dispatch->execute())->toBe(1);
    $dispatch->execute();
    expect(DB::table('jobs')->count())->toBe(5)->and(DB::table('jobs')->where('queue', 'catalog')->count())->toBe(5);
    Http::assertNothingSent();
});

it('retries a confirmed Telegram rejection in the real database worker and then records acceptance', function (): void {
    remediationQueue();
    Http::fake(['*' => Http::sequence()->push(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 120]], 429)->push(['ok' => true])]);
    Queue::connection('database')->push(remediationTelegramJob());
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertExitCode(0);
    expect(DB::table('jobs')->sole()->attempts)->toBe(1);
    expect(DB::table('jobs')->sole()->available_at - now()->timestamp)->toBeGreaterThanOrEqual(119);
    $this->assertDatabaseHas('notification_logs', ['channel' => 'telegram', 'status' => 'failed', 'error_message' => 'Retryable rejection; attempt 1/3.']);
    $this->travel(121)->seconds();
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertExitCode(0);
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 0);
    $this->assertDatabaseHas('notification_logs', ['channel' => 'telegram', 'status' => 'sent']);
    Http::assertSentCount(2);
});

it('keeps exhausted retry evidence in failed_jobs without provider credentials', function (): void {
    remediationQueue();
    Http::fake(['*' => Http::response(['ok' => false, 'error_code' => 429, 'description' => 'PRIVATE-TEST-TOKEN'], 429)]);
    Queue::connection('database')->push(remediationTelegramJob());
    for ($n = 0; $n < 3; $n++) {
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertExitCode(0);
        $this->travel(301)->seconds();
    }
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('failed_jobs', 1);
    expect(DB::table('notification_logs')->count())->toBe(3)
        ->and(DB::table('failed_jobs')->sole()->exception)->not->toContain('PRIVATE-TEST-TOKEN', 'api.telegram.org');
    Http::assertSentCount(3);
});

it('does not replay permanent rejection or uncertain delivery', function (string $mode): void {
    remediationQueue();
    Http::fake(['*' => match ($mode) {
        'permanent' => Http::response(['ok' => false, 'error_code' => 403], 403),
        'uncertain' => Http::failedConnection('PRIVATE-TEST-TOKEN'),
    }]);
    Queue::connection('database')->push(remediationTelegramJob());
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertExitCode(0);
    $this->assertDatabaseCount('jobs', 0);
    $this->assertDatabaseCount('notification_logs', 1);
    $log = DB::table('notification_logs')->sole();
    expect($log->status)->toBe('failed')->and($log->error_message)->not->toContain('PRIVATE-TEST-TOKEN');
    if ($mode === 'uncertain') {
        expect($log->error_message)->toContain('uncertain', 'No automatic replay');
    }
})->with(['permanent', 'uncertain']);

it('caches excerpts by body and format and reuses already rendered safe detail HTML', function (): void {
    Cache::flush();
    $renderer = new class extends CmsRenderer
    {
        public int $renders = 0;

        public function html(CmsArticle $article): string
        {
            $this->renders++;

            return parent::html($article);
        }
    };
    $article = new CmsArticle(['body' => '**Alpha** <script>BAD()</script>', 'content_format' => 'markdown']);
    $first = $renderer->excerpt($article);
    expect($renderer->excerpt($article))->toBe($first)->and($renderer->renders)->toBe(1)->and($first)->not->toContain('<script>');
    $article->body = '<strong>Beta</strong><img src=x onerror=BAD()> <script>BAD()</script>';
    $article->content_format = 'html';
    $html = $renderer->html($article);
    expect($renderer->excerpt($article, $html))->toContain('Beta')->and($renderer->renders)->toBe(2)
        ->and($html)->not->toContain('onerror', '<script>');
    $article->body = '**Gamma**';
    $renderer->excerpt($article);
    $article->content_format = 'markdown';
    expect($renderer->excerpt($article))->toBe('Gamma')->and($renderer->renders)->toBe(4);
});

it('indexes in chunks using metadata only and excludes drafts future and hidden content', function (): void {
    $category = CmsCategory::create(['type' => 'help', 'slug' => 'bulk', 'name' => 'Bulk']);
    foreach (range(1, 205) as $n) {
        CmsArticle::create(['cms_category_id' => $category->id, 'title' => 'TITLE-'.$n, 'slug' => 'article-'.$n,
            'body' => str_repeat('PRIVATE-LARGE-BODY', 100), 'content_format' => 'markdown', 'is_published' => true, 'published_at' => now()->subMinute()]);
    }
    foreach (['draft', 'future'] as $kind) {
        CmsArticle::create(['cms_category_id' => $category->id, 'title' => $kind, 'slug' => $kind, 'body' => 'SECRET',
            'content_format' => 'markdown', 'is_published' => $kind === 'future', 'published_at' => now()->addDay()]);
    }
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $sitemap = $this->get('/sitemap.xml')->assertOk()->assertDontSee('/bulk/draft')->assertDontSee('/bulk/future')->assertDontSee('PRIVATE-LARGE-BODY');
    $xml = simplexml_load_string($sitemap->getContent());
    expect($xml)->not->toBeFalse()->and(count($xml->url))->toBe(210);
    $this->get('/llms.txt')->assertOk()->assertSee('TITLE-205')->assertDontSee('/bulk/draft')->assertDontSee('/bulk/future');
    $articleQueries = array_values(array_filter($queries, fn ($sql) => str_starts_with($sql, 'select') && preg_match('/from ["`]cms_articles["`]/', $sql) === 1));
    expect(count($articleQueries))->toBe(6);
    foreach ($articleQueries as $sql) {
        $select = explode(' from ', $sql)[0];
        expect($select)->not->toContain('body', '*', 'seo_description')->and($sql)->toContain('limit 100');
    }
});

it('caps dashboard product forms and excludes full catalog data from its projection', function (): void {
    Product::factory()->count(25)->create(['provisioning_rules' => ['hostname_mode' => 'optional', 'classic_catalog' => str_repeat('LARGE-CATALOG', 1000)]]);
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $response = $this->actingAs(User::factory()->create())->get('/console')->assertOk()->assertSee('精选商品')->assertSee('/store', false);
    expect($response->viewData('products'))->toHaveCount(6);
    foreach ($response->viewData('products') as $product) {
        expect($product->provisioning_rules)->not->toHaveKey('classic_catalog');
    }
    $products = array_values(array_filter($queries, fn ($sql) => str_starts_with($sql, 'select') && preg_match('/from ["`]products["`]/', $sql) === 1));
    expect($products)->toHaveCount(1)->and($products[0])->toContain('limit 6')->not->toContain('"products".*', 'classic_catalog');
});

function remediationMixedPanel(?Closure $hook = null): Instance
{
    $account = UpstreamApi::factory()->create();
    $product = Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id]);
    $instance = Instance::factory()->active()->create(['product_id' => $product->id, 'driver_type' => DriverType::ZJMF_API,
        'upstream_api_id' => $account->id, 'upstream_instance_id' => '91']);
    Http::fake(function ($request) use ($hook, $instance) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($hook !== null && ($response = $hook($path, $request, $instance)) !== null) {
            return $response;
        }

        return Http::response(match ($path) {
            '/zjmf_api_login' => ['status' => 200, 'jwt' => 'PRIVATE-READ-TOKEN'],
            '/host/header' => ['status' => 200, 'data' => ['host_data' => ['id' => 91, 'domainstatus' => 'Active'],
                'module_power_status' => true, 'module_chart' => [['type' => 'cpu', 'title' => 'CPU'], ['type' => 'memory', 'title' => 'RAM']],
                'module_client_area' => [['key' => 'information']]]],
            '/zjmf_api/provision/custom/content' => ['status' => 200, 'data' => ['html' => '<form method="post" action="https://panel.example.test/vhost/index.php?c=session&amp;a=login"><input type="hidden" name="username" value="test"><input type="hidden" name="passwd" value="PRIVATE-PANEL"></form>']],
            '/provision/default' => ['status' => 200, 'data' => ['status' => 'on']],
            '/provision/chart/91' => ['status' => 200, 'data' => ['chart_type' => 'line', 'unit' => '%', 'label' => ['Sample'], 'list' => [[['time' => '2026-11-18 00:00:00', 'value' => 8]]]]],
            default => throw new RuntimeException('Unexpected panel read.'),
        });
    });

    return $instance;
}

it('fetches only login data when the supplier also advertises power and charts', function (): void {
    $instance = remediationMixedPanel();
    $this->actingAs($instance->user)->post('/console/instances/'.$instance->id.'/panel-login')->assertOk()->assertSee('PRIVATE-PANEL');
    Http::assertSentCount(3);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/provision/default') || str_contains($r->url(), '/provision/chart'));
});

it('keeps successful charts when one chart fails and never requests login content for telemetry', function (): void {
    $instance = remediationMixedPanel(fn ($path, $request) => $path === '/provision/chart/91' && $request['type'] === 'cpu'
        ? Http::response(['status' => 500]) : null);
    $widgets = app(ReadInstanceTelemetryAction::class)->execute($instance->user, $instance);
    expect(collect($widgets)->keyBy('key')['cpu']['data']['available'])->toBeFalse()
        ->and(collect($widgets)->keyBy('key')['memory']['data']['available'])->toBeTrue();
    Http::assertSentCount(5);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/zjmf_api/provision/custom/content'));
});

it('rejects authenticated panel reads after account endpoint or enabled state changes', function (string $change): void {
    $instance = remediationMixedPanel(function ($path, $request, $instance) use ($change) {
        if ($path === '/zjmf_api/provision/custom/content') {
            $instance->upstreamApi->update(match ($change) {
                'endpoint' => ['base_url' => 'https://new.example.test'],
                'secret' => ['encrypted_secret' => 'ROTATED-SECRET'],
                'disabled' => ['is_active' => false],
            });
        }

        return null;
    });
    $this->actingAs($instance->user)->postJson('/console/instances/'.$instance->id.'/panel-login')->assertUnprocessable();
    $this->assertDatabaseCount('invoices', 0);
})->with(['endpoint', 'disabled', 'secret']);

it('continues catalog configuration after the first product fails and reports separate counts', function (): void {
    $accounts = UpstreamApi::factory()->count(2)->create();
    $products = collect();
    foreach ([[$accounts[0], 1], [$accounts[0], 2], [$accounts[1], 3]] as [$account, $pid]) {
        $products->push(Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id,
            'upstream_pid' => $pid, 'upstream_version' => 0]));
    }
    $before = $products[0]->fresh()->getRawOriginal();
    Http::fake(function ($request) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/cart/get_product_config') {
            $pid = (int) $request['pid'];

            return Http::response($pid === 1 ? ['status' => 400, 'message' => 'PRIVATE-RESPONSE'] : ['status' => 200, 'data' => [
                'products' => ['id' => $pid, 'name' => 'Cloud', 'location_version' => 1, 'password' => ['show' => 0], 'host' => ['show' => 0]],
                'product_pricings' => [['code' => 'CNY', 'monthly' => '1.00', 'msetupfee' => '0.00']], 'config_groups' => [], 'customfields' => [], 'advanced' => [],
            ]]);
        }

        return Http::response(match ($path) {
            '/zjmf_api_login' => ['status' => 200, 'jwt' => 'PRIVATE-PROBE'],
            '/api/product/proinfo' => ['status' => 200, 'data' => ['info' => [['id' => 1, 'location_version' => 1], ['id' => 2, 'location_version' => 1], ['id' => 3, 'location_version' => 1]]]],
            default => throw new RuntimeException('Unexpected catalog read.'),
        });
    });
    $action = app(ProbeClassicCatalogAction::class);
    expect($action->execute())->toBe(2)->and($action->summary)->toBe(['synced' => 2, 'products_failed' => 1, 'accounts_failed' => 0])
        ->and($products[0]->fresh()->getRawOriginal())->toBe($before)
        ->and($products[1]->fresh()->upstream_version)->toBe(1)->and($products[2]->fresh()->upstream_version)->toBe(1);
});

it('isolates expected supplier probe failure but exposes unknown programming errors', function (): void {
    $accounts = UpstreamApi::factory()->count(2)->create();
    foreach ($accounts as $n => $account) {
        Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id,
            'upstream_pid' => $n + 1, 'upstream_version' => 1, 'provisioning_rules' => ['classic_catalog' => ['version' => 1, 'product_publisher_uid' => 0]]]);
    }
    $calls = 0;
    Http::fake(function ($request) use (&$calls) {
        if (parse_url($request->url(), PHP_URL_PATH) === '/zjmf_api_login') {
            return Http::response(['status' => 200, 'jwt' => 'PRIVATE-PROBE']);
        }
        $calls++;

        return Http::response($calls === 1 ? ['status' => 400] : ['status' => 200, 'data' => ['info' => [['id' => 2, 'location_version' => 1, 'qty' => 23]]]]);
    });
    $action = app(ProbeClassicCatalogAction::class);
    expect($action->execute())->toBe(1)->and($action->summary)->toBe(['synced' => 1, 'products_failed' => 0, 'accounts_failed' => 1]);
    Http::fake(fn () => throw new LogicException('Unexpected programming fault'));
    expect(fn () => $action->execute())->toThrow(LogicException::class, 'Unexpected programming fault');
});

it('retries a legacy renewal job after confirmed rejection and honors provider retry_after', function (): void {
    remediationQueue();
    $event = remediationTelegramJob();
    $invoice = Invoice::factory()->create(['user_id' => $event->userId]);
    Http::fakeSequence()->push(['ok' => false, 'error_code' => 429, 'parameters' => ['retry_after' => 600]], 429)
        ->push(['ok' => true]);
    Queue::push(new SendNotificationJob($invoice->id, 'telegram', 'test-chat'));
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    $queued = DB::table('jobs')->sole();
    expect($queued->attempts)->toBe(1)->and($queued->available_at)->toBeGreaterThanOrEqual(now()->addSeconds(599)->timestamp);
    $this->travel(601)->seconds();
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    expect(DB::table('jobs')->count())->toBe(0)->and(DB::table('notification_logs')->orderBy('id')->pluck('status')->all())->toBe(['failed', 'sent']);
    Http::assertSentCount(2);
});

it('records an uncertain billable SMS without retrying or exposing supplier details', function (): void {
    remediationQueue();
    foreach (['sms.driver' => 'idcsmart', 'sms.idcsmart_api' => 'TEST-API', 'sms.idcsmart_key' => 'PRIVATE-SMS-KEY',
        'sms.idcsmart_endpoint' => 'https://sms.example.test/send.php', 'sms.idcsmart_sign' => '测试',
        'sms.idcsmart_templates' => '{"test":{"id":"123","content":"已生成续费账单"}}'] as $key => $value) {
        remediationSetting($key, $value);
    }
    $user = User::factory()->create();
    $binding = UserNotificationBinding::create(['user_id' => $user->id, 'channel' => 'sms', 'target' => '+8613800138000', 'verified_at' => now()]);
    NotificationTemplate::create(['event_key' => 'renewal.invoice', 'channel' => 'sms', 'external_template_id' => 'test', 'is_enabled' => true]);
    Http::fake(['*' => Http::failedConnection('PRIVATE-SMS-KEY')]);
    Queue::push(new SendEventNotificationJob($user->id, 'renewal.invoice', 'sms', $binding->target, [], $binding->id));
    $this->artisan('queue:work', ['connection' => 'database', '--once' => true, '--sleep' => 0])->assertSuccessful();
    expect(DB::table('jobs')->count())->toBe(0)->and(DB::table('notification_logs')->sole()->status)->toBe('failed')
        ->and(DB::table('notification_logs')->sole()->error_message)->toContain('Delivery uncertain', 'No automatic replay')->not->toContain('PRIVATE-SMS-KEY');
});

it('classifies SMTP reply codes without retaining raw credential-bearing exceptions', function (int $code): void {
    remediationSetting('mail.transport', 'smtp');
    $mailer = Mockery::mock();
    $mailer->shouldReceive('raw')->once()->andThrow(new UnexpectedResponseException('PRIVATE-SMTP-PASSWORD', $code));
    Mail::shouldReceive('build')->once()->andReturn($mailer);
    $send = fn () => app(LogMailDriver::class)->send('test@example.test', 'Test', 'Test');
    if ($code === 550) {
        expect($send())->toBeFalse();
    } else {
        try {
            $send();
            $this->fail('A classified exception is required.');
        } catch (RetryableNotificationException|UncertainNotificationException $e) {
            expect($e->getPrevious())->toBeNull()->and($e->getMessage())->not->toContain('PRIVATE-SMTP-PASSWORD');
            expect($e)->toBeInstanceOf($code === 450 ? RetryableNotificationException::class : UncertainNotificationException::class);
        }
    }
})->with([450, 550, 0]);

it('shows safe notification recovery classifications and user filters without historical exception or recipient text', function (): void {
    $actor = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($actor, 'notification-viewer', 'Review notification evidence');
    $user = User::factory()->create();
    foreach (['Retryable rejection; attempt 3/3.', 'Delivery uncertain; PRIVATE-ERROR', 'PRIVATE-HISTORICAL-EXCEPTION'] as $error) {
        DB::table('notification_logs')->insert(['user_id' => $user->id, 'event_key' => 'renewal.invoice', 'channel' => 'telegram',
            'target' => 'PRIVATE-RECIPIENT', 'status' => 'failed', 'error_message' => $error, 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->actingAs($actor)->get('/admin/notifications?user_id='.$user->id.'&delivery_status=failed')->assertOk()
        ->assertSee('临时拒绝：最多三次尝试')->assertSee('结果不确定：核对供应商记录')->assertDontSee('PRIVATE-ERROR')
        ->assertDontSee('PRIVATE-HISTORICAL-EXCEPTION')->assertDontSee('PRIVATE-RECIPIENT');
    $this->get('/admin/notifications?user_id='.$actor->id)->assertOk()->assertDontSee('临时拒绝：最多三次尝试');
});

it('caps catalog batches at two hundred and eventually dispatches every mapped product without duplicate queue entries', function (): void {
    remediationQueue();
    remediationSetting('lifecycle.catalog_batch', '999');
    $account = UpstreamApi::factory()->create();
    $category = Category::factory()->create();
    Product::factory()->count(205)->create(['category_id' => $category->id, 'driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id]);
    $action = app(DispatchClassicCatalogAction::class);
    expect($action->execute())->toBe(200)->and(DB::table('jobs')->count())->toBe(200);
    expect($action->execute())->toBe(5)->and(DB::table('jobs')->count())->toBe(205);
    expect($action->execute())->toBe(200)->and(DB::table('jobs')->count())->toBe(205);
    Http::assertNothingSent();
});

it('returns an explicit category validation error for tampering while missing articles remain 404', function (): void {
    $actor = User::factory()->create();
    app(AssignCmsRoleAction::class)->execute($actor, 'cms-publisher', 'CMS routing acceptance');
    $category = CmsCategory::create(['type' => 'help', 'slug' => 'original', 'name' => 'Original']);
    $other = CmsCategory::create(['type' => 'help', 'slug' => 'other', 'name' => 'Other']);
    $input = ['category_id' => $category->id, 'title' => 'Unchanged', 'slug' => 'original', 'body' => 'Original content',
        'content_format' => 'markdown', 'is_published' => '0', 'reason' => 'Original draft'];
    $article = app(SaveCmsArticleAction::class)->execute($category, $input, 'Original draft');
    $before = $article->fresh()->getRawOriginal();
    $audits = DB::table('audit_logs')->count();
    $this->actingAs($actor)->postJson('/admin/content/articles', array_replace($input, ['id' => $article->id,
        'category_id' => $other->id, 'body' => 'Unauthorized replacement']))->assertUnprocessable()->assertJsonValidationErrors('category_id');
    expect($article->fresh()->getRawOriginal())->toBe($before)->and(DB::table('audit_logs')->count())->toBe($audits);
    $this->postJson('/admin/content/articles', array_replace($input, ['id' => 999999]))->assertNotFound();
    $this->get('/admin/content?article='.$article->id)->assertOk();
});
