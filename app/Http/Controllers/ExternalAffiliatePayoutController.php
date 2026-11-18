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
        $action->execute($withdrawal, $request->user(), $request->all());

        return redirect('/admin/external-payouts')->with('inventory_status', '外部提现处理已记录。');
    }

    public function details(Request $request, AffiliateWithdrawal $withdrawal, ReadExternalPayoutDetailsAction $action)
    {
        abort_unless($request->user() instanceof User, 401);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255'], 'confirm' => ['required', 'accepted']]);

        return response()->view('sales.external-payout-details', $action->execute($withdrawal, $request->user(), $data['reason']))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
