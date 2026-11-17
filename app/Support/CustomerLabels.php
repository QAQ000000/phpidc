<?php

declare(strict_types=1);

namespace App\Support;

class CustomerLabels
{
    public const INVOICE_STATUSES = ['Unpaid' => '待付款', 'Paid' => '已付款', 'Refunded' => '已退款', 'Cancelled' => '已取消', 'Expired' => '已过期'];

    public const INSTANCE_STATUSES = ['Pending' => '待交付', 'Active' => '使用中', 'Suspended' => '已暂停', 'Cancelled' => '已取消', 'Deleted' => '已删除', 'ProvisionFailed' => '交付失败', 'Fraud' => '风控处理中'];

    public const INVOICE_TYPES = ['new_order' => '新购', 'renew' => '续费', 'upgrade' => '升级', 'downgrade' => '未来续费降价', 'wallet_topup' => '钱包充值'];
}
