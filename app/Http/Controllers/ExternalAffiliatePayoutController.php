<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ProcessExternalAffiliateWithdrawalAction;
use App\Actions\ReadExternalPayoutDetailsAction;
use App\Actions\RequestExternalAffiliateWithdrawalAction;
use App\Models\AffiliateWithdrawal;
use App\Models\User;
use App\Services\AffiliateFunds;
use App\Services\ReceivableAdmission;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExternalAffiliatePayoutController extends Controller
{
    public function index(Request $request, AffiliateFunds $funds)
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $check = null;
        try {
            $scan = app(ReceivableAdmission::class)->prepare($user, 'receivable-external-preview', 'preview', [], false, true);
        } catch (ValidationException $error) {
            $scan = null;
            $check = $error->errors();
        }

        return response()->view('sales.external-payouts', ['user' => $user, 'pending' => $funds->pending($user->id),
            'hasReceivable' => $scan === null || $scan['has_outstanding'], 'receivableCheck' => $check, 'requestId' => old('request_id', (string) Str::ulid()),
            'withdrawals' => AffiliateWithdrawal::where('user_id', $user->id)->where('destination', 'external')->latest('id')
                ->paginate(20, ['id', 'amount', 'status', 'payout_method', 'payout_intent_key', 'created_at', 'completed_at'])])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, RequestExternalAffiliateWithdrawalAction $action)
    {
        abort_unless($request->user() instanceof User, 401);
        $action->execute($request->user(), $request->only(['request_id', 'amount', 'payout_method', 'payee_name', 'payee_account', 'bank_name']));

        return redirect('/console/affiliate/external')->with('status', '外部提现申请已提交，等待财务审核。');
    }

    public function process(Request $request, AffiliateWithdrawal $withdrawal, ProcessExternalAffiliateWithdrawalAction $action)
    {
        abort_unless($request->user() instanceof User, 401);
        $input = $request->all();
        $description = match ($input['operation'] ?? null) {
            'approve' => '批准外部佣金提现并冻结金额',
            'start' => '记录外部佣金提现执行意图',
            'paid' => '核对凭证并确认外部佣金提现已付款',
            default => null,
        };
        if ($description !== null && (! isset($input['reason']) || (is_string($input['reason']) && trim($input['reason']) === ''))) {
            $input['reason'] = $description;
        }
        $action->execute($withdrawal, $request->user(), $input);

        return redirect('/admin/external-payouts')->with('inventory_status', '外部提现处理已记录。');
    }

    public function details(Request $request, AffiliateWithdrawal $withdrawal, ReadExternalPayoutDetailsAction $action)
    {
        abort_unless($request->user() instanceof User, 401);

        return response()->view('sales.external-payout-details', $action->execute($withdrawal, $request->user(), '查看外部佣金提现收款资料'))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
