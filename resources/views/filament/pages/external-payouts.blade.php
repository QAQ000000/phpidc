<x-filament-panels::page>
@include('filament.admin-ui-styles')
<link rel="stylesheet" href="{{ asset('css/admin-operations.css') }}">
<div class="admin-ui admin-operations-page" x-data>
@include('filament.inventory-feedback')
<header class="admin-ui-heading"><div><p>审批只冻结佣金；请在记录执行意图后按原标识人工转账。已支付须核对唯一凭证；不确定款保持冻结。</p></div></header>
@include('filament.settlements.payout-list', ['kind' => 'external', 'pagePath' => '/admin/external-payouts', 'permissionPrefix' => 'finance.external-payout', 'formMarker' => 'external-payout'])
</div>
</x-filament-panels::page>
