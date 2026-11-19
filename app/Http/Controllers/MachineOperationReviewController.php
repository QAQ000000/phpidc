<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ReconcileMachineOperationAction;
use App\Models\Instance;
use App\Models\User;
use App\Support\MachineOperation;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MachineOperationReviewController extends Controller
{
    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    public function inspect(Request $request, Instance $instance, ReconcileMachineOperationAction $review)
    {
        $actor = $this->actor($request);
        $data = $request->validate(['operation_request_id' => ['required', 'ulid'], 'reason' => ['nullable', 'string', 'max:1000']]);
        $reason = trim($data['reason'] ?? '');
        $result = $review->inspect($instance, $actor, $data['operation_request_id'], $reason === '' ? '查询原机器操作状态' : $reason);

        return response()->view('filament.machine-operation-confirmation', ['instance' => $instance->refresh(),
            'operation' => app(MachineOperation::class)->summary($instance), 'review' => $result, 'resolutionId' => (string) Str::ulid()])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function store(Request $request, Instance $instance, ReconcileMachineOperationAction $review)
    {
        try {
            $result = $review->execute($instance, $this->actor($request), $request->all());
        } catch (ValidationException $exception) {
            return redirect('/admin/machine-operation-review?instance_id='.$instance->id)->withErrors($exception->errors());
        }

        return redirect('/admin/machine-operation-review?instance_id='.$instance->id)
            ->with('inventory_status', $result['released'] ? '已记录人工核对证据，允许新请求；原命令不会重放，原结果不改为自动成功。' : '核对记录已保存，继续保持未决。');
    }
}
