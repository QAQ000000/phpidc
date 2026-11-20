<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Support\AdminFinancialAccess;
use App\Support\Money;
use App\Support\OperationsAudit;
use App\Support\WalletCapacity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdjustCustomerWalletAction
{
    public function execute(User $target, User $actor, array $input): FinancialTransaction
    {
        if (is_string($input['request_id'] ?? null)) {
            $input['request_id'] = Str::upper(trim($input['request_id']));
        }
        if (is_string($input['reason'] ?? null)) {
            $input['reason'] = trim($input['reason']);
        }
        $data = validator($input, [
            'request_id' => ['required', 'ulid'], 'direction' => ['required', 'in:credit,debit'],
            'amount' => ['required', 'string', 'regex:/^\d{1,10}\.\d{2}$/D'],
            'currency' => ['sometimes', 'in:CNY'], 'reason' => ['nullable', 'string', 'max:255'],
            'confirm' => ['required', 'accepted'],
        ])->validate();
        $amount = Money::of($data['amount']);
        if (Money::isZero($amount) || Money::gt($amount, WalletCapacity::LIMIT)) {
            throw ValidationException::withMessages(['amount' => '调整金额必须为 0.01 至 '.WalletCapacity::LIMIT.' 元。']);
        }
        $note = $data['reason'] ?? '';
        $reason = $note !== '' ? $note : ($data['direction'] === 'credit' ? '人工增加钱包可用余额' : '人工扣减钱包可用余额');
        $intent = ['version' => 1, 'request_id' => $data['request_id'], 'owner_id' => $target->id, 'actor_id' => $actor->id,
            'direction' => $data['direction'], 'amount' => $amount, 'currency' => 'CNY', 'reason' => $reason];
        $reference = 'adjust:'.$data['request_id'];
        $type = $data['direction'] === 'credit' ? TransactionType::WALLET_ADJUST_CREDIT : TransactionType::WALLET_ADJUST_DEBIT;
        $access = app(AdminFinancialAccess::class);

        try {
            return DB::transaction(function () use ($target, $actor, $intent, $reference, $type, $amount, $reason, $access): FinancialTransaction {
                ['actor' => $operator, 'target' => $locked] = $access->lock($target, $actor, 'finance.wallet-adjust');
                $existing = FinancialTransaction::where('reference_key', $reference)->first();
                if ($existing !== null) {
                    $meta = $existing->meta ?? [];
                    if ($existing->user_id !== $locked->id || $existing->invoice_id !== null || $existing->type !== $type
                        || $existing->amount !== $amount) {
                        $this->conflict();
                    }
                    foreach ($intent as $key => $value) {
                        if (($meta[$key] ?? null) !== $value) {
                            $this->conflict();
                        }
                    }

                    return $existing;
                }
                abort_unless($access->isManageableTarget($operator, $locked), 403);
                $before = WalletCapacity::snapshot($locked);
                if ($intent['direction'] === 'debit' && Money::gt($amount, $before['credit'])) {
                    throw ValidationException::withMessages(['amount' => '扣减金额不能超过当前可用余额。']);
                }
                $after = [...$before, 'credit' => $intent['direction'] === 'credit'
                    ? Money::add($before['credit'], $amount) : Money::sub($before['credit'], $amount)];
                if (! WalletCapacity::allows($before, $after)) {
                    throw ValidationException::withMessages(['amount' => '本次调整超出钱包容量，请先核对钱包各项余额。']);
                }
                $locked->forceFill(['credit' => $after['credit']])->save();
                $entry = FinancialTransaction::create(['user_id' => $locked->id, 'invoice_id' => null, 'type' => $type,
                    'amount' => $amount, 'balance_after' => $locked->credit, 'frozen_after' => $locked->frozen_credit,
                    'reference_key' => $reference, 'remark' => $reason,
                    'meta' => $intent + ['wallet_clearance_frozen_after' => $locked->wallet_clearance_frozen]]);
                OperationsAudit::record($operator, 'wallet.adjusted', FinancialTransaction::class, $entry->id, $reason,
                    $before, $after + ['user_id' => $locked->id, 'amount' => $amount, 'direction' => $intent['direction']]);

                return $entry;
            });
        } catch (UniqueConstraintViolationException) {
            $this->conflict();
        }
    }

    private function conflict(): never
    {
        throw ValidationException::withMessages(['request_id' => '此调整请求已用于另一笔操作，请核对原记录后使用新的请求。']);
    }
}
