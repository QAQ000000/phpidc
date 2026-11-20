<?php

declare(strict_types=1);

use App\Actions\ConfigureIdentityAction;
use App\Contracts\SmsDriverInterface;
use App\Enums\DownloadAccessLevel;
use App\Models\AffiliateCommission;
use App\Models\AffiliateWithdrawal;
use App\Models\Coupon;
use App\Models\DownloadResource;
use App\Models\Instance;
use App\Models\KycVerification;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Models\UserOauthAccount;
use App\Services\DriverManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
    Queue::fake();
});

afterEach(function (): void {
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

function customerAccountOperationsUiXPath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

    return new DOMXPath($document);
}

function customerAccountOperationsUiKyc(): void
{
    app(ConfigureIdentityAction::class)->execute(['kyc.driver' => 'idcsmartali', 'kyc.enabled' => true,
        'kyc.idcsmartali_endpoint' => 'https://kyc.example.test/certapi.php', 'kyc.idcsmartali_api' => 'UI-PRIVATE-KYC-API',
        'kyc.idcsmartali_key' => 'UI-PRIVATE-KYC-KEY', 'kyc.idcsmartali_biz_code' => 'FACE'], 'Isolated customer account UI');
}

it('keeps account and operations routes behind the existing customer login', function (string $path): void {
    $this->get($path)->assertRedirect('/login');
})->with(['/console/account', '/console/coupons', '/console/affiliate', '/console/tickets', '/console/tickets/1']);

it('shows masked account facts and disabled channels without provider subjects or old secrets', function (): void {
    $owner = User::factory()->unverified()->create(['phone' => '+8613800138000']);
    UserOauthAccount::create(['user_id' => $owner->id, 'provider' => 'github', 'provider_subject' => 'UI-PRIVATE-OAUTH-SUBJECT']);
    UserNotificationBinding::create(['user_id' => $owner->id, 'channel' => 'telegram', 'target' => 'UI-PRIVATE-TG-TARGET', 'verified_at' => now()]);
    $foreign = KycVerification::create(['user_id' => User::factory()->create()->id, 'driver' => 'idcsmartali', 'external_id' => 'UI-PRIVATE-FOREIGN-KYC',
        'status' => 'pending', 'masked_summary' => ['name' => 'Foreign applicant']]);
    $response = $this->actingAs($owner)->withSession(['_old_input' => ['current_password' => 'UI-PRIVATE-PASSWORD', 'code' => 'UI-PRIVATE-CODE',
        'id_number' => 'UI-PRIVATE-ID-NUMBER']])->get('/console/account')->assertOk()
        ->assertSee($owner->email)->assertSee('真实短信通道尚未接入')->assertSee('实名认证服务尚未启用')
        ->assertSee('第三方邮箱相同不会自动合并账户')->assertSee('登录绑定与通知订阅分别管理')
        ->assertDontSee($owner->phone)->assertDontSee('UI-PRIVATE-OAUTH-SUBJECT')->assertDontSee('UI-PRIVATE-TG-TARGET')
        ->assertDontSee('UI-PRIVATE-PASSWORD')->assertDontSee('UI-PRIVATE-CODE')->assertDontSee('UI-PRIVATE-ID-NUMBER')
        ->assertDontSee($foreign->external_id)->assertDontSee('Foreign applicant')->assertDontSee($owner->getRawOriginal('password'));
    $xpath = customerAccountOperationsUiXPath($response->getContent());
    expect($xpath->query('//form[@action="/console/account/oauth/github/unlink"]//input[@name="current_password" and @type="password" and @required]'))->toHaveCount(1)
        ->and($xpath->query('//form[@action="/console/account/telegram/notifications"]//input[@name="enabled" and @value="0"]'))->toHaveCount(1)
        ->and($xpath->query('//form[@action="/console/account/phone/send"]'))->toHaveCount(0)
        ->and($xpath->query('//form[@action="/console/account/kyc"]'))->toHaveCount(0)
        ->and($xpath->query('//input[@type="password" and string-length(@value)>0]'))->toHaveCount(0);
});

it('retains verified email and current password conditions for linking configured providers', function (bool $verified): void {
    app(ConfigureIdentityAction::class)->execute(['oauth.github_enabled' => true, 'oauth.github_client_id' => 'UI-CLIENT',
        'oauth.github_client_secret' => 'UI-PRIVATE-OAUTH-KEY'], 'Isolated customer provider UI');
    $owner = User::factory()->create(['email_verified_at' => $verified ? now() : null]);
    $response = $this->actingAs($owner)->get('/console/account')->assertOk()->assertDontSee('UI-PRIVATE-OAUTH-KEY');
    $xpath = customerAccountOperationsUiXPath($response->getContent());
    expect($xpath->query('//form[@action="/auth/github/redirect"]'))->toHaveCount($verified ? 1 : 0);
    if ($verified) {
        expect($xpath->query('//form[@action="/auth/github/redirect"]//input[@name="link" and @value="1"]'))->toHaveCount(1)
            ->and($xpath->query('//form[@action="/auth/github/redirect"]//input[@name="current_password" and @required]'))->toHaveCount(1);
    } else {
        $response->assertSee('验证邮箱后可绑定');
    }
})->with([true, false]);

it('keeps KYC readiness owner state consent and pending authorization branches intact', function (string $state): void {
    customerAccountOperationsUiKyc();
    $owner = User::factory()->create(['email_verified_at' => $state === 'unverified' ? null : now(),
        'kyc_verified_at' => $state === 'verified' ? now() : null]);
    $verification = null;
    if (str_starts_with($state, 'pending')) {
        $verification = KycVerification::create(['user_id' => $owner->id, 'driver' => 'idcsmartali',
            'external_id' => $state === 'pending-authorize' ? 'UI-PRIVATE-KYC-EXTERNAL-ID' : null,
            'status' => 'pending', 'masked_summary' => ['name' => '*明', 'id_last4' => '001X']]);
    }
    $response = $this->actingAs($owner)->get('/console/account')->assertOk()
        ->assertDontSee('UI-PRIVATE-KYC-API')->assertDontSee('UI-PRIVATE-KYC-KEY')->assertDontSee('UI-PRIVATE-KYC-EXTERNAL-ID');
    $xpath = customerAccountOperationsUiXPath($response->getContent());
    expect($xpath->query('//form[@action="/console/account/kyc"]'))->toHaveCount($state === 'new' ? 1 : 0);
    if ($state === 'new') {
        foreach (['real_name', 'id_number', 'current_password', 'consent'] as $name) {
            expect($xpath->query('//form[@action="/console/account/kyc"]//input[@name="'.$name.'" and @required]'))->toHaveCount(1);
        }
        expect($xpath->query('//input[@name="consent" and @checked]'))->toHaveCount(0);
    } elseif ($state === 'pending-authorize') {
        expect($xpath->query('//form[@action="/console/account/kyc/'.$verification->id.'/authorize"]'))->toHaveCount(1)
            ->and($xpath->query('//form[@action="/console/account/kyc/'.$verification->id.'/refresh"]'))->toHaveCount(1);
        $response->assertSee('证件末四位 001X');
    } elseif ($state === 'pending-unknown') {
        $response->assertSee('初始化结果待核对')->assertDontSee('继续前往认证平台');
    } else {
        $response->assertSee($state === 'unverified' ? '请先验证邮箱后再发起实名申请。' : '认证已完成，无需重复申请。');
    }
    expect(KycVerification::count())->toBe($verification === null ? 0 : 1);
})->with(['new', 'unverified', 'verified', 'pending-authorize', 'pending-unknown']);

it('recovers a failed phone form without echoing its verification code or invoking the SMS transport', function (): void {
    app(ConfigureIdentityAction::class)->execute(['sms.binding_enabled' => true, 'sms.binding_template' => 'binding'], 'Isolated phone UI');
    $sms = Mockery::mock(SmsDriverInterface::class);
    $sms->shouldNotReceive('sendTemplate');
    $manager = Mockery::mock(DriverManager::class)->makePartial();
    $manager->shouldReceive('sms')->andReturn($sms);
    app()->instance(DriverManager::class, $manager);
    $owner = User::factory()->create();
    $this->actingAs($owner)->from('/console/account')->post('/console/account/phone/confirm',
        ['ui_form' => 'phone-confirm', 'phone' => '+8613800138000', 'code' => 'UI-PRIVATE-INVALID-CODE'])
        ->assertRedirect('/console/account')->assertSessionHasErrors('code')->assertSessionMissing('_old_input.code');
    $response = $this->get('/console/account')->assertOk()->assertDontSee('UI-PRIVATE-INVALID-CODE');
    $xpath = customerAccountOperationsUiXPath($response->getContent());
    expect($xpath->query('//form[@action="/console/account/phone/confirm"]//input[@name="phone" and @value="+8613800138000"]'))->toHaveCount(1)
        ->and($xpath->query('//input[@name="code" and string-length(@value)>0]'))->toHaveCount(0)
        ->and($xpath->query('//*[@role="alert"]'))->toHaveCount(1);
    expect($owner->fresh()->phone)->toBeNull();
});

it('lists only claimed owner coupons and preserves the original usage caveat and claim endpoint', function (): void {
    $owner = User::factory()->create();
    $foreign = User::factory()->create();
    foreach ([[$owner, 'UI-OWN-COUPON'], [$foreign, 'UI-FOREIGN-COUPON']] as [$user, $code]) {
        $coupon = Coupon::create(['code' => $code, 'type' => 'fixed', 'value' => '12.34', 'min_order_amount' => '20.00',
            'applies_to' => 'both', 'per_user_limit' => 1, 'is_active' => true]);
        DB::table('coupon_claims')->insert(['user_id' => $user->id, 'coupon_id' => $coupon->id, 'claimed_at' => now()]);
    }
    $response = $this->actingAs($owner)->get('/console/coupons')->assertOk()->assertSee('UI-OWN-COUPON')
        ->assertDontSee('UI-FOREIGN-COUPON')->assertSee('12.34 CNY')->assertSee('取消和退款不恢复次数');
    $xpath = customerAccountOperationsUiXPath($response->getContent());
    expect($xpath->query('//form[@action="/console/coupons"]//input[@name="code" and @required and @maxlength="64"]'))->toHaveCount(1)
        ->and($xpath->query('//form[@action="/console/coupons"]//input[@name="_token"]'))->toHaveCount(1);
});

it('keeps ticket services and conversations within the owner scope and preserves explicit closed state operations', function (bool $closed): void {
    $owner = User::factory()->create();
    $own = Instance::factory()->create(['user_id' => $owner->id, 'display_name' => 'UI-OWN-SERVICE',
        'encrypted_credentials' => ['password' => 'UI-PRIVATE-SERVICE-KEY']]);
    $foreign = Instance::factory()->create(['display_name' => 'UI-FOREIGN-SERVICE']);
    $ticket = Ticket::create(['user_id' => $owner->id, 'instance_id' => $own->id, 'subject' => 'UI-OWN-TICKET', 'status' => $closed ? 'closed' : 'open']);
    TicketReply::create(['ticket_id' => $ticket->id, 'user_id' => $owner->id, 'body' => '<script>UI-OWNER-TEXT</script>', 'is_admin' => false]);
    $foreignTicket = Ticket::create(['user_id' => $foreign->user_id, 'subject' => 'UI-FOREIGN-TICKET', 'status' => 'open']);
    $this->actingAs($owner)->get('/console/tickets')->assertOk()->assertSee($own->display_name)->assertSee($ticket->subject)
        ->assertDontSee($foreign->display_name)->assertDontSee($foreignTicket->subject)->assertDontSee('UI-PRIVATE-SERVICE-KEY');
    $response = $this->get('/console/tickets/'.$ticket->id)->assertOk()->assertSee('&lt;script&gt;UI-OWNER-TEXT&lt;/script&gt;', false)
        ->assertDontSee('<script>UI-OWNER-TEXT</script>', false);
    $xpath = customerAccountOperationsUiXPath($response->getContent());
    expect($xpath->query('//button[@name="operation" and @value="reopen"]'))->toHaveCount($closed ? 1 : 0)
        ->and($xpath->query('//button[@name="operation" and @value="reply"]'))->toHaveCount($closed ? 0 : 1)
        ->and($xpath->query('//button[@name="operation" and @value="close"]'))->toHaveCount($closed ? 0 : 1)
        ->and($xpath->query('//input[@name="reason"]'))->toHaveCount(0);
    $this->get('/console/tickets/'.$foreignTicket->id)->assertForbidden();
    $this->post('/console/tickets/'.$foreignTicket->id, ['operation' => 'close'])->assertForbidden();
    $this->post('/console/tickets', ['subject' => 'Forged association', 'body' => 'Body', 'instance_id' => $foreign->id])->assertForbidden();
    expect(Ticket::count())->toBe(2)->and(TicketReply::count())->toBe(1);
})->with([false, true]);

it('preserves a ticket draft after validation and safely ignores array input', function (bool $arrayInput): void {
    $owner = User::factory()->create();
    $instance = Instance::factory()->create(['user_id' => $owner->id]);
    $payload = ['ui_form' => 'ticket-create', 'instance_id' => $instance->id,
        'subject' => $arrayInput ? ['invalid'] : '', 'body' => $arrayInput ? ['invalid'] : 'UI-DRAFT-BODY'];
    $this->actingAs($owner)->from('/console/tickets')->post('/console/tickets', $payload)
        ->assertRedirect('/console/tickets')->assertSessionHasErrors('subject');
    $response = $this->get('/console/tickets')->assertOk();
    $xpath = customerAccountOperationsUiXPath($response->getContent());
    expect($xpath->query('//select[@name="instance_id"]/option[@value="'.$instance->id.'" and @selected]'))->toHaveCount(1)
        ->and($xpath->query('//*[@role="alert"]'))->toHaveCount(1)
        ->and($xpath->evaluate('string(//form[@action="/console/tickets"]//textarea[@name="body"])'))->toBe($arrayInput ? '' : 'UI-DRAFT-BODY');
    $this->assertDatabaseCount('tickets', 0);
})->with([false, true]);

it('restores only the current ticket reply draft after an invalid operation', function (): void {
    $owner = User::factory()->create();
    $ticket = Ticket::create(['user_id' => $owner->id, 'subject' => 'UI-Reply', 'status' => 'open']);
    $this->actingAs($owner)->from('/console/tickets/'.$ticket->id)->post('/console/tickets/'.$ticket->id,
        ['ui_form' => 'ticket-'.$ticket->id, 'operation' => 'invalid', 'body' => 'UI-REPLY-DRAFT'])
        ->assertRedirect('/console/tickets/'.$ticket->id)->assertSessionHasErrors('operation');
    $this->get('/console/tickets/'.$ticket->id)->assertOk()->assertSee('UI-REPLY-DRAFT');
    $this->withSession(['_old_input' => ['ui_form' => 'ticket-99999', 'body' => 'UI-FOREIGN-DRAFT']])
        ->get('/console/tickets/'.$ticket->id)->assertOk()->assertDontSee('UI-FOREIGN-DRAFT');
    $this->assertDatabaseCount('ticket_replies', 0);
});

it('keeps affiliate amounts distinct owner scoped and retry identity stable after an invalid amount', function (): void {
    $owner = User::factory()->create(['affiliate_balance' => '25.50']);
    AffiliateWithdrawal::create(['user_id' => $owner->id, 'destination' => 'wallet', 'amount' => '5.00', 'status' => 'pending']);
    AffiliateCommission::factory()->create(['referrer_id' => $owner->id, 'amount' => '7.89']);
    AffiliateCommission::factory()->create(['amount' => '98.76']);
    $requestId = (string) Str::ulid();
    $this->actingAs($owner)->from('/console/affiliate')->post('/console/affiliate/withdraw',
        ['ui_form' => 'affiliate-withdraw', 'request_id' => $requestId, 'amount' => 'invalid-money'])
        ->assertRedirect('/console/affiliate')->assertSessionHasErrors('amount');
    $response = $this->get('/console/affiliate')->assertOk()->assertSee('25.50 CNY')->assertSee('5.00 CNY')->assertSee('20.50 CNY')
        ->assertSee('7.89 CNY')->assertDontSee('98.76 CNY')->assertSee('/console/affiliate/external', false);
    $xpath = customerAccountOperationsUiXPath($response->getContent());
    expect($xpath->query('//form[@action="/console/affiliate/withdraw"]//input[@name="request_id" and @value="'.$requestId.'"]'))->toHaveCount(1)
        ->and($xpath->query('//input[@name="amount" and @value="invalid-money"]'))->toHaveCount(1)
        ->and($xpath->query('//input[@name="reason"]'))->toHaveCount(0);
    expect($owner->fresh()->affiliate_balance)->toBe('25.50');
    $this->assertDatabaseCount('affiliate_withdrawals', 1);
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('renders failed nested affiliate inputs safely without creating a withdrawal', function (): void {
    $owner = User::factory()->create(['affiliate_balance' => '20.00']);
    $this->actingAs($owner)->from('/console/affiliate')->post('/console/affiliate/withdraw',
        ['ui_form' => 'affiliate-withdraw', 'request_id' => ['invalid'], 'amount' => ['invalid']])
        ->assertRedirect('/console/affiliate')->assertSessionHasErrors(['request_id', 'amount']);
    $response = $this->get('/console/affiliate')->assertOk();
    $xpath = customerAccountOperationsUiXPath($response->getContent());
    expect($xpath->evaluate('string(//input[@name="amount"]/@value)'))->toBe('')
        ->and(Str::isUlid($xpath->evaluate('string(//input[@name="request_id"]/@value)')))->toBeTrue();
    $this->assertDatabaseCount('affiliate_withdrawals', 0);
    expect($owner->fresh()->affiliate_balance)->toBe('20.00');
});

it('keeps download targets private for guests and uses bounded pagination links', function (): void {
    for ($index = 0; $index < 21; $index++) {
        DownloadResource::create(['title' => 'UI-Download-'.$index, 'category' => 'tools', 'storage_type' => 'external_url',
            'external_url' => 'https://example.test/UI-PRIVATE-DOWNLOAD-'.$index, 'access_level' => DownloadAccessLevel::AUTHENTICATED,
            'is_active' => true, 'version' => '1.0', 'description' => '<script>UI-UNTRUSTED-DESCRIPTION</script>']);
    }
    $resource = DownloadResource::firstOrFail();
    $response = $this->get('/downloads')->assertOk()->assertSee('UI-Download-0')->assertSee('授权下载')->assertSee('第 1 / 2 页')
        ->assertSee('&lt;script&gt;UI-UNTRUSTED-DESCRIPTION&lt;/script&gt;', false)->assertDontSee('UI-PRIVATE-DOWNLOAD')
        ->assertDontSee('<script>UI-UNTRUSTED-DESCRIPTION</script>', false);
    $xpath = customerAccountOperationsUiXPath($response->getContent());
    expect($xpath->query('//nav[@aria-label="分页"]//a[@rel="next"]'))->toHaveCount(1)
        ->and($xpath->query('//nav[@aria-label="分页"]//svg'))->toHaveCount(0);
    $this->get('/downloads?page=2')->assertOk()->assertSee('UI-Download-20');
    $this->get('/downloads/'.$resource->id)->assertForbidden();
    expect($resource->fresh()->download_count)->toBe(0);
});
