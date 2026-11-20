<x-filament-panels::page>
@include('filament.admin-ui-styles')
<link rel="stylesheet" href="/css/admin-operations.css?v=20261120-b1">
@php
    $restoreContext = $errors->any() && is_string(old('_admin_form')) ? old('_admin_form') : null;
@endphp
<div class="admin-ui admin-operations-page" x-data>
    @if(session('inventory_status'))<p role="status" class="inventory-alert">{{ session('inventory_status') }}</p>@endif
    @if($errors->any())<div role="alert" class="inventory-alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    @if($errors->has('receivable_check_url'))<p><a class="admin-ui-link" href="{{ $errors->first('receivable_check_url') }}">查看原请求完整核验进度</a></p>@endif
    @include('filament.operations.'.$section)
</div>
</x-filament-panels::page>
