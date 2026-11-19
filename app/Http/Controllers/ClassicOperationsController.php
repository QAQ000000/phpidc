<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CallUpstreamAction;
use App\Actions\ConfigureClassicUpstreamAction;
use App\Actions\ConfigureDowngradeBoundsAction;
use App\Actions\HotSwapInstanceDriverAction;
use App\Actions\ImportClassicProductAction;
use App\Actions\RequestInstanceOperationAction;
use App\Actions\RetryFulfillmentTaskAction;
use App\Actions\SyncClassicCatalogAction;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Product;
use App\Models\ProductOptionGroup;
use App\Models\User;
use App\Services\DriverManager;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClassicOperationsController extends Controller
{
    private function actor(Request $request, string $permission): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize('upstream.view');
        Gate::forUser($actor)->authorize($permission);

        return $actor;
    }

    private function run(callable $call): mixed
    {
        try {
            return $call();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['operation' => 'The upstream target is already bound to a local service.']);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['operation' => $e->getMessage()]);
        }
    }

    private function reason(array $data, string $fallback): string
    {
        $reason = trim($data['reason'] ?? '');

        return $reason === '' ? $fallback : $reason;
    }

    public function configure(Request $request)
    {
        $actor = $this->actor($request, 'upstream.configure');
        $data = $request->validate(['account_id' => ['nullable', 'integer', 'min:1'], 'name' => ['required', 'string', 'max:255'], 'url' => ['required', 'url', 'max:255'], 'username' => ['required', 'string', 'max:255'],
            'api_secret' => [Rule::requiredIf(! $request->filled('account_id')), 'nullable', 'string', 'max:4096'], 'reason' => ['nullable', 'string', 'max:1000'], 'raw_jwt' => ['sometimes', 'boolean'], 'credit_limit' => ['sometimes', 'boolean']]);
        $reason = $this->reason($data, $request->filled('account_id') ? '编辑上游连接配置' : '新增上游连接');
        $this->run(fn () => app(ConfigureClassicUpstreamAction::class)->execute($data['name'], $data['url'], $data['username'], $data['api_secret'] ?? '', $reason, isset($data['raw_jwt']) ? $request->boolean('raw_jwt') : null, isset($data['credit_limit']) ? $request->boolean('credit_limit') : null, $actor, isset($data['account_id']) ? (int) $data['account_id'] : null));

        return redirect('/admin/upstreams')->with('inventory_status', '连接已保存；密钥不会回显。');
    }

    public function catalog(Request $request)
    {
        $this->actor($request, 'upstream.view');
        $data = $request->validate(['account_id' => ['required', 'integer']]);
        $catalog = $this->run(fn () => app(CallUpstreamAction::class)->execute(fn () => app(DriverManager::class)->classicAccount($data['account_id'])->catalog()));

        return response()->view('sales.classic-catalog', ['catalog' => $catalog, 'accountId' => $data['account_id']])->header('Cache-Control', 'private, no-store');
    }

    public function import(Request $request)
    {
        $actor = $this->actor($request, 'upstream.import');
        $data = $request->validate(['account_id' => ['required', 'integer'], 'pid' => ['required', 'integer', 'min:1'], 'cycle' => ['required', Rule::enum(BillingCycle::class)],
            'currency' => ['required', 'regex:/^[A-Z]{3}$/D'], 'markup' => ['required', 'regex:/^\d+(\.\d{1,2})?$/D'], 'reason' => ['nullable', 'string', 'max:1000']]);
        $reason = $this->reason($data, '导入上游商品与完整配置');
        $this->run(fn () => app(ImportClassicProductAction::class)->execute($data['account_id'], (string) $data['pid'], BillingCycle::from($data['cycle']), $data['currency'], $data['markup'], $reason, $actor));

        return redirect('/admin/upstreams')->with('inventory_status', '商品与配置已导入。');
    }

    public function sync(Request $request, Product $product)
    {
        $actor = $this->actor($request, 'upstream.import');
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $this->run(fn () => app(SyncClassicCatalogAction::class)->execute($product, $this->reason($data, '手动同步商品配置与价格'), $actor));

        return redirect('/admin/upstreams')->with('inventory_status', '商品配置已同步。');
    }

    public function control(Request $request, Instance $instance)
    {
        $actor = $this->actor($request, 'upstream.lifecycle');
        $data = $request->validate(['action' => ['required', Rule::in(['suspend', 'unsuspend', 'terminate'])], 'reason' => ['nullable', 'string', 'max:1000'], 'request_id' => ['required', 'ulid'], 'confirm' => ['accepted']]);
        $reason = $this->reason($data, match ($data['action']) {
            'suspend' => '管理员暂停服务', 'unsuspend' => '管理员恢复服务', default => '管理员确认终止服务',
        });
        $this->run(fn () => app(RequestInstanceOperationAction::class)->execute($instance, $data['action'], $reason, $data['request_id'], $actor));

        return redirect('/admin/upstream-instances')->with('inventory_status', '操作已入队，等待上游确认。');
    }

    public function bounds(Request $request, ProductOptionGroup $group)
    {
        $actor = $this->actor($request, 'upstream.import');
        $data = $request->validate(['allow' => ['sometimes', 'boolean'], 'minimum' => ['nullable', 'integer', 'min:0'], 'items' => ['sometimes', 'array'], 'items.*' => ['integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:1000']]);
        $this->run(fn () => app(ConfigureDowngradeBoundsAction::class)->execute($group, $request->boolean('allow'), isset($data['minimum']) ? (int) $data['minimum'] : null, $data['items'] ?? [], $actor, $this->reason($data, '调整未来续费降价边界')));

        return redirect('/admin/upstreams')->with('inventory_status', '未来续费降价边界已保存并审计。');
    }

    public function retry(Request $request, FulfillmentTask $task)
    {
        $actor = $this->actor($request, 'upstream.lifecycle');
        abort_unless($task->driver_type === DriverType::ZJMF_API, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000'], 'confirm' => ['accepted']]);
        $this->run(fn () => app(RetryFulfillmentTaskAction::class)->execute($task, $data['reason'], $actor));

        return redirect('/admin/upstream-instances')->with('inventory_status', '原任务已重新排队；未决上游意图仍只允许对账，重试不会重新发单。');
    }

    public function hotSwap(Request $request, Instance $instance)
    {
        $actor = $this->actor($request, 'upstream.hotswap');
        $data = $request->validate(['account_id' => ['required', 'integer'], 'host_id' => ['required', 'integer', 'min:1'], 'pid' => ['nullable', 'integer', 'min:1'],
            'disposition' => ['required', Rule::in(['terminate', 'keep_orphan'])], 'reason' => ['required', 'string', 'max:255'], 'confirm' => ['accepted']]);
        $this->run(fn () => app(HotSwapInstanceDriverAction::class)->execute($instance, $data['account_id'], (string) $data['host_id'], isset($data['pid']) ? (string) $data['pid'] : null, $data['disposition'], $data['reason'], $actor, true));

        return redirect('/admin/upstream-instances')->with('inventory_status', '绑定已切换；请核对目标配置，旧资源处置已记录。');
    }
}
