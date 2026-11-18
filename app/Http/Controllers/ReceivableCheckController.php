<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ReviewReceivableEvidenceAction;
use App\Jobs\RunReceivableCheckJob;
use App\Models\User;
use App\Services\ReceivableCheckRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReceivableCheckController extends Controller
{
    public function show(Request $request, User $user, string $action, string $requestId): Response
    {
        $this->authorizeOwner($request, $user);
        abort_unless(strlen($action) <= 100 && strlen($requestId) <= 100, 404);
        $state = app(ReceivableCheckRequests::class)->get($user->id, $action, $requestId);

        return response()->view('sales.receivable-check', ['owner' => $user, 'action' => $action, 'requestId' => $requestId,
            'state' => $state === null ? null : array_intersect_key($state, array_flip(['status', 'queued_at', 'started_at', 'completed_at', 'failed_at', 'reason'])),
            'reconciliationRequired' => (bool) $user->receivable_reconciliation_required])->header('Cache-Control', 'private, no-store');
    }

    public function retry(Request $request, User $user, string $action, string $requestId): RedirectResponse
    {
        $this->authorizeOwner($request, $user);
        $request->validate(['confirm' => ['required', 'accepted']]);
        $requests = app(ReceivableCheckRequests::class);
        $state = $requests->get($user->id, $action, $requestId);
        if ($state === null || ($state['status'] ?? null) !== 'failed') {
            throw ValidationException::withMessages(['operation' => '只有未完成的原失败核验可以明确重试；过期请求请回原操作重新核验。']);
        }
        $requests->put($user->id, $action, $requestId, $state['intent_hmac'],
            ['status' => 'queued', 'queued_at' => now()->toIso8601String(), 'capture_ids' => $state['capture_ids'] ?? []]);
        RunReceivableCheckJob::dispatch($user->id, $action, $requestId, $state['intent_hmac'])->onQueue('receivable-check');

        return back()->with('status', '原请求已重新排入完整核验。');
    }

    public function review(Request $request, User $user, ReviewReceivableEvidenceAction $action): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $action->execute($user, $actor, $request->all());

        return redirect('/admin/finance/receivables?user_id='.$user->id)->with('inventory_status', '完整证据核验通过，失效标志已由授权人员解除。');
    }

    private function authorizeOwner(Request $request, User $user): void
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        if ($actor->id !== $user->id) {
            Gate::forUser($actor)->authorize('finance.view');
            Gate::forUser($actor)->authorize('finance.receivable-review');
        }
    }
}
