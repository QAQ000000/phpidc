<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ExecuteCustomerPowerCommandAction;
use App\Actions\ReadAdminInstanceControlsAction;
use App\Actions\ReadAdminInstancePowerStatusAction;
use App\Actions\RetryFulfillmentTaskAction;
use App\Actions\SyncAdminInstanceAction;
use App\Enums\DriverType;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ProvisionCommand;
use App\Filament\Pages\AdminInstanceMachine;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\User;
use App\Support\AdminMachineAccess;
use App\Support\AdminWorkspaceNavigation;
use App\Support\DirectVncConnection;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminInstanceOperationsController extends Controller
{
    public function controls(Request $request, Instance $instance, ReadAdminInstanceControlsAction $read)
    {
        $actor = $this->actor($request, 'upstream.machine-control');
        try {
            $controls = $read->execute($actor, $instance);
        } catch (ValidationException $exception) {
            return $this->failure($request, $instance, $exception);
        }

        // Explicit controller routes do not run Filament's panel middleware.
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();

        return response()->view('filament.admin-instance-machine-shell', ['instanceId' => $instance->id,
            'controls' => $controls, 'layoutPage' => app(AdminInstanceMachine::class)])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function command(Request $request, Instance $instance, ExecuteCustomerPowerCommandAction $execute)
    {
        $actor = $this->actor($request, 'upstream.machine-control');
        try {
            $data = $request->validate(['command' => ['required', Rule::enum(ProvisionCommand::class)]]);
            $command = ProvisionCommand::from($data['command']);
            $access = app(AdminMachineAccess::class);
            $executionBinding = null;
            if ($command === ProvisionCommand::VNC) {
                ['actor' => $actor, 'instance' => $instance] = $access->authorize($actor, $instance);
                abort_unless($instance->driver_type === DriverType::ZJMF_API && $instance->status === InstanceStatus::ACTIVE
                    && trim($instance->upstream_instance_id ?? '') !== '' && $instance->upstreamApi?->is_active
                    && $instance->upstreamApi->driver === DriverType::ZJMF_API, 422);
                $executionBinding = $access->fingerprint($instance);
            }
            $result = $execute->executeAsAdmin($actor, $instance, $command, $request->only(['request_id', 'reason',
                'confirm', 'backup_confirm', 'os_group', 'os', 'port', 'format_data_disk', 'system', 'temp_pass']));
            if (! $result->success) {
                throw ValidationException::withMessages(['command' => $result->errorMessage ?? '上游未确认受理，请核对原请求。']);
            }
            if ($command === ProvisionCommand::VNC) {
                ['actor' => $currentActor, 'instance' => $current] = $access->authorize($actor, $instance);
                abort_unless(hash_equals($executionBinding, $access->fingerprint($current)), 409);
                $encoded = base64_encode(json_encode(DirectVncConnection::fromClassic($result->rawResponse), JSON_THROW_ON_ERROR));
                $link = URL::temporarySignedRoute('admin.instance.vnc', now()->addMinutes(5), [
                    'instance' => $current->id, 'actor_id' => $currentActor->id, 'auth_revision' => $currentActor->auth_revision,
                    'fingerprint' => hash('sha256', $encoded), 'binding' => $executionBinding,
                ], absolute: false);

                return redirect($link.'#'.$encoded)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
            }

            return redirect($this->workspaceUrl($request, $instance))->with('instance_status', '上游已受理机器操作；请查询电源或上游任务核对实际结果，受理不代表完成。');
        } catch (ValidationException $exception) {
            return $this->failure($request, $instance, $exception);
        }
    }

    public function power(Request $request, Instance $instance, ReadAdminInstancePowerStatusAction $read)
    {
        $actor = $this->actor($request, 'upstream.machine-control');
        try {
            $status = $read->execute($actor, $instance);
        } catch (ValidationException $exception) {
            return $this->failure($request, $instance, $exception);
        }
        if ($request->expectsJson()) {
            return response()->json($status + ['checked_at' => now()->toIso8601String()], $status['available'] ? 200 : 502)
                ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
        }
        $label = ['on' => '开机', 'off' => '关机', 'unknown' => '未知', 'unavailable' => '暂不可用'][$status['state']] ?? '暂不可用';

        return redirect($this->workspaceUrl($request, $instance))->with('instance_status', '本次查询电源状态：'.$label.'。电源状态与服务计费状态分别记录。');
    }

    public function sync(Request $request, Instance $instance, SyncAdminInstanceAction $sync)
    {
        $actor = $this->actor($request, 'upstream.sync');
        try {
            $data = $request->validate(['_admin_form' => ['required', Rule::in(['instance-sync'])],
                'instance_id' => ['required', 'integer', Rule::in([$instance->id])], 'reason' => ['nullable', 'string', 'max:255']]);
            $sync->execute($instance, $actor, $data['reason'] ?? null);
        } catch (ValidationException $exception) {
            return $this->formFailure($request, $instance, $exception, 'instance-sync');
        } catch (DomainException) {
            return $this->formFailure($request, $instance, ValidationException::withMessages([
                'sync' => '上游暂未提供可用的服务快照，本地事实未更新；请稍后重新同步。',
            ]), 'instance-sync');
        }

        return redirect($this->workspaceUrl($request, $instance))->with('instance_status', '已同步上游服务事实；请核对最新状态与到期日，原未决机器操作仍按原流程处理。');
    }

    public function retry(Request $request, Instance $instance, FulfillmentTask $task, RetryFulfillmentTaskAction $retry)
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->isAccessEnabled(), 403);
        foreach (['upstream.view', 'tasks.view', 'finance.view'] as $permission) {
            Gate::forUser($actor)->authorize($permission);
        }
        abort_unless($task->instance_id === $instance->id, 404);
        try {
            $data = $request->validate(['_admin_form' => ['required', Rule::in(['instance-task-retry'])],
                'instance_id' => ['required', 'integer', Rule::in([$instance->id])],
                'record_id' => ['required', 'integer', Rule::in([$task->id])], 'confirm' => ['accepted'],
                'reason' => ['nullable', 'string', 'max:255']]);
            if ($task->status !== FulfillmentTaskStatus::FAILED || $task->invoice_id === null
                || ! Invoice::whereKey($task->invoice_id)->where('instance_id', $instance->id)->where('status', InvoiceStatus::PAID)->exists()) {
                throw ValidationException::withMessages(['operation' => '工作台仅重试当前实例的已付款失败交付，请核对原任务。']);
            }
            $retry->execute($task, trim($data['reason'] ?? '') ?: '管理员从实例工作台重试已付款失败任务', $actor);
        } catch (ValidationException $exception) {
            return $this->formFailure($request, $instance, $exception, 'instance-task-retry', $task->id);
        } catch (DomainException $exception) {
            return $this->formFailure($request, $instance, ValidationException::withMessages(['operation' => $exception->getMessage()]), 'instance-task-retry', $task->id);
        }

        return redirect($this->workspaceUrl($request, $instance))->with('instance_status', '原失败任务已重新入队；请在履约任务中核对结果。');
    }

    public function vnc(Request $request, Instance $instance)
    {
        $actor = $this->actor($request, 'upstream.machine-control');
        $access = app(AdminMachineAccess::class);
        ['actor' => $currentActor, 'instance' => $current] = $access->authorize($actor, $instance);
        abort_unless($current->driver_type === DriverType::ZJMF_API && $current->status === InstanceStatus::ACTIVE
            && trim($current->upstream_instance_id ?? '') !== '' && $current->upstreamApi?->is_active
            && $current->upstreamApi->driver === DriverType::ZJMF_API, 403);
        $claims = validator($request->query(), ['actor_id' => ['required', 'string'], 'auth_revision' => ['required', 'string'],
            'binding' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);
        abort_if($claims->fails(), 403);
        $data = $claims->validated();
        abort_unless($data['actor_id'] === (string) $currentActor->id && $data['auth_revision'] === (string) $currentActor->auth_revision
            && hash_equals($access->fingerprint($current), $data['binding']), 403);

        return response()->view('sales.vnc', ['instance' => $current, 'fingerprint' => $data['fingerprint'],
            'returnUrl' => '/admin/instances/'.$current->id])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')
            ->header('Content-Security-Policy', "default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; connect-src wss:; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'; form-action 'none'")
            ->header('X-Frame-Options', 'DENY');
    }

    private function actor(Request $request, string $permission): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->isAccessEnabled(), 403);
        Gate::forUser($actor)->authorize('upstream.view');
        Gate::forUser($actor)->authorize($permission);

        return $actor;
    }

    private function workspaceUrl(Request $request, Instance $instance): string
    {
        $actor = $request->user();

        return AdminWorkspaceNavigation::url('instance', $instance->id, $actor instanceof User
            ? AdminWorkspaceNavigation::query($actor, 'instance', $request->query()) : []);
    }

    private function failure(Request $request, Instance $instance, ValidationException $exception)
    {
        // Rescue passwords, console connections and submitted machine inputs never enter old-input.
        return redirect($this->workspaceUrl($request, $instance))->withErrors($exception->errors());
    }

    private function formFailure(Request $request, Instance $instance, ValidationException $exception, string $form, ?int $taskId = null)
    {
        $input = ['_admin_form' => $form, 'instance_id' => $instance->id];
        if ($taskId !== null) {
            $input['record_id'] = $taskId;
        }
        if (is_string($request->input('reason'))) {
            $input['reason'] = mb_substr($request->input('reason'), 0, 255);
        }

        return $this->failure($request, $instance, $exception)->withInput($input);
    }
}
