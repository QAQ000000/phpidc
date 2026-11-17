<?php

declare(strict_types=1);

namespace App\Actions;

use App\Support\InstallationState;
use DomainException;

class PrepareInstallationAction
{
    public function execute(): string
    {
        return app(InstallationState::class)->exclusive(function (): string {
            if (is_file(storage_path('installed.lock'))) {
                throw new DomainException('已安装，不能重新开启安装向导。');
            }
            app(InstallationState::class)->encrypter(config('app.key') ?? '');
            $token = bin2hex(random_bytes(32));
            app(InstallationState::class)->write('installation.token', ['hash' => hash('sha256', $token), 'expires_at' => now()->addHour()->timestamp]);

            return $token;
        });
    }
}
