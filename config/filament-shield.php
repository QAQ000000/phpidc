<?php

declare(strict_types=1);
use App\Models\User;

return [
    'auth_provider_model' => User::class,
    'super_admin' => ['enabled' => false, 'name' => 'super_admin', 'define_via_gate' => false, 'intercept_gate' => 'before'],
    'panel_user' => ['enabled' => false, 'name' => 'panel_user'],
    'permissions' => ['separator' => ':', 'case' => 'pascal', 'generate' => true, 'format_custom_permission_keys' => false],
    'custom_permissions' => ['promotion.view' => '查看优惠券', 'promotion.manage' => '管理优惠券',
        'affiliate.view' => '查看推广资金', 'affiliate.manage' => '管理佣金规则', 'finance.payout' => '审核佣金转钱包',
        'support.view' => '查看工单', 'support.reply' => '处理工单', 'notification.view' => '查看通知', 'notification.manage' => '管理通知模板',
        'download.view' => '查看下载资源', 'download.manage' => '管理下载资源', 'inventory.view' => '查看库存', 'inventory.import' => '导入卡密', 'inventory.retry' => '确认重试交付',
        'upstream.view' => '查看上游', 'upstream.configure' => '配置上游', 'upstream.import' => '导入商品',
        'upstream.lifecycle' => '操作上游服务', 'upstream.hotswap' => '切换绑定',
        'cms.view' => '查看内容', 'cms.edit' => '编辑内容', 'cms.publish' => '发布内容',
        'finance.view' => '查看财务', 'finance.refund' => '全额退款', 'tasks.view' => '查看全部任务',
        'access.view' => '查看账户角色', 'access.manage' => '授撤业务角色', 'audit.view' => '查看操作审计'],
];
