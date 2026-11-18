<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\PaymentCallback;
use App\Models\ReconciliationCase;
use App\Models\User;
use App\Services\NotificationManager;
use App\Services\PaymentAttentionCases;
use App\Support\OperationsAudit;
use DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use JsonException;

class ReopenPaymentAttentionCaseAction
{
    public function execute(ReconciliationCase $case, User $actor, array $input): ReconciliationCase
    {
        $proof = app(PaymentAttentionCases::class);
        $data = $proof->operationInput($input);
        $hint = ReconciliationCase::findOrFail($case->id);

        try {
            return DB::transaction(function () use ($hint, $actor, $data, $proof): ReconciliationCase {
                $users = User::withTrashed()->whereKey([$hint->user_id, $actor->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $operator = $users->get($actor->id);
                abort_unless($users->has($hint->user_id) && $operator !== null && ! $operator->trashed() && $operator->anonymized_at === null, 403);
                Gate::forUser($operator)->authorize('finance.view');
                Gate::forUser($operator)->authorize('finance.reconcile');
                $current = ReconciliationCase::findOrFail($hint->id);
                if ($current->user_id !== $hint->user_id || $current->kind !== 'payment_attention') {
                    throw new DomainException('Unsupported reconciliation identity.');
                }
                if ($proof->operationReplayed($current, $operator, $data, 'reopen')) {
                    return $current;
                }
                $proof->assertRevision($current, $data);
                $callback = PaymentCallback::find($current->payment_callback_id);
                $credit = FinancialTransaction::find($current->source_credit_transaction_id);
                $invoiceId = $callback === null ? $credit?->invoice_id : $callback->invoice_id;
                if ($invoiceId !== null) {
                    $invoice = Invoice::whereKey($invoiceId)->lockForUpdate()->first();
                    if ($invoice !== null && $invoice->user_id !== $current->user_id) {
                        throw new DomainException('The original source owner changed.');
                    }
                }
                $before = ['status' => $current->status, 'revision' => $current->revision];
                $updated = app(RecordPaymentAttentionCaseAction::class)->reopen($current, $operator, $data);
                OperationsAudit::record($operator, 'payment.reconciliation_reopened', ReconciliationCase::class, $updated->id,
                    $data['reason'], $before, ['status' => $updated->status, 'revision' => $updated->revision]);
                app(NotificationManager::class)->queueOperations('reconciliation.updated',
                    ['invoice_no' => 'case:'.$updated->id, 'amount' => '0.00', 'currency' => 'CNY', 'status' => 'open'],
                    'reconciliation-case:'.$updated->id.':'.$updated->revision);

                return $updated;
            });
        } catch (DomainException|DecryptException|JsonException) {
            throw ValidationException::withMessages(['operation' => '仅允许重新打开原结案证据已失效的付款案件；完整有效的结案不能被任意改写。']);
        }
    }
}
