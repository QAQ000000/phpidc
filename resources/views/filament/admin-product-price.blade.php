@php
    $basePrice = null;
    $cycles = [\App\Enums\BillingCycle::MONTHLY, ...array_filter(\App\Enums\BillingCycle::cases(), fn ($cycle) => !in_array($cycle, [\App\Enums\BillingCycle::MONTHLY, \App\Enums\BillingCycle::ONTRIAL], true))];
    foreach ($cycles as $cycle) {
        $price = $product->local_prices[$cycle->value] ?? [];
        if (($price['enabled'] ?? false) && is_string($price['price'] ?? null) && preg_match('/^\d+\.\d{2}$/D', $price['price']) === 1) {
            $basePrice = ['amount' => $price['price'], 'cycle' => $cycle->label()];
            break;
        }
    }
@endphp
@if($basePrice)<span class="admin-ui-nowrap">{{ $basePrice['amount'] }} <small>{{ $product->currency }}</small></span><span class="admin-ui-meta">基础价 / {{ $basePrice['cycle'] }}</span>
@else<span class="admin-ui-meta">暂无普通周期基础价</span>@endif
