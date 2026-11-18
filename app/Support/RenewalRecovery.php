<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\FulfillmentTaskStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

final class RenewalRecovery
{
    /** A locking read is called only after acquiring the owner user lock. */
    public function rows(Invoice $invoice, bool $lock = false): Collection
    {
        $root = $invoice->renewal_root_id ?? $invoice->id;
        $query = Invoice::where(fn ($q) => $q->whereKey($root)->orWhere('renewal_root_id', $root))->orderBy('id');

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    public function chain(Invoice $invoice, ?Collection $rows = null): Collection
    {
        $rows ??= $this->rows($invoice);
        $root = $rows->firstWhere('id', $invoice->renewal_root_id ?? $invoice->id);
        if ($root === null || $root->renewal_root_id !== null || $root->replaces_invoice_id !== null || $root->billing_period_key === null) {
            $this->reject('Renewal root requires manual reconciliation.');
        }
        $chain = collect([$root]);
        $tip = $root;
        while (($child = $rows->firstWhere('replaces_invoice_id', $tip->id)) !== null) {
            if ($chain->contains('id', $child->id) || $child->renewal_root_id !== $root->id || $child->billing_period_key !== null) {
                $this->reject('Invalid renewal recovery chain.');
            }
            $chain->push($child);
            $tip = $child;
        }
        if ($chain->count() !== $rows->count() || ! $chain->contains('id', $invoice->id)) {
            $this->reject('Incomplete renewal recovery chain.');
        }
        foreach ($chain as $member) {
            if ($member->type !== InvoiceType::RENEW || $member->purchase_request_id !== null || $member->user_id !== $invoice->user_id || $member->instance_id !== $invoice->instance_id
                || $member->product_id !== $root->product_id || $member->currency !== $root->currency) {
                $this->reject('Renewal recovery identity mismatch.');
            }
        }

        return $chain;
    }

    public function current(Invoice $invoice, bool $lock = false): Invoice
    {
        $rows = $this->rows($invoice, $lock);
        if ($rows->count() === 1 && $invoice->renewal_root_id === null && $invoice->replaces_invoice_id === null) {
            return $rows->first();
        }

        return $this->chain($invoice, $rows)->last();
    }

    public function assertPayable(Invoice $invoice, Collection $rows): void
    {
        if ($rows->count() === 1 && $invoice->renewal_root_id === null && $invoice->replaces_invoice_id === null) {
            return;
        }
        $chain = $this->chain($invoice, $rows);
        if ($chain->last()->id !== $invoice->id || $chain->slice(0, -1)->contains(fn ($i) => $i->status !== InvoiceStatus::EXPIRED || $i->paid_at !== null)) {
            $this->reject('Only the current renewal recovery invoice may settle.');
        }
    }

    public function repeat(Invoice $parent, string $requestId, string $identity, ?Collection $rows = null): ?Invoice
    {
        $chain = $this->chain($parent, $rows);
        $same = $chain->first(fn ($i) => ($i->line_items['request_id'] ?? null) === $requestId);
        $child = $chain->firstWhere('replaces_invoice_id', $parent->id);
        if ($same !== null) {
            $saved = $same->line_items['recovery']['encrypted_request'] ?? null;
            if ($same->replaces_invoice_id !== $parent->id || ! is_string($saved)
                || ! hash_equals(hash('sha256', Crypt::decryptString($saved)), hash('sha256', $identity))) {
                $this->reject('Recovery request belongs to different parameters.', 'request_id');
            }

            return $same;
        }

        // A different confirmation cannot branch an existing predecessor or change its child.
        return $child;
    }

    public function eligible(Invoice $parent, Instance $instance, ?Collection $rows = null, bool $lock = false): Invoice
    {
        $chain = $this->chain($parent, $rows);
        $root = $chain->first();
        SettlementCurrency::assertSupported($root->currency);
        if ($chain->last()->id !== $parent->id || ! $instance->status->isBillable() || ! $instance->billing_cycle->isRenewable()
            || $instance->id !== $root->instance_id || $instance->user_id !== $root->user_id || $instance->product_id !== $root->product_id || $instance->currency !== $root->currency
            || isset($instance->active_config['cancellation'])) {
            $this->reject('Only an expired current renewal for this live service can be recovered.');
        }
        if ($root->billing_period_key !== $instance->id.':renew:'.$instance->next_due_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z')) {
            $this->reject('The original renewal boundary requires manual reconciliation.');
        }
        foreach ($chain as $member) {
            if ($member->status !== InvoiceStatus::EXPIRED || $member->paid_at !== null || ! isset($member->line_items['encrypted_operation'])) {
                $this->reject('A settled or unresolved renewal requires manual reconciliation.');
            }
            $operation = json_decode(Crypt::decryptString($member->line_items['encrypted_operation']), true, flags: JSON_THROW_ON_ERROR);
            if (! is_string($operation['renew_until'] ?? null) || ! InstanceDueDate::parse($operation['renew_until'])->isFuture()) {
                $this->reject('The renewal period is already overdue; confirm supplier billing and start-date policy manually.');
            }
            if (($operation['action'] ?? null) !== 'renew' || ($operation['driver_type'] ?? null) !== $instance->driver_type->value
                || ($operation['upstream_api_id'] ?? null) !== $instance->upstream_api_id || ($operation['upstream_instance_id'] ?? null) !== $instance->upstream_instance_id
                || ($operation['token_hash'] ?? null) !== hash('sha256', $instance->downstream_token ?? '')
                || ($operation['base_url'] ?? null) !== $instance->upstreamApi?->base_url || ($operation['cycle'] ?? null) !== $instance->billing_cycle->value
                || $instance->next_due_at === null || ($operation['due_boundary'] ?? null) !== $instance->next_due_at->copy()->utc()->format('Y-m-d\TH:i:s\Z')) {
                $this->reject('Binding or due boundary changed; reconcile the old renewal.');
            }
            if (FinancialTransaction::where('reference_key', 'settle:'.$member->id)->exists()) {
                $this->reject('A prior settlement requires manual reconciliation.');
            }
            if (! Money::isZero($member->credit_applied)) {
                foreach (['freeze' => TransactionType::CREDIT_FREEZE, 'unfreeze' => TransactionType::CREDIT_UNFREEZE] as $prefix => $type) {
                    $entry = FinancialTransaction::where('reference_key', $prefix.':'.$member->id)->first();
                    if ($entry === null || $entry->user_id !== $member->user_id || $entry->invoice_id !== $member->id
                        || $entry->type !== $type || ! Money::eq($entry->amount, $member->credit_applied)) {
                        $this->reject('Original wallet release is not verified.');
                    }
                }
            }
        }
        // In the confirmation transaction all invoice rows were checked before the instance lock.
        // Use a current task read on MySQL, even if an earlier read established an RR snapshot.
        $tasks = FulfillmentTask::where(fn ($q) => $q->whereIn('invoice_id', $chain->pluck('id')->all())
            ->orWhere(fn ($q) => $q->where('instance_id', $instance->id)->whereIn('status', [FulfillmentTaskStatus::PENDING, FulfillmentTaskStatus::RUNNING, FulfillmentTaskStatus::FAILED])))->orderBy('id');
        if ((! $lock && Invoice::where('instance_id', $instance->id)->where('status', InvoiceStatus::UNPAID)->exists())
            || ($lock ? $tasks->lockForUpdate() : $tasks)->first(['id']) !== null) {
            $this->reject('An invoice or operation requires reconciliation before recovery.');
        }

        return $root;
    }

    public function fingerprint(Instance $instance): string
    {
        return hash('sha256', json_encode([$instance->user_id, $instance->product_id, $instance->driver_type->value,
            $instance->upstream_api_id, $instance->upstream_instance_id, $instance->downstream_token, $instance->upstreamApi?->base_url,
            $instance->next_due_at?->copy()->utc()->format('Y-m-d\TH:i:s\Z'), $instance->billing_cycle->value, $instance->currency,
            $instance->active_config, $instance->recurring_amount, $instance->is_price_locked, $instance->product->getRawOriginal()], JSON_THROW_ON_ERROR));
    }

    public function reject(string $message, string $field = 'recovery'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
