@php
    $purchaseId = $purchaseRequestId ?? ((string) old('product_id') === (string) $product->id ? old('request_id') : null);
    if (!is_string($purchaseId) || !\Illuminate\Support\Str::isUlid($purchaseId)) {
        $purchaseId = (string) \Illuminate\Support\Str::ulid();
    }
@endphp
<input type="hidden" name="request_id" value="{{ $purchaseId }}">
