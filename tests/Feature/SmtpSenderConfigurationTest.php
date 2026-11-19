<?php

declare(strict_types=1);

use App\Actions\ConfigureIdentityAction;
use App\Drivers\Notification\LogMailDriver;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Email;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
});

it('uses the configured UTF-8 sender while preserving implicit SMTP TLS and legacy sender defaults', function (int $port, ?string $name): void {
    $settings = ['mail.transport' => 'smtp', 'mail.host' => 'smtp.example.test', 'mail.port' => $port,
        'mail.from_address' => 'accounts@example.test'];
    if ($name !== null) {
        $settings['mail.from_name'] = $name;
    }
    app(ConfigureIdentityAction::class)->execute($settings, 'Isolated sender configuration');
    $manager = new MailManager(app());
    $memoryMailer = $manager->build(['transport' => 'array']);
    $memoryTransport = $memoryMailer->getSymfonyTransport();
    expect($memoryTransport)->toBeInstanceOf(ArrayTransport::class);
    Mail::shouldReceive('build')->once()->withArgs(function (array $config) use ($manager, $port): bool {
        $smtp = $manager->build($config)->getSymfonyTransport();
        expect($smtp)->toBeInstanceOf(EsmtpTransport::class);
        $stream = $smtp->getStream();
        expect($stream)->toBeInstanceOf(SocketStream::class)
            ->and($stream->isTLS())->toBe($port === 465)
            ->and($config['host'])->toBe('smtp.example.test')->and($config['port'])->toBe($port);

        return true;
    })->andReturn($memoryMailer);

    expect(app(LogMailDriver::class)->send('recipient@example.test', '测试通知', '这是 UTF-8 测试正文。'))->toBeTrue();
    expect($memoryTransport->messages())->toHaveCount(1);
    $message = $memoryTransport->messages()->sole()->getOriginalMessage();
    expect($message)->toBeInstanceOf(Email::class);
    expect($message->getFrom())->toHaveCount(1)
        ->and($message->getFrom()[0]->getAddress())->toBe('accounts@example.test')
        ->and($message->getFrom()[0]->getName())->toBe($name ?? '')
        ->and($message->getTextBody())->toBe('这是 UTF-8 测试正文。')
        ->and($message->getTo()[0]->getAddress())->toBe('recipient@example.test');
})->with([[465, '测试主机'], [587, null]]);

it('rejects newline-bearing sender names before any settings or audit writes', function (string $name): void {
    $action = app(ConfigureIdentityAction::class);
    $action->execute(['mail.from_name' => '原发件名称'], 'Original sender configuration');
    $beforeSettings = DB::table('system_settings')->orderBy('id')->get()->toArray();
    $beforeAudit = DB::table('audit_logs')->orderBy('id')->get()->toArray();

    expect(fn () => $action->execute(['mail.from_name' => $name], 'Invalid sender header'))
        ->toThrow(ValidationException::class);
    expect(DB::table('system_settings')->orderBy('id')->get()->toArray())->toEqual($beforeSettings)
        ->and(DB::table('audit_logs')->orderBy('id')->get()->toArray())->toEqual($beforeAudit);
})->with(["Test\rName", "Test\nName", "Test\r\nBcc: other@example.test"]);
