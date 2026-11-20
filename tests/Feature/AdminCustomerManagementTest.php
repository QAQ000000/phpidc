<?php

declare(strict_types=1);

use App\Actions\ConfigureIdentityAction;
use App\Actions\CustomerIdentityAction;
use App\Actions\RequestCustomerPasswordResetAction;
use App\Actions\SetCustomerAccessAction;
use App\Actions\UpdateCustomerProfileAction;
use App\Contracts\MailDriverInterface;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Models\UserOauthAccount;
use App\Services\DriverManager;
use App\Support\AdminAuditProjection;
use App\Support\AdminCustomerAccess;
use App\Support\RetryableNotificationException;
use App\Support\UncertainNotificationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Http::preventStrayRequests();
    Queue::fake();
});

afterEach(function (): void {
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

function c2ManagementActor(array $permissions = ['customers.view', 'customers.manage', 'customers.password-reset', 'customers.status.manage']): User
{
    $actor = User::factory()->create();
    foreach ($permissions as $permission) {
        $actor->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }

    return $actor;
}

function c2ManagementInput(User $user, array $changes = []): array
{
    return array_replace(['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone], $changes);
}

function c2ManagementSession(User $user): void
{
    DB::table('sessions')->insert(['id' => 'c2-session-'.$user->id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);
}

function c2ManagementMail(string $outcome, array &$messages): void
{
    app(ConfigureIdentityAction::class)->execute(['mail.transport' => 'smtp', 'mail.host' => 'smtp.example.test',
        'mail.from_address' => 'accounts@example.test'], 'C2 isolated mail fixture');
    $level = DB::transactionLevel();
    $mail = Mockery::mock(MailDriverInterface::class);
    $mail->shouldReceive('send')->once()->andReturnUsing(function (string $to, string $subject, string $body) use ($outcome, &$messages, $level): bool {
        expect(DB::transactionLevel())->toBe($level);
        $messages[] = compact('to', 'subject', 'body');

        return match ($outcome) {
            'retryable' => throw new RetryableNotificationException(60),
            'uncertain' => throw new UncertainNotificationException('C2 private transport transcript'),
            'rejected' => false,
            default => true,
        };
    });
    $manager = Mockery::mock(DriverManager::class)->makePartial();
    $manager->shouldReceive('mail')->andReturn($mail);
    app()->instance(DriverManager::class, $manager);
}

it('edits all three profile fields and invalidates contact proofs without changing financial or linked identity facts', function (): void {
    $actor = c2ManagementActor();
    $customer = User::factory()->create(['name' => 'C2 原姓名', 'email' => 'c2-old@example.test', 'phone' => '+8613800000000',
        'remember_token' => 'c2-old-remember', 'kyc_verified_at' => now(), 'credit' => '123.45', 'frozen_credit' => '6.78',
        'wallet_clearance_frozen' => '9.01', 'affiliate_balance' => '2.34']);
    foreach (['email' => $customer->email, 'mail' => $customer->email, 'sms' => $customer->phone, 'telegram' => '123456'] as $channel => $target) {
        UserNotificationBinding::create(['user_id' => $customer->id, 'channel' => $channel, 'target' => $target, 'verified_at' => now()]);
    }
    UserOauthAccount::create(['user_id' => $customer->id, 'provider' => 'github', 'provider_subject' => 'c2-original-subject']);
    Password::broker()->createToken($customer);
    c2ManagementSession($customer);
    Cache::put('phone-binding:'.$customer->id, ['hash' => 'C2-old-OTP-proof'], 600);
    $updated = app(UpdateCustomerProfileAction::class)->execute($customer, $actor, c2ManagementInput($customer, [
        'name' => '  C2 新姓名  ', 'email' => '  C2-NEW@EXAMPLE.TEST  ', 'phone' => '+8613900000000',
        'credit' => '9999.99', 'kyc_verified_at' => null, 'roles' => ['access-manager'], 'disabled_at' => now(),
    ]));
    $current = $customer->fresh();
    expect($updated->id)->toBe($customer->id)->and($current->name)->toBe('C2 新姓名')->and($current->email)->toBe('c2-new@example.test')
        ->and($current->phone)->toBe('+8613900000000')->and($current->email_verified_at)->toBeNull()->and($current->kyc_verified_at)->not->toBeNull()
        ->and($current->auth_revision)->toBe(1)->and($current->remember_token)->not->toBe('c2-old-remember')
        ->and($current->disabled_at)->toBeNull()->and($current->credit)->toBe('123.45')->and($current->frozen_credit)->toBe('6.78')
        ->and($current->wallet_clearance_frozen)->toBe('9.01')->and($current->affiliate_balance)->toBe('2.34')
        ->and($current->roles()->exists())->toBeFalse()->and(Cache::get('phone-binding:'.$customer->id))->toBeNull();
    expect(UserNotificationBinding::where('user_id', $customer->id)->whereIn('channel', ['email', 'mail', 'sms'])->whereNotNull('verified_at')->exists())->toBeFalse()
        ->and(UserNotificationBinding::where('channel', 'telegram')->value('verified_at'))->not->toBeNull()
        ->and(UserOauthAccount::sole()->provider_subject)->toBe('c2-original-subject');
    $this->assertDatabaseCount('password_reset_tokens', 0);
    $this->assertDatabaseCount('sessions', 0);
    $audit = DB::table('audit_logs')->where('action', 'customer.profile_updated')->sole();
    expect($audit->actor_id)->toBe($actor->id)->and($audit->subject_id)->toBe($customer->id)->and($audit->request_id)->not->toBeNull();
    $summary = json_decode($audit->after, true, flags: JSON_THROW_ON_ERROR);
    expect($summary['changed_keys'])->toBe(['name', 'email', 'phone'])->and($summary['sessions_revoked'])->toBeTrue()
        ->and($audit->after)->not->toContain('c2-old@example.test', 'c2-new@example.test', '+8613', 'C2 新姓名', 'OTP-proof', 'c2-original-subject');
});

it('preserves sessions and contact verification for name-only edits and does not duplicate an unchanged update', function (): void {
    $actor = c2ManagementActor();
    $customer = User::factory()->create(['remember_token' => 'c2-preserved-remember']);
    c2ManagementSession($customer);
    Cache::put('phone-binding:'.$customer->id, 'c2-preserved-proof', 600);
    $input = c2ManagementInput($customer, ['name' => 'C2 只修改姓名']);
    app(UpdateCustomerProfileAction::class)->execute($customer, $actor, $input);
    app(UpdateCustomerProfileAction::class)->execute($customer, $actor, $input);
    expect($customer->fresh()->auth_revision)->toBe(0)->and($customer->fresh()->email_verified_at)->not->toBeNull()
        ->and($customer->fresh()->remember_token)->toBe('c2-preserved-remember')
        ->and(Cache::get('phone-binding:'.$customer->id))->toBe('c2-preserved-proof');
    $this->assertDatabaseCount('sessions', 1);
    expect(DB::table('audit_logs')->where('action', 'customer.profile_updated')->count())->toBe(1);
});

it('allows clearing a phone but never marks the replacement as verified', function (): void {
    $customer = User::factory()->create(['phone' => '+8613800000000']);
    UserNotificationBinding::create(['user_id' => $customer->id, 'channel' => 'sms', 'target' => $customer->phone, 'verified_at' => now()]);
    app(UpdateCustomerProfileAction::class)->execute($customer, c2ManagementActor(), c2ManagementInput($customer, ['phone' => '']));
    expect($customer->fresh()->phone)->toBeNull()->and(UserNotificationBinding::sole()->verified_at)->toBeNull();
});

it('rejects contact collisions across case variants and retained deleted accounts without partial edits', function (string $field, string $value): void {
    $customer = User::factory()->create(['name' => 'C2 不能半写']);
    $other = User::factory()->create(['email' => 'C2-Reserved@example.test', 'phone' => '+8613800138000']);
    $other->forceFill(['deleted_at' => now()])->save();
    expect(fn () => app(UpdateCustomerProfileAction::class)->execute($customer, c2ManagementActor(), c2ManagementInput($customer,
        ['name' => 'C2 不应写入', $field => $value])))->toThrow(ValidationException::class);
    expect($customer->fresh()->name)->toBe('C2 不能半写')->and($customer->fresh()->auth_revision)->toBe(0);
})->with([['email', 'c2-reserved@EXAMPLE.TEST'], ['phone', '+8613800138000']]);

it('uses the same OTP mutex before account locks and leaves data untouched while confirmation is in flight', function (): void {
    $actor = c2ManagementActor();
    $customer = User::factory()->create(['phone' => '+8613800000000']);
    $mutex = Cache::lock('phone-binding-lock:'.$customer->id, 60);
    expect($mutex->get())->toBeTrue();
    try {
        expect(fn () => app(UpdateCustomerProfileAction::class)->execute($customer, $actor,
            c2ManagementInput($customer, ['phone' => '+8613900000000'])))->toThrow(ValidationException::class);
        expect($customer->fresh()->phone)->toBe('+8613800000000');
    } finally {
        $mutex->release();
    }
});

it('rolls profile proofs tokens and sessions back if its mandatory audit cannot be written', function (): void {
    $actor = c2ManagementActor();
    $customer = User::factory()->create(['email' => 'c2-rollback@example.test', 'phone' => '+8613800000000']);
    Password::broker()->createToken($customer);
    c2ManagementSession($customer);
    UserNotificationBinding::create(['user_id' => $customer->id, 'channel' => 'sms', 'target' => $customer->phone, 'verified_at' => now()]);
    Cache::put('phone-binding:'.$customer->id, 'c2-rollback-proof', 600);
    DB::unprepared("CREATE TRIGGER c2_reject_profile_audit BEFORE INSERT ON audit_logs WHEN NEW.action = 'customer.profile_updated' BEGIN SELECT RAISE(ABORT, 'C2 audit unavailable'); END");
    expect(fn () => app(UpdateCustomerProfileAction::class)->execute($customer, $actor, c2ManagementInput($customer,
        ['email' => 'c2-not-written@example.test', 'phone' => '+8613900000000'])))->toThrow(QueryException::class);
    expect($customer->fresh()->email)->toBe('c2-rollback@example.test')->and($customer->fresh()->auth_revision)->toBe(0)
        ->and(UserNotificationBinding::sole()->verified_at)->not->toBeNull()->and(Cache::get('phone-binding:'.$customer->id))->toBe('c2-rollback-proof');
    $this->assertDatabaseCount('sessions', 1);
    $this->assertDatabaseCount('password_reset_tokens', 1);
});

it('requires customer view and the exact management permission using a fresh locked actor', function (array $permissions, string $operation): void {
    $actor = c2ManagementActor($permissions);
    $customer = User::factory()->create();
    expect(fn () => match ($operation) {
        'profile' => app(UpdateCustomerProfileAction::class)->execute($customer, $actor, c2ManagementInput($customer, ['name' => 'C2 forbidden'])),
        'reset' => app(RequestCustomerPasswordResetAction::class)->execute($customer, $actor),
        'access' => app(SetCustomerAccessAction::class)->execute($customer, $actor, true),
    })->toThrow(AuthorizationException::class);
    expect($customer->fresh()->disabled_at)->toBeNull()->and($customer->fresh()->auth_revision)->toBe(0);
})->with([
    [['customers.view'], 'profile'], [['customers.manage'], 'profile'], [['customers.view'], 'reset'],
    [['customers.password-reset'], 'reset'], [['customers.view'], 'access'], [['customers.status.manage'], 'access'],
]);

it('does not trust previously loaded permissions or actor availability', function (string $change): void {
    $actor = c2ManagementActor();
    $customer = User::factory()->create();
    expect($actor->can('customers.manage'))->toBeTrue();
    if ($change === 'revoked') {
        $actor->permissions()->detach();
    } else {
        User::whereKey($actor->id)->update(['disabled_at' => now()]);
    }
    expect(fn () => app(UpdateCustomerProfileAction::class)->execute($customer, $actor,
        c2ManagementInput($customer, ['name' => 'C2 stale actor'])))->toThrow($change === 'revoked' ? AuthorizationException::class : HttpException::class);
})->with(['revoked', 'disabled']);

it('blocks self administrative role direct permission and closed targets from every management action', function (string $kind): void {
    $actor = c2ManagementActor();
    $target = $kind === 'self' ? $actor : User::factory()->create();
    match ($kind) {
        'role' => $target->assignRole(Role::findOrCreate('c2-protected-role', 'web')),
        'permission' => $target->givePermissionTo(Permission::findOrCreate('support.view', 'web')),
        'closed' => $target->forceFill(['deleted_at' => now(), 'anonymized_at' => now()])->save(),
        default => null,
    };
    expect(app(AdminCustomerAccess::class)->isManageableTarget($actor, $target))->toBeFalse();
    expect(fn () => app(UpdateCustomerProfileAction::class)->execute($target, $actor, c2ManagementInput($target)))->toThrow(HttpException::class);
    expect(fn () => app(SetCustomerAccessAction::class)->execute($target, $actor, true))->toThrow(HttpException::class);
    expect(fn () => app(RequestCustomerPasswordResetAction::class)->execute($target, $actor))->toThrow(HttpException::class);
    $this->assertDatabaseCount('password_reset_tokens', 0);
})->with(['self', 'role', 'permission', 'closed']);

it('disables and restores access idempotently without altering paid services or financial facts', function (): void {
    $actor = c2ManagementActor();
    $customer = User::factory()->create(['credit' => '9.99', 'remember_token' => 'c2-old-remember']);
    $instance = Instance::factory()->active()->create(['user_id' => $customer->id]);
    $invoice = Invoice::factory()->paid()->create(['user_id' => $customer->id, 'instance_id' => $instance->id]);
    $task = FulfillmentTask::factory()->create(['user_id' => $customer->id, 'instance_id' => $instance->id, 'invoice_id' => $invoice->id]);
    Password::broker()->createToken($customer);
    c2ManagementSession($customer);
    Cache::put('phone-binding:'.$customer->id, 'c2-disabled-proof', 600);
    $action = app(SetCustomerAccessAction::class);
    $action->execute($customer, $actor, true);
    $disabledAt = $customer->fresh()->disabled_at;
    $remember = $customer->fresh()->remember_token;
    $action->execute($customer, $actor, true);
    expect($customer->fresh()->disabled_at->eq($disabledAt))->toBeTrue()->and($customer->fresh()->auth_revision)->toBe(1)
        ->and($remember)->not->toBe('c2-old-remember')->and(Cache::get('phone-binding:'.$customer->id))->toBeNull();
    $action->execute($customer, $actor, false);
    $action->execute($customer, $actor, false);
    expect($customer->fresh()->isAccessEnabled())->toBeTrue()->and($customer->fresh()->auth_revision)->toBe(1)
        ->and($customer->fresh()->remember_token)->toBe($remember)->and($customer->fresh()->credit)->toBe('9.99')
        ->and($customer->fresh()->deleted_at)->toBeNull()->and($customer->fresh()->anonymized_at)->toBeNull()
        ->and($instance->fresh()->status)->toBe($instance->status)->and($invoice->fresh()->status)->toBe($invoice->status)
        ->and($task->fresh()->status)->toBe($task->status)->and($task->fresh()->attempts)->toBe(0);
    expect(DB::table('audit_logs')->whereIn('action', ['customer.access_disabled', 'customer.access_restored'])->count())->toBe(2);
    $this->assertDatabaseCount('sessions', 0);
    $this->assertDatabaseCount('password_reset_tokens', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('keeps a disabled account unavailable for both new recovery requests and old recovery tokens', function (): void {
    $customer = User::factory()->create(['password' => 'C2-original-password']);
    $token = Password::broker()->createToken($customer);
    $customer->forceFill(['disabled_at' => now()])->save();
    expect(fn () => app(RequestCustomerPasswordResetAction::class)->execute($customer, c2ManagementActor()))->toThrow(HttpException::class);
    expect(fn () => app(CustomerIdentityAction::class)->resetPassword($customer->email, $token, 'C2-replaced-password'))->toThrow(ValidationException::class);
    expect(Hash::check('C2-original-password', $customer->fresh()->password))->toBeTrue();
});

it('sends only the locked target current recovery address and records the actual admin and classified result', function (string $outcome): void {
    $messages = [];
    c2ManagementMail($outcome, $messages);
    config(['app.url' => 'https://c2-trusted.example.test']);
    $actor = c2ManagementActor();
    $customer = User::factory()->create(['email' => 'c2-owner@example.test']);
    $other = User::factory()->create(['email' => 'c2-other@example.test']);
    $customer->email = $other->email; // Stale caller data must never choose the recovery recipient.
    $action = app(RequestCustomerPasswordResetAction::class);
    expect($action->execute($customer, $actor))->toBe($outcome)->and($action->execute($customer, $actor))->toBe('throttled');
    expect($messages)->toHaveCount(1)->and($messages[0]['to'])->toBe('c2-owner@example.test')
        ->and($messages[0]['body'])->toContain('https://c2-trusted.example.test/reset-password/');
    preg_match('~/reset-password/([A-Za-z0-9]+)~', $messages[0]['body'], $matches);
    $stored = DB::table('password_reset_tokens')->where('email', 'c2-owner@example.test')->value('token');
    expect(Hash::check($matches[1], $stored))->toBeTrue();
    $audits = DB::table('audit_logs')->where('subject_type', User::class)->where('subject_id', $customer->id)->get();
    expect($audits)->toHaveCount(3);
    foreach ($audits as $audit) {
        expect($audit->actor_id)->toBe($actor->id)->and($audit->request_id)->not->toBeNull()
            ->and($audit->after)->not->toContain($matches[1], 'c2-owner@example.test', 'c2-other@example.test', 'https://', 'transport transcript');
    }
    expect(json_decode($audits->firstWhere('action', 'customer.password_reset_delivery')->after, true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['delivery_status' => $outcome]);
})->with(['sent', 'retryable', 'rejected', 'uncertain']);

it('does not create a token or send mail if the administrative request audit fails', function (): void {
    app(ConfigureIdentityAction::class)->execute(['mail.transport' => 'smtp', 'mail.host' => 'smtp.example.test',
        'mail.from_address' => 'accounts@example.test'], 'C2 isolated mail fixture');
    $actor = c2ManagementActor();
    $customer = User::factory()->create();
    DB::unprepared("CREATE TRIGGER c2_reject_reset_audit BEFORE INSERT ON audit_logs WHEN NEW.action = 'customer.password_reset_requested' BEGIN SELECT RAISE(ABORT, 'C2 audit unavailable'); END");
    expect(fn () => app(RequestCustomerPasswordResetAction::class)->execute($customer, $actor))->toThrow(QueryException::class);
    $this->assertDatabaseCount('password_reset_tokens', 0);
});

it('increments the authentication revision when a standard recovery completes without authenticating or verifying email', function (): void {
    $customer = User::factory()->unverified()->create(['auth_revision' => 4, 'remember_token' => 'c2-original-remember']);
    $token = Password::broker()->createToken($customer);
    c2ManagementSession($customer);
    app(CustomerIdentityAction::class)->resetPassword($customer->email, $token, 'C2-new-password-1234');
    expect($customer->fresh()->auth_revision)->toBe(5)->and($customer->fresh()->email_verified_at)->toBeNull()
        ->and($customer->fresh()->remember_token)->not->toBe('c2-original-remember')
        ->and(Hash::check('C2-new-password-1234', $customer->fresh()->password))->toBeTrue();
    $this->assertGuest();
    $this->assertDatabaseCount('sessions', 0);
    expect(fn () => app(CustomerIdentityAction::class)->resetPassword($customer->email, $token, 'C2-next-password-1234'))->toThrow(ValidationException::class);
});

it('projects only the explicitly approved C2 audit keys and safe changed-field names', function (): void {
    $safe = json_decode(AdminAuditProjection::json(json_encode(['changed_keys' => ['name', 'email', 'phone', 'password', 'c2-private@example.test'],
        'email_verification_invalidated' => true, 'sms_verification_invalidated' => false, 'sessions_revoked' => true,
        'disabled' => true, 'auth_revision' => 3, 'delivery_status' => 'uncertain', 'email' => 'c2-private@example.test',
        'phone' => '+8613800000000', 'token' => 'C2-private-token', 'url' => 'https://private.test'])), true, flags: JSON_THROW_ON_ERROR);
    expect($safe)->toBe(['changed_keys' => ['name', 'email', 'phone'], 'email_verification_invalidated' => true,
        'sms_verification_invalidated' => false, 'sessions_revoked' => true, 'disabled' => true, 'auth_revision' => 3, 'delivery_status' => 'uncertain']);
    expect(AdminAuditProjection::json(json_encode(['changed_keys' => 'C2-private-value', 'delivery_status' => 'C2-private-value',
        'auth_revision' => 'C2-private-value', 'disabled' => 'C2-private-value'])))->toBe('[]');
});
