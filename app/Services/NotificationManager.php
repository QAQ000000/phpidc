<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendEventNotificationJob;
use App\Models\User;
use App\Models\UserNotificationBinding;

class NotificationManager
{
    public const EVENTS = ['invoice.paid', 'instance.delivered', 'invoice.refunded', 'affiliate.pending', 'affiliate.available', 'affiliate.withdrawal', 'ticket.reply', 'renewal.invoice', 'instance.overdue'];

    public const DEFAULTS = [
        'invoice.paid' => ['付款成功', '账单 {{invoice_no}} 已付款：{{amount}} {{currency}}。'],
        'instance.delivered' => ['服务交付完成', '服务 #{{instance_id}} 已交付，请登录查看交付信息。'],
        'invoice.refunded' => ['账单已退款', '账单 {{invoice_no}} 已处理退款：{{amount}} {{currency}}，具体方式请查看账单。'],
        'affiliate.pending' => ['佣金待成熟', '新增待成熟佣金 {{amount}} CNY，请查看冻结期。'],
        'affiliate.available' => ['佣金已成熟', '佣金 {{amount}} CNY 已进入推广余额。'],
        'affiliate.withdrawal' => ['转钱包申请已处理', '转钱包申请 {{amount}} CNY，审核结果：{{status}}。'],
        'ticket.reply' => ['工单有新回复', '工单 #{{ticket_id}} 已收到客服回复。'],
        'renewal.invoice' => ['续费提醒', '续费账单 {{invoice_no}}：{{amount}} {{currency}}。'],
        'instance.overdue' => ['服务逾期', '服务 #{{instance_id}} 已逾期，请核查续费账单。'],
    ];

    public const VARIABLES = ['invoice_no', 'amount', 'currency', 'status', 'ticket_id', 'instance_id'];

    public function queue(int $userId, string $event, array $variables = []): void
    {
        if (! in_array($event, self::EVENTS, true)) {
            throw new \InvalidArgumentException('Unknown notification event.');
        }
        $user = User::find($userId);
        if ($user === null) {
            return;
        }
        $safe = array_intersect_key($variables, array_flip(self::VARIABLES));
        $bindings = UserNotificationBinding::where('user_id', $userId)->whereNotNull('verified_at')->get();
        foreach ($bindings as $binding) {
            if (in_array($binding->channel, ['email', 'mail', 'sms', 'telegram'], true)) {
                SendEventNotificationJob::dispatch($userId, $event, $binding->channel === 'mail' ? 'email' : $binding->channel, $binding->target, $safe, $binding->id)->afterCommit();
            }
        }
        if (! $bindings->contains(fn ($b) => in_array($b->channel, ['email', 'mail'], true))) {
            SendEventNotificationJob::dispatch($userId, $event, 'email', $user->email, $safe)->afterCommit();
        }
    }
}
