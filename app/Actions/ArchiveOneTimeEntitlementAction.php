<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\InstanceEntitlementArchive;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\RedemptionCode;
use App\Models\User;
use App\Services\OneTimeEntitlementEvidence;
use App\Support\OperationsAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ArchiveOneTimeEntitlementAction
{
    public function __construct(private readonly OneTimeEntitlementEvidence $evidence) {}

    public function execute(User $user, Instance $instance, string $currentPassword, string $requestId, bool $confirmed): InstanceEntitlementArchive
    {
        abort_unless($instance->user_id === $user->id, 403);
        $requestId = strtoupper(trim($requestId));
        validator(['request_id' => $requestId, 'confirm' => $confirmed], ['request_id' => ['required', 'ulid'], 'confirm' => ['accepted']])->validate();

        return DB::transaction(function () use ($user, $instance, $currentPassword, $requestId): InstanceEntitlementArchive {
            $lockedUser = User::withTrashed()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($lockedUser->trashed() || $lockedUser->anonymized_at !== null || ! Hash::check($currentPassword, $lockedUser->getAuthPassword())) {
                throw ValidationException::withMessages(['current_password' => '当前密码错误或账户已停用。']);
            }
            // INV-L1: user -> invoices -> attempts -> instance -> cards -> tasks -> archive.
            $invoices = Invoice::where('instance_id', $instance->id)->orderBy('id')->lockForUpdate()->get();
            PaymentAttempt::whereIn('invoice_id', $invoices->modelKeys())->orderBy('id')->lockForUpdate()->get();
            $locked = Instance::whereKey($instance->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->user_id === $lockedUser->id, 403);
            RedemptionCode::where('instance_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            FulfillmentTask::where('instance_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            $archives = InstanceEntitlementArchive::where(function ($query) use ($lockedUser, $locked, $requestId): void {
                $query->where('instance_id', $locked->id)->orWhere(fn ($request) => $request->where('user_id', $lockedUser->id)->where('request_id', $requestId));
            })->orderBy('id')->lockForUpdate()->get();
            if ($archives->contains(fn (InstanceEntitlementArchive $archive): bool => $archive->instance_id !== $locked->id || $archive->user_id !== $lockedUser->id)) {
                throw ValidationException::withMessages(['request_id' => '该归档请求已用于另一笔服务，或历史归属需要核对。']);
            }
            $snapshot = $this->evidence->snapshot($locked);
            $fingerprint = $this->evidence->fingerprint($snapshot);
            $existing = $archives->first();
            if ($existing !== null) {
                if (! $this->evidence->validArchive($locked)) {
                    throw ValidationException::withMessages(['operation' => '原归档与当前交付证据不一致，请人工核对；不能覆盖历史归档。']);
                }

                return $existing;
            }
            $archive = InstanceEntitlementArchive::create(['user_id' => $lockedUser->id, 'instance_id' => $locked->id,
                'request_id' => $requestId, 'delivery_fingerprint' => $fingerprint, 'delivery_snapshot' => $snapshot,
                'confirmation_version' => 1, 'confirmed_at' => now(), 'archived_at' => now()]);
            OperationsAudit::record($lockedUser, 'instance.entitlement_archived', Instance::class, $locked->id,
                'Customer confirmed completed one-time delivery and saving delivery materials before account closure',
                [], ['archive_id' => $archive->id, 'delivery_fingerprint' => $fingerprint, 'confirmation_version' => 1]);

            return $archive;
        });
    }
}
