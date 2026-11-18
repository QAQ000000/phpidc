<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Instance;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\NotificationManager;
use App\Support\OperationsAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ManageTicketAction
{
    public function create(User $user, array $input): Ticket
    {
        $data = validator($input, ['subject' => ['required', 'string', 'max:255'], 'body' => ['required', 'string', 'max:20000'], 'instance_id' => ['nullable', 'integer']])->validate();
        $this->validateBodyBytes($data['body']);

        return DB::transaction(function () use ($user, $data): Ticket {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (isset($data['instance_id'])) {
                abort_unless(Instance::whereKey($data['instance_id'])->where('user_id', $user->id)->exists(), 403);
            }
            $ticket = Ticket::create(['user_id' => $user->id, 'instance_id' => $data['instance_id'] ?? null, 'subject' => $data['subject'], 'status' => 'open']);
            TicketReply::create(['ticket_id' => $ticket->id, 'user_id' => $user->id, 'body' => $data['body'], 'is_admin' => false]);

            return $ticket;
        });
    }

    public function update(User $actor, Ticket $ticket, string $operation, ?string $body = null, bool $staff = false, string $reason = ''): void
    {
        validator(['operation' => $operation, 'body' => $body, 'reason' => $reason], [
            'operation' => ['required', 'in:reply,close,reopen'], 'body' => ['required_if:operation,reply', 'nullable', 'string', 'max:20000'],
            'reason' => [$staff ? 'required' : 'nullable', 'string', 'max:255'],
        ])->validate();
        $this->validateBodyBytes($body);
        DB::transaction(function () use ($actor, $ticket, $operation, $body, $staff, $reason): void {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if ($staff) {
                Gate::forUser($actor)->authorize('support.reply');
            }
            $ticket = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            abort_unless($staff || $ticket->user_id === $actor->id, 403);
            $before = $ticket->status;
            if ($operation === 'reply') {
                if ($before === 'closed') {
                    throw ValidationException::withMessages(['operation' => '工单已关闭，请先明确重开。']);
                }
                TicketReply::create(['ticket_id' => $ticket->id, 'user_id' => $actor->id, 'is_admin' => $staff, 'body' => $body]);
                $ticket->update(['status' => $staff ? 'answered' : 'open']);
                if ($staff) {
                    app(NotificationManager::class)->queue($ticket->user_id, 'ticket.reply', ['ticket_id' => $ticket->id, 'status' => 'answered']);
                }
            } elseif ($operation === 'close') {
                $ticket->update(['status' => 'closed', 'closed_at' => $ticket->closed_at ?? now()]);
            } elseif ($before === 'closed') {
                $ticket->update(['status' => 'open', 'closed_at' => null]);
            }
            if ($staff) {
                OperationsAudit::record($actor, 'ticket.'.$operation, Ticket::class, $ticket->id, $reason, ['status' => $before], ['status' => $ticket->status]);
            }
        });
    }

    private function validateBodyBytes(?string $body): void
    {
        if ($body !== null && strlen($body) > 65535) {
            throw ValidationException::withMessages(['body' => '正文的 UTF-8 长度不能超过 65,535 字节，请缩短内容后重试。']);
        }
    }
}
