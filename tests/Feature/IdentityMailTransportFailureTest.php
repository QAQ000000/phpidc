<?php

declare(strict_types=1);

use App\Actions\ConfigureIdentityAction;
use App\Drivers\Notification\LogMailDriver;
use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Services\DriverManager;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    app(ConfigureIdentityAction::class)->execute(['mail.transport' => 'smtp', 'mail.host' => 'smtp.example.test',
        'mail.from_address' => 'accounts@example.test'], 'Isolated identity SMTP regression');
    Queue::fake();
});

/** Keep DriverManager and LogMailDriver real, replacing only the mail transport boundary. */
function identityMailTransport(string $result, array &$bodies): void
{
    expect(app(DriverManager::class)->mail())->toBeInstanceOf(LogMailDriver::class);
    $mailer = Mockery::mock(Mailer::class);
    $level = DB::transactionLevel();
    $mailer->shouldReceive('raw')->once()->andReturnUsing(function (string $body) use ($result, &$bodies, $level): void {
        expect(DB::transactionLevel())->toBe($level);
        $bodies[] = $body;
        match ($result) {
            'retryable' => throw new UnexpectedResponseException('PRIVATE-SMTP-TRANSCRIPT', 450),
            'rejected' => throw new UnexpectedResponseException('PRIVATE-SMTP-TRANSCRIPT', 550),
            'uncertain' => throw new TransportException('PRIVATE-SMTP-TRANSCRIPT'),
            default => null,
        };
    });
    Mail::shouldReceive('build')->once()->withArgs(fn (array $config): bool => $config['transport'] === 'smtp'
        && $config['host'] === 'smtp.example.test')->andReturn($mailer);
}

it('classifies real mail adapter outcomes without breaking recovery responses or broker throttle', function (string $result): void {
    $bodies = [];
    identityMailTransport($result, $bodies);
    $user = User::factory()->create(['password' => 'Original-Password-123']);
    $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])
        ->assertRedirect('/forgot-password')->assertSessionHasNoErrors();
    $status = session('status');
    $stored = DB::table('password_reset_tokens')->where('email', $user->email)->value('token');

    $this->post('/forgot-password', ['email' => 'missing@example.test'])->assertRedirect('/forgot-password')
        ->assertSessionHasNoErrors()->assertSessionHas('status', $status);
    $this->post('/forgot-password', ['email' => $user->email])->assertRedirect('/forgot-password')
        ->assertSessionHasNoErrors()->assertSessionHas('status', $status);

    expect($bodies)->toHaveCount(1);
    expect(DB::table('password_reset_tokens')->where('email', $user->email)->value('token'))->toBe($stored);
    preg_match('~/reset-password/([A-Za-z0-9]+)~', $bodies[0], $matches);
    expect(Hash::check($matches[1], $stored))->toBeTrue();
    $audit = DB::table('audit_logs')->where('subject_id', $user->id)
        ->where('action', $result === 'sent' ? 'identity.password_reset_sent' : 'identity.password_reset_delivery_failed')->sole();
    expect(json_decode($audit->after, true, flags: JSON_THROW_ON_ERROR))->toBe(['delivery_status' => $result]);
    expect($audit->after)->not->toContain('PRIVATE-', $matches[1]);
    expect(Hash::check('Original-Password-123', $user->fresh()->password))->toBeTrue();
    Queue::assertNothingPushed();
})->with(['sent', 'retryable', 'rejected', 'uncertain']);

it('returns classified verification failures from the real mail adapter without changing identity', function (string $result): void {
    $bodies = [];
    identityMailTransport($result, $bodies);
    $user = User::factory()->unverified()->create();
    $response = $this->actingAs($user)->from('/console/account')->post('/email/verification-notification')
        ->assertRedirect('/console/account');

    if ($result === 'sent') {
        $response->assertSessionHasNoErrors()->assertSessionHas('status', '验证邮件已提交发送，请检查收件箱。');
        expect(DB::table('audit_logs')->where('action', 'identity.email_verification_delivery_failed')->exists())->toBeFalse();
    } else {
        $response->assertSessionHasErrors(['operation' => $result === 'uncertain'
            ? '邮件发送结果尚未确认，请先检查收件箱；如未收到，请联系管理员核对后再发送。'
            : '邮件发送失败，请稍后重试或联系管理员。']);
        $audit = DB::table('audit_logs')->where('action', 'identity.email_verification_delivery_failed')->sole();
        expect(json_decode($audit->after, true, flags: JSON_THROW_ON_ERROR))->toBe(['delivery_status' => $result]);
        expect($audit->after)->not->toContain('PRIVATE-');
    }
    expect($bodies)->toHaveCount(1);
    expect($user->fresh()->email_verified_at)->toBeNull();
    expect(UserNotificationBinding::where('user_id', $user->id)->count())->toBe(0);
    Queue::assertNothingPushed();
})->with(['sent', 'retryable', 'rejected', 'uncertain']);

it('keeps a recovery link usable when the real transport cannot confirm acceptance', function (): void {
    $bodies = [];
    identityMailTransport('uncertain', $bodies);
    $user = User::factory()->create(['password' => 'Original-Password-123']);
    $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();
    preg_match('~/reset-password/([A-Za-z0-9]+)~', $bodies[0], $matches);
    $this->post('/reset-password', ['email' => $user->email, 'token' => $matches[1], 'password' => 'Changed-Password-456',
        'password_confirmation' => 'Changed-Password-456'])->assertRedirect('/login')->assertSessionHasNoErrors();
    expect(Hash::check('Changed-Password-456', $user->fresh()->password))->toBeTrue();
    expect(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeFalse();
    Queue::assertNothingPushed();
});
