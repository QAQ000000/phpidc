<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\RecordNotificationEventAction;

class NotificationManager
{
    public const EVENTS = ['invoice.paid', 'instance.delivered', 'invoice.refunded', 'affiliate.pending', 'affiliate.available', 'affiliate.withdrawal', 'ticket.reply', 'renewal.invoice', 'instance.overdue',
        'wallet.topup.credited', 'wallet.clearance', 'payment.callback.attention', 'affiliate.receivable.settled', 'affiliate.receivable.reversed', 'affiliate.receivable.attention'];

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
        'payment.callback.attention' => ['付款需要核对', '账单 {{invoice_no}} 收款 {{amount}} {{currency}} 需要核对：{{status}}。'],
        'affiliate.receivable.settled' => ['应收清偿已登记', '应收清偿 {{amount}} {{currency}} 已登记，请查看明细。'],
        'affiliate.receivable.reversed' => ['应收清偿已冲正', '应收清偿 {{amount}} {{currency}} 已冲正，请查看明细。'],
        'affiliate.receivable.attention' => ['应收冲正需要核对', '应收清偿 {{amount}} {{currency}} 冲正后的债务需要人工核对：{{status}}。'],
    ];

    public const VARIABLES = ['invoice_no', 'amount', 'currency', 'status', 'ticket_id', 'instance_id'];

    public function queue(int $userId, string $event, array $variables = [], ?string $eventKey = null): void
    {
        app(RecordNotificationEventAction::class)->execute($userId, $event, $variables, $eventKey);
    }

    public function queueOperations(string $event, array $variables, string $eventKey): void
    {
        // Financial callers already hold their owner/invoice locks. Recipient locks belong to a later independent expansion.
        app(RecordNotificationEventAction::class)->execute(null, $event, $variables, $eventKey);
    }
}
