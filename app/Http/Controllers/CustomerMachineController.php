<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ExecuteCustomerPowerCommandAction;
use App\Actions\ReadInstanceControlsAction;
use App\Enums\InstanceStatus;
use App\Enums\ProvisionCommand;
use App\Models\Instance;
use App\Models\User;
use App\Support\DirectVncConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerMachineController extends Controller
{
    public function show(Request $request, Instance $instance, ReadInstanceControlsAction $read)
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->view('sales.machine-controls', ['instance' => $instance, 'controls' => $read->execute($user, $instance)])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function execute(Request $request, Instance $instance, ExecuteCustomerPowerCommandAction $execute)
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($instance->user_id === $user->id, 403);
        try {
            $data = $request->validate(['command' => ['required', Rule::enum(ProvisionCommand::class)]]);
            $command = ProvisionCommand::from($data['command']);
            $result = $execute->execute($user, $instance, $command, $request->all());
            if (! $result->success) {
                throw ValidationException::withMessages(['command' => $result->errorMessage]);
            }
            if ($command === ProvisionCommand::VNC) {
                $encoded = base64_encode(json_encode(DirectVncConnection::fromClassic($result->rawResponse), JSON_THROW_ON_ERROR));
                $link = URL::temporarySignedRoute('instance.vnc', now()->addMinutes(5), ['instance' => $instance->id,
                    'fingerprint' => hash('sha256', $encoded), 'binding' => $this->binding($instance)], absolute: false);

                // Fragment stays in the browser: neither web access logs nor the VNC page GET receive the credentials.
                return redirect($link.'#'.$encoded)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
            }

            return redirect('/console/instances/'.$instance->id)->with('status', '上游已受理机器操作；请刷新监控核对实际完成状态。');
        } catch (ValidationException $e) {
            // Never flash a rescue password or a VNC connection into session old-input.
            return redirect('/console/instances/'.$instance->id)->withErrors($e->errors());
        }
    }

    public function vnc(Request $request, Instance $instance)
    {
        $user = $request->user();
        abort_unless($user instanceof User && $instance->user_id === $user->id, 403);
        abort_unless($instance->status === InstanceStatus::ACTIVE && $instance->upstreamApi?->is_active
            && hash_equals($this->binding($instance), (string) $request->query('binding'))
            && preg_match('/^[a-f0-9]{64}$/D', (string) $request->query('fingerprint')), 403);

        return response()->view('sales.vnc', ['instance' => $instance, 'fingerprint' => $request->query('fingerprint')])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src wss:; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'; form-action 'none'")
            ->header('X-Frame-Options', 'DENY');
    }

    private function binding(Instance $instance): string
    {
        return hash('sha256', json_encode($instance->only(['user_id', 'driver_type', 'upstream_api_id', 'upstream_instance_id', 'downstream_token']), JSON_THROW_ON_ERROR));
    }
}
