<?php

declare(strict_types=1);

use App\Actions\ConfigureIdentityAction;
use App\Actions\InitiateKycAction;
use App\Drivers\Kyc\IdcsmartAliKycDriver;
use App\Models\KycVerification;
use App\Models\User;
use App\Services\DriverManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);
beforeEach(fn () => $this->installApplicationForTest());

function configureIdcsmartKyc(): void
{
    app(ConfigureIdentityAction::class)->execute(['kyc.driver' => 'idcsmartali', 'kyc.enabled' => true,
        'kyc.idcsmartali_endpoint' => 'https://kyc.example.test/certapi.php', 'kyc.idcsmartali_api' => 'PRIVATE-KYC-API',
        'kyc.idcsmartali_key' => 'PRIVATE-KYC-KEY', 'kyc.idcsmartali_biz_code' => 'FACE'], 'Approved source-verified personal KYC');
}

function personalKycPayload(): array
{
    return ['real_name' => '王小明', 'id_number' => '11010119900101001X', 'current_password' => 'Customer-Password-123', 'consent' => '1'];
}

function pendingKyc(User $user, string $externalId = 'CERT-EXAMPLE'): KycVerification
{
    return KycVerification::create(['user_id' => $user->id, 'driver' => IdcsmartAliKycDriver::IDENTIFIER, 'external_id' => $externalId,
        'status' => 'pending', 'masked_summary' => ['name' => '*明', 'id_last4' => '001X']]);
}

it('commits an intent before initialization and a certificate id before authorization then verifies only through authenticated polling', function (): void {
    configureIdcsmartKyc();
    config(['app.url' => 'https://trusted.example.test']);
    $user = User::factory()->create(['password' => 'Customer-Password-123']);
    $level = DB::transactionLevel();
    Http::fake(function (Request $request) use ($level, $user) {
        expect(DB::transactionLevel())->toBe($level)->and($request->method())->toBe('POST')
            ->and($request->header('Content-Type'))->toBe(['application/x-www-form-urlencoded'])
            ->and($request->header('api'))->toBe(['PRIVATE-KYC-API'])->and($request->header('key'))->toBe(['PRIVATE-KYC-KEY']);
        $record = KycVerification::sole();
        expect($record->user_id)->toBe($user->id)->and($record->status)->toBe('pending')->and($record->masked_summary)->toBe(['name' => '*明', 'id_last4' => '001X']);
        if (str_ends_with($request->url(), '?action=initialize')) {
            expect($record->external_id)->toBeNull()->and($request['cert_type'])->toBe('IDENTITY_CARD')
                ->and($request['cert_name'])->toBe('王小明')->and($request['cert_no'])->toBe('11010119900101001X')
                ->and($request['biz_code'])->toBe('FACE')->and($request['outer_order_no'])->toStartWith('cms_kyc_'.$record->id.'_')
                ->and($request['return_url'])->toBe('https://trusted.example.test/console/account/kyc/return');

            return Http::response(['status' => 200, 'certify_id' => 'CERT-EXAMPLE', 'echo_name' => '王小明']);
        }
        expect($record->external_id)->toBe('CERT-EXAMPLE')->and($request->data())->toBe(['certify_id' => 'CERT-EXAMPLE']);

        return str_ends_with($request->url(), '?action=certify')
            ? Http::response(['status' => 200, 'url' => 'https://cert.example.test/authorize?certify_id=CERT-EXAMPLE'])
            : Http::response(['status' => 200, 'private_provider_data' => '王小明 11010119900101001X']);
    });
    $this->actingAs($user)->post('/console/account/kyc', personalKycPayload())->assertRedirect('/console/account')->assertSessionHasNoErrors();
    $record = KycVerification::sole();
    expect($user->fresh()->kyc_verified_at)->toBeNull()->and($record->external_id)->toBe('CERT-EXAMPLE');
    $this->get('/console/account')->assertOk()->assertSee('证件末四位 001X')->assertDontSee('11010119900101001X')->assertDontSee('王小明');
    $this->post('/console/account/kyc/'.$record->id.'/authorize')->assertOk()->assertSee('https://cert.example.test/authorize?certify_id=CERT-EXAMPLE', false)
        ->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('Cache-Control', 'no-store, private');
    $this->get('/console/account/kyc/return?status=verified&certify_id=CERT-EXAMPLE')->assertRedirect('/console/account');
    expect($user->fresh()->kyc_verified_at)->toBeNull();
    Http::assertSentCount(2);
    $this->post('/console/account/kyc/'.$record->id.'/refresh')->assertRedirect('/console/account')->assertSessionHas('status', '实名认证已通过。');
    expect($record->fresh()->status)->toBe('verified')->and($user->fresh()->kyc_verified_at)->not->toBeNull();
    $this->post('/console/account/kyc/'.$record->id.'/refresh')->assertRedirect('/console/account');
    Http::assertSentCount(3);
    expect(DB::table('audit_logs')->where('action', 'identity.kyc_status')->count())->toBe(1);
    foreach ([json_encode(DB::table('kyc_verifications')->get()), json_encode(DB::table('audit_logs')->get()), json_encode(session()->all())] as $stored) {
        expect($stored)->not->toContain('11010119900101001X', 'Customer-Password-123', 'PRIVATE-KYC-', 'cert.example.test/authorize');
    }
});

it('retains an ambiguous initialization intent and refuses a second billable create after supplier failure', function (string $failure): void {
    configureIdcsmartKyc();
    Http::fake(match ($failure) {
        'connection' => ['*' => Http::failedConnection()],
        'http' => ['*' => Http::response(['status' => 200, 'certify_id' => 'not-confirmed'], 500)],
        'malformed' => ['*' => Http::response('<html>11010119900101001X</html>')],
        'business' => ['*' => Http::response(['status' => 400, 'msg' => '王小明 11010119900101001X PRIVATE-KYC-KEY'])],
        'missing-id' => ['*' => Http::response(['status' => 200])],
    });
    $user = User::factory()->create(['password' => 'Customer-Password-123']);
    $this->actingAs($user)->from('/console/account')->post('/console/account/kyc', personalKycPayload())->assertRedirect('/console/account')->assertSessionHasErrors('operation');
    $record = KycVerification::sole();
    expect($record->status)->toBe('pending')->and($record->external_id)->toBeNull()->and($user->fresh()->kyc_verified_at)->toBeNull();
    expect(session()->getOldInput())->not->toHaveKeys(['real_name', 'id_number', 'current_password']);
    $this->get('/console/account')->assertOk()->assertSee('初始化结果待核对')->assertDontSee('11010119900101001X')->assertDontSee('PRIVATE-KYC-KEY');
    $count = Http::recorded()->count();
    $this->post('/console/account/kyc', personalKycPayload())->assertSessionHasErrors('operation');
    expect(KycVerification::count())->toBe(1)->and(Http::recorded()->count())->toBe($count);
})->with(['connection', 'http', 'malformed', 'business', 'missing-id']);

it('keeps a query non-success pending instead of marking a person failed or trusting a browser response', function (array $body): void {
    configureIdcsmartKyc();
    $user = User::factory()->create();
    $record = pendingKyc($user);
    Http::fake(['*' => Http::response($body)]);
    $this->actingAs($user)->from('/console/account')->post('/console/account/kyc/'.$record->id.'/refresh')->assertRedirect('/console/account');
    expect($record->fresh()->status)->toBe('pending')->and($user->fresh()->kyc_verified_at)->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'identity.kyc_status')->count())->toBe(0);
})->with([[['status' => 400, 'msg' => 'not complete']], [['status' => 401]], [['status' => 'pending']], [[]]]);

it('preserves the saved certificate id if the separate authorization step fails and never repeats initialize', function (): void {
    configureIdcsmartKyc();
    $user = User::factory()->create(['password' => 'Customer-Password-123']);
    Http::fake(['*' => Http::sequence()->push(['status' => 200, 'certify_id' => 'CERT-EXAMPLE'])->push(['status' => 400])->push(['url' => 'https://cert.example.test/recovered'])]);
    $this->actingAs($user)->post('/console/account/kyc', personalKycPayload())->assertSessionHasNoErrors();
    $record = KycVerification::sole();
    $this->from('/console/account')->post('/console/account/kyc/'.$record->id.'/authorize')->assertSessionHasErrors('operation');
    expect($record->fresh()->external_id)->toBe('CERT-EXAMPLE')->and($record->fresh()->status)->toBe('pending');
    $this->post('/console/account/kyc/'.$record->id.'/authorize')->assertOk()->assertSee('https://cert.example.test/recovered', false);
    expect(Http::recorded(fn (Request $request) => str_ends_with($request->url(), '?action=initialize'))->count())->toBe(1);
});

it('never outputs an unsafe authorization URL supplied by a certification response', function (string $url): void {
    configureIdcsmartKyc();
    $user = User::factory()->create();
    $record = pendingKyc($user);
    Http::fake(['*' => Http::response(['status' => 200, 'url' => $url])]);
    $this->actingAs($user)->from('/console/account')->post('/console/account/kyc/'.$record->id.'/authorize')->assertRedirect('/console/account')->assertSessionHasErrors('operation');
    expect($user->fresh()->kyc_verified_at)->toBeNull();
})->with(['javascript:alert(1)', 'http://cert.example.test/plaintext', 'https://user:password@cert.example.test/authorize']);

it('requires verified local email password and consent before storing an intent or sending identity data', function (string $case): void {
    configureIdcsmartKyc();
    Http::fake();
    $user = User::factory()->create(['password' => 'Customer-Password-123', 'email_verified_at' => $case === 'email' ? null : now()]);
    $payload = personalKycPayload();
    if ($case === 'password') {
        $payload['current_password'] = 'incorrect';
    } elseif ($case === 'consent') {
        $payload['consent'] = '0';
    } elseif ($case === 'id') {
        $payload['id_number'] = 'invalid';
    }
    $this->actingAs($user)->post('/console/account/kyc', $payload)->assertSessionHasErrors();
    expect(KycVerification::count())->toBe(0)->and(session()->getOldInput())->not->toHaveKeys(['real_name', 'id_number', 'current_password']);
    Http::assertNothingSent();
})->with(['email', 'password', 'consent', 'id']);

it('denies cross-account authorization and queries and never submits identity data on an account page GET', function (): void {
    configureIdcsmartKyc();
    Http::fake();
    $record = pendingKyc(User::factory()->create());
    $user = User::factory()->create();
    $this->actingAs($user)->get('/console/account')->assertOk();
    $this->post('/console/account/kyc/'.$record->id.'/authorize')->assertForbidden();
    $this->post('/console/account/kyc/'.$record->id.'/refresh')->assertForbidden();
    $this->get('/console/account/kyc/return?status=verified')->assertRedirect('/console/account');
    Http::assertNothingSent();
    expect($user->fresh()->kyc_verified_at)->toBeNull()->and($record->fresh()->status)->toBe('pending');
});

it('does not query an obsolete application or initialize another application for an already verified customer', function (): void {
    configureIdcsmartKyc();
    Http::fake();
    $user = User::factory()->create(['password' => 'Customer-Password-123']);
    $old = pendingKyc($user, 'OLD-CERT');
    pendingKyc($user, 'CURRENT-CERT');
    $this->actingAs($user)->post('/console/account/kyc/'.$old->id.'/refresh')->assertSessionHasErrors('operation');
    $user->update(['kyc_verified_at' => now()]);
    expect(fn () => app(InitiateKycAction::class)->execute($user, personalKycPayload()))->toThrow(DomainException::class);
    Http::assertNothingSent();
});

it('leaves the frozen callback contract explicitly unsupported and keeps supplier credentials encrypted', function (): void {
    configureIdcsmartKyc();
    $driver = app(DriverManager::class)->kyc();
    expect(fn () => $driver->handleCallback(Illuminate\Http\Request::create('/unavailable-callback', 'POST')))->toThrow(DomainException::class);
    expect(json_encode(DB::table('system_settings')->get()))->not->toContain('PRIVATE-KYC-API', 'PRIVATE-KYC-KEY');
    expect(json_encode(DB::table('audit_logs')->get()))->not->toContain('PRIVATE-KYC-API', 'PRIVATE-KYC-KEY');
});

it('decodes the source-supported UTF-8 BOM while the driver returns results without writing identity models', function (): void {
    configureIdcsmartKyc();
    $user = User::factory()->create();
    $record = pendingKyc($user);
    Http::fake(['*' => Http::sequence()->push("\xEF\xBB\xBF".'{"status":200,"certify_id":"NEW-CERTIFICATE"}')
        ->push("\xEF\xBB\xBF".'{"status":"200"}')]);
    $driver = app(DriverManager::class)->kyc();
    $initial = $driver->initiate($user, ['request_id' => 'isolated-request', ...personalKycPayload()]);
    $status = $driver->queryStatus($record);
    expect($initial->externalId)->toBe('NEW-CERTIFICATE')->and($status->status)->toBe('verified')
        ->and($record->fresh()->status)->toBe('pending')->and($user->fresh()->kyc_verified_at)->toBeNull()->and(KycVerification::count())->toBe(1);
});
