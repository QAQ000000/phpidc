<?php

declare(strict_types=1);

use App\Actions\AccrueAffiliateCommissionAction;
use App\Actions\ApplyKycStatusAction;
use App\Actions\AssignAdminRoleAction;
use App\Actions\CloseCustomerAccountAction;
use App\Actions\ConfigureIdentityAction;
use App\Actions\CustomerIdentityAction;
use App\Actions\PhoneBindingAction;
use App\Actions\RecordPaymentAttentionCaseAction;
use App\Actions\RegisterCustomerAction;
use App\Contracts\SmsDriverInterface;
use App\Data\KycStatusResult;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\TransactionType;
use App\Models\AffiliateCommission;
use App\Models\AffiliateWithdrawal;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\InstanceUpstreamBinding;
use App\Models\Invoice;
use App\Models\KycVerification;
use App\Models\NotificationDelivery;
use App\Models\PaymentAttempt;
use App\Models\PaymentCallback;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Models\UserOauthAccount;
use App\Services\AccountClosureEligibility;
use App\Services\DriverManager;
use App\Services\NotificationManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);
beforeEach(function (): void {
    $this->installApplicationForTest();
    Queue::fake();
    Http::preventStrayRequests();
});

function anonymizationUser(array $attributes = []): User
{
    return User::factory()->create(array_replace(['name' => 'Original Private Name', 'email' => 'closed-original@example.test',
        'phone' => '+8613800138000', 'password' => 'Original-Password-123', 'remember_token' => 'original-remember',
        'credit' => '0.00', 'frozen_credit' => '0.00', 'affiliate_balance' => '0.00', 'affiliate_frozen_balance' => '0.00'], $attributes))->fresh();
}

it('anonymizes credentials and contact bindings atomically while retaining immutable historical evidence', function (): void {
    $user = anonymizationUser(['kyc_verified_at' => now()]);
    $invoice = Invoice::factory()->paid()->create(['user_id' => $user->id]);
    $instance = Instance::factory()->create(['user_id' => $user->id, 'status' => InstanceStatus::CANCELLED]);
    $ticket = Ticket::create(['user_id' => $user->id, 'subject' => 'Historical private support text', 'status' => 'closed', 'closed_at' => now()]);
    $ledger = FinancialTransaction::create(['user_id' => $user->id, 'invoice_id' => $invoice->id, 'type' => TransactionType::CREDIT_IN,
        'amount' => '1.00', 'balance_after' => '0.00', 'frozen_after' => '0.00', 'reference_key' => 'historical-review']);
    $history = [$invoice->fresh()->getAttributes(), $instance->fresh()->getAttributes(), $ticket->fresh()->getAttributes(), $ledger->fresh()->getAttributes()];
    UserOauthAccount::create(['user_id' => $user->id, 'provider' => 'github', 'provider_subject' => 'original-subject']);
    UserNotificationBinding::create(['user_id' => $user->id, 'channel' => 'sms', 'target' => $user->phone, 'verified_at' => now()]);
    DB::table('sessions')->insert(['id' => 'original-session', 'user_id' => $user->id, 'payload' => 'sensitive-session', 'last_activity' => now()->timestamp]);
    Password::broker()->createToken($user);
    Cache::put('phone-binding:'.$user->id, ['phone' => $user->phone, 'password_hash' => $user->password], 600);
    DB::table('notification_logs')->insert(['user_id' => $user->id, 'event_key' => 'ticket.reply', 'channel' => 'email', 'target' => $user->email, 'status' => 'sent', 'created_at' => now(), 'updated_at' => now()]);
    app(NotificationManager::class)->queue($user->id, 'ticket.reply', ['status' => 'answered'], eventKey: 'closure-review:ticket');
    $delivery = NotificationDelivery::where('user_id', $user->id)->where('channel', 'email')->sole();
    $closed = app(CloseCustomerAccountAction::class)->execute($user, 'Original-Password-123', true);
    expect($closed->trashed())->toBeTrue()->and($closed->anonymized_at)->not->toBeNull()
        ->and($closed->name)->toBe('已注销用户')->and($closed->email)->toEndWith('@deleted.invalid')->and($closed->phone)->toBeNull()
        ->and($closed->email_verified_at)->toBeNull()->and($closed->kyc_verified_at)->toBeNull()
        ->and(Hash::check('Original-Password-123', $closed->password))->toBeFalse()->and($closed->remember_token)->not->toBe('original-remember')
        ->and(User::find($user->id))->toBeNull()->and(UserOauthAccount::count())->toBe(0)->and(UserNotificationBinding::count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->where('email', $user->email)->count())->toBe(0)
        ->and(Cache::has('phone-binding:'.$user->id))->toBeFalse()
        ->and($delivery->fresh()->status)->toBe('skipped')->and($delivery->fresh()->encrypted_payload)->toBeNull()
        ->and(DB::table('notification_logs')->where('user_id', $user->id)->value('target'))->toBe('[anonymized]')
        ->and([$invoice->fresh()->getAttributes(), $instance->fresh()->getAttributes(), $ticket->fresh()->getAttributes(), $ledger->fresh()->getAttributes()])->toBe($history);
    $audit = DB::table('audit_logs')->where('action', 'identity.account_anonymized')->sole();
    expect(json_encode($audit))->not->toContain($user->email, $user->phone, $user->name, 'Original-Password-123', 'original-subject')
        ->and($audit->actor_id)->toBe($user->id)->and($audit->subject_id)->toBe($user->id);
    $snapshot = $closed->getAttributes();
    expect(app(CloseCustomerAccountAction::class)->execute($user, 'unused internal replay', true)->getAttributes())->toBe($snapshot)
        ->and(DB::table('audit_logs')->where('action', 'identity.account_anonymized')->count())->toBe(1);
});

it('releases the original email phone and OAuth subject for a new independent identity', function (): void {
    $old = anonymizationUser();
    $invoice = Invoice::factory()->paid()->create(['user_id' => $old->id]);
    UserOauthAccount::create(['user_id' => $old->id, 'provider' => 'github', 'provider_subject' => 'released-subject']);
    app(CloseCustomerAccountAction::class)->execute($old, 'Original-Password-123', true);
    $new = app(RegisterCustomerAction::class)->execute('New identity', $old->email, 'Independent-Password-456', $old->id)->fresh();
    $new->phone = $old->phone;
    $new->save();
    UserOauthAccount::create(['user_id' => $new->id, 'provider' => 'github', 'provider_subject' => 'released-subject']);
    expect($new->id)->not->toBe($old->id)->and($new->referred_by)->toBeNull()->and($new->kyc_verified_at)->toBeNull()
        ->and($new->credit)->toBe('0.00')->and($new->affiliate_balance)->toBe('0.00')
        ->and(Invoice::where('user_id', $new->id)->count())->toBe(0)->and($invoice->fresh()->user_id)->toBe($old->id);
    $this->actingAs($new)->get('/console/invoices/'.$invoice->invoice_no)->assertNotFound();
});

it('rechecks all obligations under the account lock instead of trusting an earlier eligible preview', function (string $case, string $reason): void {
    $user = anonymizationUser();
    expect(app(AccountClosureEligibility::class)->forUser($user)['eligible'])->toBeTrue();
    $invoice = null;
    if (in_array($case, ['payment_unknown', 'payment_attention', 'unaccrued'], true)) {
        $invoice = Invoice::factory()->paid()->create(['user_id' => $case === 'unaccrued' ? User::factory()->create()->id : $user->id]);
        DB::table('audit_logs')->insert(['action' => AccrueAffiliateCommissionAction::ZERO_ACCRUAL_ACTION, 'subject_type' => Invoice::class, 'subject_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    match ($case) {
        'credit', 'frozen_credit', 'affiliate_balance', 'affiliate_frozen_balance' => $user->forceFill([$case => '0.01'])->save(),
        'unpaid' => Invoice::factory()->create(['user_id' => $user->id]),
        'payment_unknown' => PaymentAttempt::create(['invoice_id' => $invoice->id, 'gateway' => 'isolated', 'out_trade_no' => 'unknown-attempt', 'amount' => '1.00', 'status' => 'unknown']),
        'payment_attention' => (function () use ($invoice): void {
            $invoice->forceFill(['gateway' => 'epay_wechat'])->save();
            $callback = PaymentCallback::create(['invoice_id' => $invoice->id, 'invoice_no' => $invoice->invoice_no,
                'gateway' => 'epay_wechat', 'gateway_trade_no' => 'capacity-receipt', 'amount' => '1.00',
                'outcome' => 'rejected_invalid_state', 'processed_at' => now()]);
            app(RecordPaymentAttentionCaseAction::class)->execute($callback, 'wallet_capacity');
        })(),
        'instance' => Instance::factory()->active()->create(['user_id' => $user->id]),
        'failed_instance' => Instance::factory()->create(['user_id' => $user->id, 'status' => InstanceStatus::PROVISION_FAILED]),
        'orphan' => (function () use ($user): void {
            $instance = Instance::factory()->create(['user_id' => $user->id, 'status' => InstanceStatus::CANCELLED]);
            InstanceUpstreamBinding::create(['instance_id' => $instance->id, 'driver_type' => $instance->driver_type, 'bound_at' => now(), 'unbound_at' => now(), 'upstream_disposition' => 'kept_orphan']);
        })(),
        'task', 'uncertain_task' => (function () use ($user, $case): void {
            $instance = Instance::factory()->create(['user_id' => $user->id, 'status' => InstanceStatus::CANCELLED]);
            FulfillmentTask::factory()->create(['user_id' => $user->id, 'instance_id' => $instance->id,
                'status' => $case === 'task' ? FulfillmentTaskStatus::PENDING : FulfillmentTaskStatus::FAILED,
                'payload' => $case === 'task' ? [] : ['operation' => ['state' => 'create_uncertain']]]);
        })(),
        'machine' => Instance::factory()->create(['user_id' => $user->id, 'status' => InstanceStatus::CANCELLED,
            'active_config' => ['cloud_operation' => ['status' => 'unknown']]]),
        'withdrawal', 'external_unknown' => AffiliateWithdrawal::create(['user_id' => $user->id,
            'destination' => $case === 'withdrawal' ? 'wallet' : 'external', 'status' => $case === 'withdrawal' ? 'pending' : 'unknown', 'amount' => '1.00']),
        'commission' => AffiliateCommission::factory()->create(['referrer_id' => $user->id]),
        'unaccrued' => $invoice->forceFill(['line_items' => ['affiliate' => ['referrer_id' => $user->id]]])->save(),
        'receivable' => FinancialTransaction::create(['user_id' => $user->id, 'type' => TransactionType::AFFILIATE_CLAWBACK, 'amount' => '1.00', 'balance_after' => '0.00', 'frozen_after' => '0.00', 'reference_key' => 'invalid-debt', 'meta' => ['receivable_amount' => '1.00']]),
        'kyc' => KycVerification::create(['user_id' => $user->id, 'driver' => 'isolated', 'external_id' => 'pending-proof', 'status' => 'pending']),
        'ticket' => Ticket::create(['user_id' => $user->id, 'subject' => 'Unresolved ticket', 'status' => 'answered']),
        'permission' => $user->givePermissionTo(Permission::findOrCreate('finance.view', 'web')),
        'role' => app(AssignAdminRoleAction::class)->execute($user, 'finance-viewer', 'Cannot close before access handoff'),
    };
    $result = app(AccountClosureEligibility::class)->forUser($user->fresh());
    expect($result['eligible'])->toBeFalse()->and($result['reasons'])->toHaveKey($reason);
    expect(fn () => app(CloseCustomerAccountAction::class)->execute($user, 'Original-Password-123', true))->toThrow(ValidationException::class);
    expect(User::find($user->id))->not->toBeNull()->and(DB::table('audit_logs')->where('action', 'identity.account_anonymized')->count())->toBe(0);
})->with([
    ['credit', 'credit'], ['frozen_credit', 'frozen_credit'], ['affiliate_balance', 'affiliate_balance'], ['affiliate_frozen_balance', 'affiliate_frozen_balance'],
    ['unpaid', 'invoices'], ['payment_unknown', 'payments'], ['payment_attention', 'payments'], ['instance', 'instances'], ['failed_instance', 'instances'],
    ['orphan', 'orphans'], ['task', 'tasks'], ['uncertain_task', 'tasks'], ['machine', 'machine_operations'], ['withdrawal', 'withdrawals'],
    ['external_unknown', 'withdrawals'], ['commission', 'commissions'], ['unaccrued', 'commissions'], ['receivable', 'receivables'], ['kyc', 'kyc'], ['ticket', 'tickets'], ['role', 'permissions'], ['permission', 'permissions'],
]);

it('requires the owner current password and confirmation and ignores an injected target user', function (): void {
    $owner = anonymizationUser();
    $other = User::factory()->create();
    $this->get('/console/account/close')->assertRedirect('/login');
    $this->actingAs($owner)->get('/console/account/close')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('注销不可恢复')->assertSee('历史账单');
    $this->post('/console/account/close', ['current_password' => 'wrong', 'confirm' => '1'])->assertSessionHasErrors('current_password')->assertSessionMissing('_old_input.current_password');
    $this->post('/console/account/close', ['current_password' => 'Original-Password-123'])->assertSessionHasErrors('confirm');
    $this->post('/console/account/close', ['current_password' => 'Original-Password-123', 'confirm' => '1', 'user_id' => $other->id])->assertRedirect('/login');
    $this->assertGuest();
    expect(User::find($owner->id))->toBeNull()->and(User::find($other->id))->not->toBeNull();
});

it('rolls back identity bindings sessions and outbox scrubbing if the anonymization audit fails', function (): void {
    $user = anonymizationUser();
    UserOauthAccount::create(['user_id' => $user->id, 'provider' => 'github', 'provider_subject' => 'rollback-subject']);
    DB::table('sessions')->insert(['id' => 'rollback-session', 'user_id' => $user->id, 'payload' => 'original', 'last_activity' => now()->timestamp]);
    app(NotificationManager::class)->queue($user->id, 'ticket.reply', ['status' => 'answered'], eventKey: 'closure-review:rollback');
    DB::statement("CREATE TRIGGER reject_anonymization_audit BEFORE INSERT ON audit_logs WHEN NEW.action = 'identity.account_anonymized' BEGIN SELECT RAISE(ABORT, 'isolated audit fault'); END");
    try {
        expect(fn () => app(CloseCustomerAccountAction::class)->execute($user, 'Original-Password-123', true))->toThrow(QueryException::class);
    } finally {
        DB::statement('DROP TRIGGER reject_anonymization_audit');
    }
    expect($user->fresh()->email)->toBe($user->email)->and($user->fresh()->phone)->toBe($user->phone)
        ->and($user->fresh()->anonymized_at)->toBeNull()->and(Hash::check('Original-Password-123', $user->fresh()->password))->toBeTrue()
        ->and(UserOauthAccount::count())->toBe(1)->and(DB::table('sessions')->where('id', 'rollback-session')->exists())->toBeTrue()
        ->and(NotificationDelivery::sole()->encrypted_payload['target'])->toBe($user->email)->and(NotificationDelivery::sole()->status)->toBe('pending');
});

it('rejects stale reset email KYC and SMS proofs after closure without restoring the original account', function (): void {
    $user = anonymizationUser();
    $token = Password::broker()->createToken($user);
    $record = KycVerification::create(['user_id' => $user->id, 'driver' => 'isolated', 'external_id' => 'verified-original', 'status' => 'verified']);
    app(CloseCustomerAccountAction::class)->execute($user, 'Original-Password-123', true);
    expect(fn () => app(CustomerIdentityAction::class)->resetPassword($user->email, $token, 'Changed-Password-456'))->toThrow(ValidationException::class);
    expect(fn () => app(CustomerIdentityAction::class)->verifyEmail($user, $user->id, sha1($user->email)))->toThrow(ModelNotFoundException::class);
    expect(fn () => app(ApplyKycStatusAction::class)->execute('isolated', new KycStatusResult($record->external_id, 'verified', ['name' => '张*', 'id_last4' => '123X'])))->toThrow(ModelNotFoundException::class);
    expect(Auth::guard('web')->getProvider()->retrieveByToken($user->id, 'original-remember'))->toBeNull()
        ->and(Auth::guard('web')->getProvider()->retrieveById($user->id))->toBeNull()->and(User::withTrashed()->findOrFail($user->id)->kyc_verified_at)->toBeNull();
});

it('cannot persist a new SMS challenge when closure completes while SMS transport is in flight', function (): void {
    $user = anonymizationUser();
    app(ConfigureIdentityAction::class)->execute(['sms.binding_enabled' => true, 'sms.binding_template' => 'binding'], 'Isolated closure SMS setup');
    $sms = Mockery::mock(SmsDriverInterface::class);
    $sms->shouldReceive('sendTemplate')->once()->andReturnUsing(function () use ($user): bool {
        app(CloseCustomerAccountAction::class)->execute($user, 'Original-Password-123', true);

        return true;
    });
    $manager = Mockery::mock(DriverManager::class)->makePartial();
    $manager->shouldReceive('sms')->andReturn($sms);
    app()->instance(DriverManager::class, $manager);
    expect(fn () => app(PhoneBindingAction::class)->send($user, '+8613800138001'))->toThrow(ModelNotFoundException::class);
    expect(Cache::has('phone-binding:'.$user->id))->toBeFalse()->and(User::find($user->id))->toBeNull();
});

it('revokes another existing authenticated session on its next request for each supported session backend', function (string $driver): void {
    $user = anonymizationUser();
    config(['session.driver' => $driver]);
    app('session')->forgetDrivers();
    $guard = Auth::guard('web');
    $sessionKey = $guard->getName();
    if ($driver === 'cookie') {
        app('session')->driver()->getHandler()->setRequest(Request::create('/'));
    }
    $this->withSession([$sessionKey => $user->id, 'password_hash_web' => $user->password]);
    app(CloseCustomerAccountAction::class)->execute($user, 'Original-Password-123', true);
    Auth::forgetGuards();
    $this->get('/console/account')->assertRedirect('/login');
    $this->assertGuest();
})->with(['database', 'file', 'cookie']);
