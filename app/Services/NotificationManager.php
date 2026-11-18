<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\RecordNotificationEventAction;

class NotificationManager
{
    public const EVENTS = ['invoice.paid', 'instance.delivered', 'invoice.refunded', 'affiliate.pending', 'affiliate.available', 'affiliate.withdrawal', 'ticket.reply', 'renewal.invoice', 'instance.overdue',
        'wallet.topup.credited', 'wallet.clearance', 'reconciliation.updated', 'payment.callback.attention', 'affiliate.receivable.settled', 'affiliate.receivable.reversed', 'affiliate.receivable.attention',
        'fulfillment.failed', 'fulfillment.recovery-exhausted', 'upstream.rollback-failed', 'affiliate.receivable.created', 'notification.uncertain', 'affiliate.receivable.check-failed'];

    public const DEFAULTS = [
        'invoice.paid' => ['付款成功', '账单 {{invoice_no}} 已付款：{{amount}} {{currency}}。'],
        'instance.delivered' => ['服务交付完成', '服务 #{{instance_id}} 已交付，请登录查看交付信息。'],
        'invoice.refunded' => ['账单已退款', '账单 {{invoice_no}} 已处理退款：{{amount}} {{currency}}，具体方式请查看账单。'],
        'affiliate.pending' => ['佣金待成熟', '新增待成熟佣金 {{amount}} CNY，请查看冻结期。'],
        'affiliate.available' => ['佣金已成熟', '佣金 {{amount}} CNY 已进入推广余额。'],
        'affiliate.withdrawal' => ['提现申请状态更新', '提现申请 {{amount}} CNY，当前状态：{{status}}。'],
        'ticket.reply' => ['工单有新回复', '工单 #{{ticket_id}} 已收到客服回复。'],
        'renewal.invoice' => ['续费提醒', '续费账单 {{invoice_no}}：{{amount}} {{currency}}。'],
        'instance.overdue' => ['服务逾期', '服务 #{{instance_id}} 已逾期，请核查续费账单。'],
        'wallet.topup.credited' => ['钱包充值到账', '充值账单 {{invoice_no}} 已到账：{{amount}} {{currency}}。'],
        'wallet.clearance' => ['钱包结清状态更新', '钱包结清 {{amount}} CNY，当前状态：{{status}}。'],
        'reconciliation.updated' => ['异常核对状态更新', '原记录 {{invoice_no}}：{{status}}。'],
        'payment.callback.attention' => ['付款需要核对', '账单 {{invoice_no}} 收款 {{amount}} {{currency}} 需要核对：{{status}}。'],
        'affiliate.receivable.settled' => ['应收清偿已登记', '应收清偿 {{amount}} {{currency}} 已登记，请查看明细。'],
        'affiliate.receivable.reversed' => ['应收清偿已冲正', '应收清偿 {{amount}} {{currency}} 已冲正，请查看明细。'],
        'affiliate.receivable.attention' => ['应收冲正需要核对', '应收清偿 {{amount}} {{currency}} 冲正后的债务需要人工核对：{{status}}。'],
        'fulfillment.failed' => ['履约任务最终失败', '任务 #{{task_id}}，服务 #{{instance_id}} 已失败，请核对原执行结果。'],
        'fulfillment.recovery-exhausted' => ['履约恢复次数耗尽', '任务 #{{task_id}}，服务 #{{instance_id}} 恢复次数已耗尽，请核对原任务。'],
        'upstream.rollback-failed' => ['上游余额回滚未确认', '上游操作 #{{operation_id}} 的回滚未确认，请先核对原操作。'],
        'affiliate.receivable.created' => ['新增退款应收', '账单 {{invoice_no}} 产生应收 {{amount}} {{currency}}，请核对并安排清偿。'],
        'notification.uncertain' => ['通知结果待核对', '通知投递 #{{delivery_id}} 的发送结果未知，请人工核对，系统不会自动重发。'],
        'affiliate.receivable.check-failed' => ['应收核验未完成', '客户 #{{user_id}} 的应收核验失败，请核对原证据及任务。'],
    ];

    public const VARIABLES = ['invoice_no', 'amount', 'currency', 'status', 'ticket_id', 'instance_id', 'task_id', 'operation_id', 'delivery_id', 'user_id'];

    public function queue(int $userId, string $event, array $variables = [], ?string $eventKey = null): void
    {
        app(RecordNotificationEventAction::class)->execute($userId, $event, $variables, $eventKey);
    }

    public function queueOperations(string $event, array $variables, string $eventKey, bool $suppressOperationsFanout = false): void
    {
        if ($suppressOperationsFanout && $event !== 'notification.uncertain') {
            throw new \DomainException('Only a notification uncertainty alert may suppress recursive alert delivery.');
        }
        // Financial callers already hold their owner/invoice locks. Recipient locks belong to a later independent expansion.
        app(RecordNotificationEventAction::class)->execute(null, $event, $variables, $eventKey, suppressOperationsFanout: $suppressOperationsFanout);
    }
}
