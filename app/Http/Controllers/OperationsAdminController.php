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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OperationsAdminController extends Controller
{
    public function save(Request $request, string $kind, SaveOperationsConfigurationAction $action)
    {
        $permission = match ($kind) {
            'coupon' => 'promotion.manage', 'rule' => 'affiliate.manage', 'template' => 'notification.manage', default => abort(404),
        };
        $actor = $this->actor($request, $permission);
        $data = $request->validate(['id' => ['nullable', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:255'],
            'product_ids_json' => ['nullable', 'json'], 'claim_conditions_json' => ['nullable', 'json'], 'variable_mapping_json' => ['nullable', 'json']]);
        $input = $request->all();
        foreach (['product_ids', 'claim_conditions', 'variable_mapping'] as $key) {
            if (isset($data[$key.'_json'])) {
                $input[$key] = json_decode($data[$key.'_json'], true, flags: JSON_THROW_ON_ERROR);
            }
        }
        $action->execute($actor, $kind, $input, $data['reason'], isset($data['id']) ? (int) $data['id'] : null);

        return back()->with('inventory_status', '配置已保存并记录审计；既有账单快照不变。');
    }

    public function grant(Request $request, ClaimCouponAction $action)
    {
        $actor = $this->actor($request, 'promotion.manage');
        $data = $request->validate(['user_id' => ['required', 'integer'], 'code' => ['required', 'string', 'max:64'], 'reason' => ['required', 'string', 'max:255']]);
        $action->execute(User::findOrFail($data['user_id']), $data['code'], $actor, $data['reason']);

        return back()->with('inventory_status', '领取资格已发放；重复发放不会增加核销次数。');
    }

    public function payout(Request $request, AffiliateWithdrawal $withdrawal, ProcessAffiliateWithdrawalAction $action)
    {
        $actor = $this->actor($request, 'finance.payout');
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'reason' => ['required', 'string', 'max:255'], 'confirm' => ['required', 'accepted']]);
        $action->execute($withdrawal, $actor, $data['decision'] === 'approve', $data['reason']);

        return back()->with('inventory_status', '提现申请已处理；批准只代表转入本站钱包。');
    }

    public function ticket(Request $request, Ticket $ticket, ManageTicketAction $action)
    {
        $actor = $this->actor($request, 'support.reply');
        $data = $request->validate(['operation' => ['required', 'in:reply,close,reopen'], 'body' => ['nullable', 'string'], 'reason' => ['required', 'string', 'max:255']]);
        $action->update($actor, $ticket, $data['operation'], $data['body'] ?? null, true, $data['reason']);

        return back()->with('inventory_status', '工单处理已记录。');
    }

    public function download(Request $request, SaveDownloadResourceAction $action)
    {
        $actor = $this->actor($request, 'download.manage');
        $data = $request->validate(['id' => ['nullable', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:255']]);
        $action->execute($actor, $request->all(), $data['reason'], isset($data['id']) ? (int) $data['id'] : null, $request->file('file'));

        return back()->with('inventory_status', '下载资源已保存；本地文件保持私有。');
    }

    private function actor(Request $request, string $permission): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize($permission);

        return $actor;
    }
}
