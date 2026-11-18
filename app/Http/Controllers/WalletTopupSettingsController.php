<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ConfigureWalletTopupAction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class WalletTopupSettingsController extends Controller
{
    public function store(Request $request, ConfigureWalletTopupAction $configure): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        Gate::forUser($actor)->authorize('finance.view');
        Gate::forUser($actor)->authorize('finance.wallet.configure');
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:255'],
            'confirm' => ['required', 'accepted']]);
        $configure->execute($actor, $request->boolean('enabled'), $data['reason']);

        return redirect('/admin/finance')->with('inventory_status', $request->boolean('enabled') ? '钱包充值已开启并记录审计。' : '钱包充值已关闭；既有有效充值账单仍可支付。');
    }
}
