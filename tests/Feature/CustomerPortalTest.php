<?php

declare(strict_types=1);

use App\Actions\ConfigureIdentityAction;
use App\Enums\BillingCycle;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Models\UserOauthAccount;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Laravel\Socialite\Facades\Socialite;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);
beforeEach(fn () => $this->installApplicationForTest());

it('provides separate registration and login pages and creates only an ordinary unverified customer', function (): void {
    $this->get('/register')->assertOk()->assertSee('注册账户')->assertSee('autocomplete="new-password"', false);
    $this->get('/login')->assertOk()->assertSee('注册新账户')->assertSee('记住登录');
    $this->post('/register', ['name' => 'New Customer', 'email' => 'Buyer@Example.Test', 'password' => 'Customer-Password-123',
        'password_confirmation' => 'Customer-Password-123', 'credit' => '9999.00', 'phone' => '+8613800138000',
        'email_verified_at' => now()->toDateTimeString(), 'kyc_verified_at' => now()->toDateTimeString(), 'role' => 'cms-publisher'])->assertRedirect('/console');
    $user = User::sole();
    expect($user->email)->toBe('buyer@example.test')->and($user->credit)->toBe('0.00')->and($user->phone)->toBeNull()
        ->and($user->email_verified_at)->toBeNull()->and($user->kyc_verified_at)->toBeNull()->and($user->getRoleNames()->all())->toBe([]);
    expect(Hash::check('Customer-Password-123', $user->password))->toBeTrue();
    $this->get('/console')->assertOk()->assertSee('邮箱待验证');
    $this->assertDatabaseHas('audit_logs', ['action' => 'identity.register', 'subject_id' => $user->id]);
    expect(json_encode(DB::table('audit_logs')->get()))->not->toContain('Customer-Password-123');
});

it('does not reuse an existing mailbox with different casing including a soft deleted account', function (bool $deleted): void {
    $user = User::factory()->create(['email' => 'Legacy@Example.Test']);
    if ($deleted) {
        $user->delete();
    }
    $this->post('/register', ['name' => 'Duplicate', 'email' => 'legacy@example.test', 'password' => 'Customer-Password-123',
        'password_confirmation' => 'Customer-Password-123'])->assertSessionHasErrors('email');
    expect(User::withTrashed()->count())->toBe(1);
    $this->assertGuest();
})->with([false, true]);

it('logs into legacy mixed case mailboxes with remember login and will not replace an authenticated account', function (): void {
    $user = User::factory()->create(['email' => 'Legacy@Example.Test', 'password' => 'Customer-Password-123']);
    $this->post('/login', ['email' => 'legacy@example.test', 'password' => 'Customer-Password-123', 'remember' => 1])->assertRedirect('/console')
        ->assertCookie(Auth::guard()->getRecallerName());
    $this->assertAuthenticatedAs($user);
    $this->get('/register')->assertRedirect('/console');
    $this->post('/register', ['name' => 'Other', 'email' => 'other@example.test', 'password' => 'Customer-Password-123',
        'password_confirmation' => 'Customer-Password-123'])->assertRedirect('/console');
    expect(User::count())->toBe(1);
});

it('rejects incorrect passwords deleted users and ambiguous legacy identities with the same login error', function (string $case): void {
    $user = User::factory()->create(['email' => 'Legacy@Example.Test', 'password' => 'Customer-Password-123']);
    if ($case === 'deleted') {
        $user->delete();
    } elseif ($case === 'ambiguous') {
        // SQLite permits the historical collision; authentication must not choose an arbitrary user.
        User::factory()->create(['email' => 'legacy@example.test', 'password' => 'Customer-Password-123']);
    }
    $this->post('/login', ['email' => 'legacy@example.test', 'password' => $case === 'password' ? 'incorrect-password' : 'Customer-Password-123'])
        ->assertSessionHasErrors(['email' => '邮箱或密码错误。']);
    $this->assertGuest();
})->with(['password', 'deleted', 'ambiguous']);

it('requires login for customer pages and never serves their contents to anonymous JSON requests', function (): void {
    $invoice = Invoice::factory()->create();
    $instance = Instance::factory()->create();
    foreach (['/console', '/console/invoices', '/console/instances', '/console/account', '/console/invoices/'.$invoice->id, '/console/instances/'.$instance->id] as $path) {
        $this->get($path)->assertRedirect('/login');
        $this->getJson($path)->assertUnauthorized();
    }
});

it('continues the original signed email verification after logging in from a fresh browser session', function (): void {
    $user = User::factory()->unverified()->create(['password' => 'Customer-Password-123']);
    $path = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), ['id' => $user->id, 'hash' => sha1($user->email)], absolute: false);
    $this->get($path)->assertRedirect('/login');
    $this->post('/login', ['email' => $user->email, 'password' => 'Customer-Password-123'])->assertRedirect($path);
    $this->get($path)->assertRedirect('/console/account');
    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

it('returns only to allowed local customer paths after authentication', function (string $intended, string $expected): void {
    $user = User::factory()->create(['password' => 'Customer-Password-123']);
    $this->withSession(['url.intended' => $intended])->post('/login', ['email' => $user->email, 'password' => 'Customer-Password-123'])->assertRedirect($expected);
    expect(session()->has('url.intended'))->toBeFalse();
})->with([
    ['https://foreign.example/steal', '/console'],
    ['/admin/content', '/console'],
    ['//foreign.example/steal', '/console'],
    ['/console/invoices?status=Paid', '/console/invoices?status=Paid'],
]);

it('makes all historical invoices reachable by pagination without exposing another user or private snapshots', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['name' => 'Shared catalog product']);
    $records = Invoice::factory()->count(61)->create(['user_id' => $user->id, 'product_id' => $product->id,
        'line_items' => ['purchase_secret' => 'PRIVATE-PURCHASE-SNAPSHOT']]);
    $foreign = Invoice::factory()->create(['product_id' => $product->id, 'invoice_no' => 'FOREIGN-INVOICE-ONLY']);
    $first = $this->actingAs($user)->get('/console/invoices')->assertOk()->assertSee($records->last()->invoice_no)
        ->assertDontSee($records->first()->invoice_no)->assertDontSee($foreign->invoice_no)->assertDontSee('PRIVATE-PURCHASE-SNAPSHOT');
    expect($first->viewData('invoices')->total())->toBe(61)->and($first->viewData('invoices')->count())->toBe(20);
    $this->get('/console/invoices?page=4')->assertOk()->assertSee($records->first()->invoice_no);
    $filtered = $this->get('/console/invoices?q=Shared&user_id='.$foreign->user_id)->assertOk()->assertDontSee($foreign->invoice_no);
    expect($filtered->viewData('invoices')->total())->toBe(61);
});

it('keeps invoice status and literal wildcard searches within the current customer scope', function (): void {
    $user = User::factory()->create();
    $one = Invoice::factory()->paid()->create(['user_id' => $user->id, 'invoice_no' => 'OWN-100%-PAID']);
    $two = Invoice::factory()->create(['user_id' => $user->id, 'invoice_no' => 'OWN-UNPAID']);
    $foreign = Invoice::factory()->paid()->create(['invoice_no' => 'FOREIGN-100%-PAID']);
    $this->actingAs($user)->get('/console/invoices?status=Paid')->assertOk()->assertSee($one->invoice_no)->assertDontSee($two->invoice_no)->assertDontSee($foreign->invoice_no);
    $response = $this->get('/console/invoices?q=%25')->assertOk()->assertSee($one->invoice_no)->assertDontSee($two->invoice_no);
    expect($response->viewData('invoices')->total())->toBe(1);
});

it('paginates services beyond fifty and scopes name IP and product searches without listing delivered secrets', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create(['name' => 'Common product']);
    $instances = Instance::factory()->count(51)->active()->create(['user_id' => $user->id, 'product_id' => $product->id,
        'encrypted_credentials' => ['card_key' => 'PRIVATE-CARD-SECRET', 'password' => 'PRIVATE-HOST-PASSWORD']]);
    $foreign = Instance::factory()->active()->create(['display_name' => 'FOREIGN-SERVICE-ONLY', 'product_id' => $product->id, 'primary_ip' => '192.0.2.8']);
    $this->actingAs($user)->get('/console/instances')->assertOk()->assertSee($instances->last()->display_name)
        ->assertDontSee('PRIVATE-CARD-SECRET')->assertDontSee('PRIVATE-HOST-PASSWORD')->assertDontSee($foreign->display_name);
    $this->get('/console/instances?page=3')->assertOk()->assertSee($instances->first()->display_name);
    $instances->first()->update(['display_name' => 'MY-OLDEST-SERVICE', 'primary_ip' => '192.0.2.8']);
    $this->get('/console/instances?q=192.0.2.8')->assertOk()->assertSee('MY-OLDEST-SERVICE')->assertDontSee($foreign->display_name);
    $response = $this->get('/console/instances?q=Common&user_id='.$foreign->user_id)->assertOk()->assertDontSee($foreign->display_name);
    expect($response->viewData('instances')->total())->toBe(51);
});

it('shows only owner totals and recent records on the dashboard without card or host credentials', function (): void {
    $owner = User::factory()->create();
    $invoice = Invoice::factory()->create(['user_id' => $owner->id]);
    $instance = Instance::factory()->active()->create(['user_id' => $owner->id, 'encrypted_credentials' => ['card_key' => 'PRIVATE-CARD']]);
    Invoice::factory()->create(['invoice_no' => 'OTHER-PERSON-INVOICE']);
    Instance::factory()->create(['display_name' => 'OTHER-PERSON-SERVICE']);
    $response = $this->actingAs($owner)->get('/console')->assertOk()->assertSee($invoice->invoice_no)->assertSee($instance->display_name)
        ->assertDontSee('PRIVATE-CARD')->assertDontSee('OTHER-PERSON-INVOICE')->assertDontSee('OTHER-PERSON-SERVICE');
    expect($response->viewData('invoiceCount'))->toBe(1)->and($response->viewData('instanceCount'))->toBe(1)
        ->and($response->viewData('unpaidCount'))->toBe(1)->and($response->viewData('activeCount'))->toBe(1);
});

it('validates customer search parameters rather than accepting arbitrary statuses or nested query inputs', function (): void {
    $this->actingAs(User::factory()->create());
    $this->getJson('/console/invoices?status=unknown')->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->getJson('/console/instances?q[]=nested')->assertUnprocessable()->assertJsonValidationErrors('q');
    $this->getJson('/console/instances?page=-1')->assertUnprocessable()->assertJsonValidationErrors('page');
});

it('shows the historical customer invoice amounts and a safe item projection without upstream costs or snapshots', function (): void {
    $user = User::factory()->create();
    $invoice = Invoice::factory()->paid()->create(['user_id' => $user->id, 'total_amount' => '22.00', 'discount_amount' => '3.00',
        'credit_applied' => '7.00', 'gateway_amount' => '15.00', 'upstream_expected_cost' => '18.73',
        'line_items' => ['items' => [['kind' => 'product', 'label' => '<b>Customer item</b>', 'qty' => 1, 'unit_price' => '25.00', 'amount' => '25.00',
            'provider_debug' => 'PRIVATE-DEBUG-SECRET'], ['kind' => 'discount', 'label' => 'Coupon', 'qty' => 1, 'unit_price' => '-3.00', 'amount' => '-3.00']],
            'encrypted_purchase_payload' => 'PRIVATE-PURCHASE-SECRET']]);
    $this->actingAs($user)->get('/console/invoices/'.$invoice->id)->assertOk()->assertSee('22.00')->assertSee('15.00')->assertSee('-3.00')
        ->assertSee('&lt;b&gt;Customer item&lt;/b&gt;', false)->assertDontSee('<b>Customer item</b>', false)
        ->assertDontSee('18.73')->assertDontSee('PRIVATE-DEBUG-SECRET')->assertDontSee('PRIVATE-PURCHASE-SECRET');
    expect($invoice->fresh()->total_amount)->toBe('22.00');
});

it('never links another customer service from an inconsistent invoice and denies cross-account details and operations', function (): void {
    Http::fake();
    $owner = User::factory()->create();
    $foreign = Instance::factory()->active()->create(['display_name' => 'FOREIGN-RESOURCE']);
    $invoice = Invoice::factory()->create(['user_id' => $owner->id, 'instance_id' => $foreign->id]);
    $this->actingAs($owner)->get('/console/invoices/'.$invoice->id)->assertOk()->assertDontSee('FOREIGN-RESOURCE')
        ->assertDontSee('/console/instances/'.$foreign->id, false);
    $this->get('/console/instances/'.$foreign->id)->assertForbidden();
    $foreignInvoice = Invoice::factory()->create();
    $this->get('/console/invoices/'.$foreignInvoice->id)->assertForbidden();
    $this->post('/console/invoices/'.$foreignInvoice->id.'/pay')->assertForbidden();
    $this->post('/console/instances/'.$foreign->id.'/operations', ['action' => 'terminate'])->assertForbidden();
    Http::assertNothingSent();
});

it('renders service delivery and only owner invoices without contacting upstream on a GET', function (): void {
    Http::fake();
    $user = User::factory()->create();
    $instance = Instance::factory()->active()->create(['user_id' => $user->id, 'billing_cycle' => BillingCycle::ONETIME,
        'encrypted_credentials' => ['card_key' => '<OWNER-SECRET>']]);
    $invoice = Invoice::factory()->paid()->create(['user_id' => $user->id, 'instance_id' => $instance->id]);
    Invoice::factory()->create(['instance_id' => $instance->id, 'invoice_no' => 'FOREIGN-RELATED-INVOICE']);
    $this->actingAs($user)->get('/console/instances/'.$instance->id)->assertOk()->assertSee('&lt;OWNER-SECRET&gt;', false)
        ->assertSee($invoice->invoice_no)->assertDontSee('FOREIGN-RELATED-INVOICE')->assertHeader('Cache-Control', 'no-store, private');
    Http::assertNothingSent();
    expect($instance->fresh()->status)->toBe(InstanceStatus::ACTIVE)->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID);
});

it('resets a legacy mixed case mailbox using the same lookup policy as login', function (): void {
    $user = User::factory()->create(['email' => 'Legacy@Example.Test', 'password' => 'Original-Password-123']);
    $token = Password::broker()->createToken($user);
    $this->post('/reset-password', ['email' => 'legacy@example.test', 'token' => $token, 'password' => 'Changed-Password-456',
        'password_confirmation' => 'Changed-Password-456'])->assertRedirect('/login');
    expect(Hash::check('Changed-Password-456', $user->fresh()->password))->toBeTrue();
});

it('returns a bound third-party login to the requested customer page and keeps failed callbacks on local pages', function (): void {
    app(ConfigureIdentityAction::class)->execute(['oauth.google_enabled' => true, 'oauth.google_client_id' => 'client', 'oauth.google_client_secret' => 'secret'], 'Isolated OAuth navigation');
    $client = new Client(['handler' => HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode(['access_token' => 'opaque-token'])), new Response(200, [], json_encode(['sub' => 'bound-subject'])),
    ]))]);
    Socialite::shouldReceive('buildProvider')->andReturnUsing(fn ($class, $config) => (new $class(app('request'), $config['client_id'], $config['client_secret'], $config['redirect']))->setHttpClient($client));
    $user = User::factory()->create();
    UserOauthAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_subject' => 'bound-subject']);
    $this->get('/console/invoices')->assertRedirect('/login');
    $this->post('/auth/google/redirect')->assertRedirect();
    $this->get('/auth/google/callback?'.http_build_query(['state' => session('state'), 'code' => 'one-use-code']))->assertRedirect('/console/invoices');
    $this->assertAuthenticatedAs($user);
    $this->post('/logout');
    $this->get('/auth/google/callback?state=replay&code=replay')->assertRedirect('/login')->assertSessionHasErrors('operation');
});
