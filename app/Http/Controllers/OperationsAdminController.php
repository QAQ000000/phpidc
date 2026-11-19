<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ClaimCouponAction;
use App\Actions\ManageTicketAction;
use App\Actions\ProcessAffiliateWithdrawalAction;
use App\Actions\SaveDownloadResourceAction;
use App\Actions\SaveOperationsConfigurationAction;
use App\Models\AffiliateWithdrawal;
use App\Models\Ticket;
use App\Models\User;
use App\Services\NotificationManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OperationsAdminController extends Controller
{
    public function save(Request $request, string $kind, SaveOperationsConfigurationAction $action)
    {
        $permission = match ($kind) {
            'coupon' => 'promotion.manage', 'rule' => 'affiliate.manage', 'template' => 'notification.manage', default => abort(404),
        };
        $actor = $this->actor($request, $permission);
        $data = $request->validate(['id' => ['nullable', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:255'],
            'product_scope' => ['sometimes', Rule::in(['all', 'selected'])],
            'product_ids' => ['nullable', Rule::requiredIf($kind === 'rule' && $request->input('product_scope') === 'selected'), 'array'],
            'mapping_rows_present' => ['sometimes', 'accepted'], 'mapping_rows' => ['sometimes', 'array', 'max:50'],
            'mapping_rows.*' => ['array:name,value'], 'mapping_rows.*.name' => ['nullable', 'string', 'max:255'],
            'mapping_rows.*.value' => ['nullable', Rule::in(NotificationManager::VARIABLES)],
            'product_ids_json' => ['nullable', 'json'], 'claim_conditions_json' => ['nullable', 'json'], 'variable_mapping_json' => ['nullable', 'json']]);
        $input = $request->all();
        foreach (['product_ids', 'claim_conditions', 'variable_mapping'] as $key) {
            if (isset($data[$key.'_json'])) {
                $input[$key] = json_decode($data[$key.'_json'], true, flags: JSON_THROW_ON_ERROR);
            }
        }
        if ($request->has('product_scope') && in_array($kind, ['coupon', 'rule'], true)) {
            $input['product_ids'] = $data['product_scope'] === 'all' ? null : ($request->input('product_ids') ?? []);
        }
        if ($kind === 'coupon' && $request->has('claim_conditions')) {
            $conditions = $request->input('claim_conditions');
            if (is_array($conditions)) {
                $input['claim_conditions'] = array_filter($conditions, fn ($value) => $value !== null && $value !== '');
            }
        }
        if ($kind === 'template' && $request->has('variable_mapping')) {
            $mapping = $request->input('variable_mapping');
            if (is_array($mapping)) {
                $input['variable_mapping'] = array_filter($mapping, fn ($value) => $value !== null && $value !== '');
            }
        }
        if ($kind === 'template' && isset($data['mapping_rows_present'])) {
            $mapping = [];
            foreach ($data['mapping_rows'] ?? [] as $index => $row) {
                $name = trim($row['name'] ?? '');
                $value = $row['value'] ?? '';
                if ($name === '' && $value === '') {
                    continue;
                }
                if ($name === '' || $value === '' || array_key_exists($name, $mapping)) {
                    throw ValidationException::withMessages(['mapping_rows.'.$index.'.name' => '请填写唯一的供应商变量名，并选择对应内容。']);
                }
                $mapping[$name] = $value;
            }
            $input['variable_mapping'] = $mapping;
        }
        $description = match ($kind) {
            'coupon' => isset($data['id']) ? '修改优惠券' : '创建优惠券',
            'rule' => isset($data['id']) ? '修改佣金规则' : '创建佣金规则',
            'template' => isset($data['id']) ? '修改通知模板' : '创建通知模板',
        };
        $action->execute($actor, $kind, $input, $this->reason($data['reason'] ?? null, $description), isset($data['id']) ? (int) $data['id'] : null);

        return back()->with('inventory_status', '配置已保存并记录审计；既有账单快照不变。');
    }

    public function grant(Request $request, ClaimCouponAction $action)
    {
        $actor = $this->actor($request, 'promotion.manage');
        $data = $request->validate(['user_id' => ['required', 'integer'], 'code' => ['required', 'string', 'max:64'], 'reason' => ['nullable', 'string', 'max:255']]);
        $action->execute(User::findOrFail($data['user_id']), $data['code'], $actor, $this->reason($data['reason'] ?? null, '发放优惠券领取资格'));

        return back()->with('inventory_status', '领取资格已发放；重复发放不会增加核销次数。');
    }

    public function payout(Request $request, AffiliateWithdrawal $withdrawal, ProcessAffiliateWithdrawalAction $action)
    {
        $actor = $this->actor($request, 'finance.payout');
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'reason' => ['required_if:decision,reject', 'nullable', 'string', 'max:255'], 'confirm' => ['required', 'accepted']]);
        $action->execute($withdrawal, $actor, $data['decision'] === 'approve', $this->reason($data['reason'] ?? null, '批准佣金转入钱包'));

        return back()->with('inventory_status', '提现申请已处理；批准只代表转入本站钱包。');
    }

    public function ticket(Request $request, Ticket $ticket, ManageTicketAction $action)
    {
        $actor = $this->actor($request, 'support.reply');
        $data = $request->validate(['operation' => ['required', 'in:reply,close,reopen'], 'body' => ['nullable', 'string'], 'reason' => ['nullable', 'string', 'max:255']]);
        $description = match ($data['operation']) {
            'reply' => '客服回复工单', 'close' => '关闭工单', default => '重开工单',
        };
        $action->update($actor, $ticket, $data['operation'], $data['body'] ?? null, true, $this->reason($data['reason'] ?? null, $description));

        return back()->with('inventory_status', '工单处理已记录。');
    }

    public function download(Request $request, SaveDownloadResourceAction $action)
    {
        $actor = $this->actor($request, 'download.manage');
        $data = $request->validate(['id' => ['nullable', 'integer', 'min:1'], 'reason' => ['nullable', 'string', 'max:255']]);
        $description = isset($data['id']) ? '修改下载资源' : '创建下载资源';
        $action->execute($actor, $request->except('reason'), $this->reason($data['reason'] ?? null, $description), isset($data['id']) ? (int) $data['id'] : null, $request->file('file'));

        return back()->with('inventory_status', '下载资源已保存；本地文件保持私有。');
    }

    private function reason(?string $note, string $description): string
    {
        $note = trim($note ?? '');

        return $note === '' ? $description : $note;
    }

    private function actor(Request $request, string $permission): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize($permission);

        return $actor;
    }
}
