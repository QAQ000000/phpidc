<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ProcessWalletClearanceAction;
use App\Actions\ReadWalletClearanceDetailsAction;
use App\Actions\RequestClosedAccountWalletClearanceAction;
use App\Actions\RequestWalletClearanceAction;
use App\Models\User;
use App\Models\WalletClearance;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WalletClearanceController extends Controller
{
    public function index(Request $request)
    {
        $user = $this->user($request)->fresh();

        return response()->view('sales.wallet-clearances', ['user' => $user, 'requestId' => (string) Str::ulid(),
            'clearances' => WalletClearance::where('user_id', $user->id)->latest('id')
                ->paginate(20, ['id', 'amount', 'status', 'payout_method', 'created_at', 'completed_at'])])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, RequestWalletClearanceAction $action)
    {
        $action->execute($this->user($request), $request->all());

        return redirect('/console/wallet/clearances')->with('status', '钱包结清申请已提交，等待财务核验。');
    }

    public function closed(Request $request, RequestClosedAccountWalletClearanceAction $action)
    {
        $actor = $this->user($request);
        abort_unless($actor->can('finance.view') && $actor->can('finance.wallet-clearance.reconcile'), 403);
        $data = $request->validate(['user_id' => ['required', 'integer', 'min:1']]);
        $owner = User::withTrashed()->findOrFail($data['user_id']);
        $action->execute($owner, $actor, $request->all());

        return redirect('/admin/wallet-clearances')->with('inventory_status', '原财务身份清退申请已记录。');
    }

    public function process(Request $request, WalletClearance $clearance, ProcessWalletClearanceAction $action)
    {
        $action->execute($clearance, $this->user($request), $request->all());

        return redirect('/admin/wallet-clearances')->with('inventory_status', '钱包结清处理已记录。');
    }

    public function details(Request $request, WalletClearance $clearance, ReadWalletClearanceDetailsAction $action)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255'], 'confirm' => ['required', 'accepted']]);

        return response()->view('sales.wallet-clearance-details', $action->execute($clearance, $this->user($request), $data['reason']))
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
