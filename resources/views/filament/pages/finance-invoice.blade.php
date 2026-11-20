<x-filament-panels::page>
@include('filament.admin-ui-styles')
<link rel="stylesheet" href="/css/admin-finance-invoice.css?v=20261120-u2c">
@php
    $workspaceOrigin = fn (?string $section = null): array => \App\Support\AdminWorkspaceNavigation::origin(auth()->user(), 'invoice', $invoice->id, $workspaceQuery, $section);
    $workspaceAction = fn (string $path): string => $path.($workspaceQuery === [] ? '' : '?'.http_build_query($workspaceQuery));
    $workspaceReturn = \App\Support\AdminWorkspaceNavigation::returnLink(auth()->user(), $workspaceQuery, $returnUrl, '返回账单');
    $workspaceStatus = $invoice->type === \App\Enums\InvoiceType::WALLET_TOPUP && $invoice->status === \App\Enums\InvoiceStatus::PAID ? '已到账' : $statusLabels[$invoice->status->value];
    $workspaceTone = match($invoice->status) { \App\Enums\InvoiceStatus::PAID => 'success', \App\Enums\InvoiceStatus::UNPAID => 'warning', \App\Enums\InvoiceStatus::REFUNDED => 'danger', default => 'neutral' };
    $restoreOffline = $offlineAllowed && $errors->any() && old('_admin_form') === 'offline-receipt'
        && is_scalar(old('invoice_id')) && (string) old('invoice_id') === (string) $invoice->id;
    $refundInput = old('ui_form') === 'refund-'.$invoice->id;
    $restoreRefund = $canRefund && $refundInput && $errors->any();
    $offlineValue = function ($key, $default = '') use ($restoreOffline) {
        $value = $restoreOffline ? old($key, $default) : $default;
        return is_scalar($value) || $value === null ? $value : $default;
    };
    $offlineRequest = $offlineValue('request_id');
    $offlineRequest = is_string($offlineRequest) && \Illuminate\Support\Str::isUlid($offlineRequest) ? $offlineRequest : (string) \Illuminate\Support\Str::ulid();
    $taskLabels = ['pending' => '待执行', 'running' => '执行中', 'success' => '已完成', 'failed' => '失败', 'stale' => '已失效'];
@endphp
<div class="admin-ui admin-finance-invoice" x-data>
    @include('filament.inventory-feedback')
    @if(session('status'))<p role="status" class="inventory-alert">{{ session('status') }}</p>@endif
    <x-admin.workspace-header :name="$invoice->invoice_no" :identity="'账单 #'.$invoice->id.' · '.$typeLabels[$invoice->type->value]" :status="$workspaceStatus" :tone="$workspaceTone" :return-url="$workspaceReturn['url']" :return-label="$workspaceReturn['label']" />
    <nav class="admin-workspace-tabs" aria-label="账单页签">
        @foreach($tabLinks as $key => $link)<a class="admin-workspace-tab" href="{{ $link['url'] }}" @if($tab === $key) aria-current="page" @endif>{{ $link['label'] }}</a>@endforeach
    </nav>
    @if($tab === 'overview')
        @include('filament.pages.invoice-workspace.overview')
    @else
        @include('filament.pages.invoice-workspace.history')
    @endif
    @include('filament.pages.invoice-workspace.dialogs')
</div>
</x-filament-panels::page>
