<?php

declare(strict_types=1);

namespace App\Actions;

use App\Contracts\PowerControllable;
use App\Models\Instance;
use App\Models\User;
use App\Services\DriverManager;
use Illuminate\Validation\ValidationException;

class ReadInstanceControlsAction
{
    public function available(User $user, Instance $instance): bool
    {
        return app(OpenInstancePanelAction::class)->available($user, $instance)
            && app(DriverManager::class)->fulfillment($instance->driver_type, $instance->upstream_api_id) instanceof PowerControllable;
    }

    public function execute(User $user, Instance $instance): array
    {
        if ($this->available($user, $instance)) {
            foreach (app(OpenInstancePanelAction::class)->fetchWidgets($user, $instance, ['cloud_controls']) as $widget) {
                if ($widget['type'] === 'cloud_controls') {
                    return $widget['data'];
                }
            }
        }

        throw ValidationException::withMessages(['control' => '上游没有提供可用的机器管理操作。']);
    }
}
