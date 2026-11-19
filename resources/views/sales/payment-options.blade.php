@forelse(app(\App\Support\PaymentChannels::class)->available() as $gatewayId => $gatewayLabel)
<option value="{{ $gatewayId }}" @selected(($selectedGateway ?? null) === $gatewayId)>{{ $gatewayLabel }}</option>
@empty
<option value="">外部支付暂未开放</option>
@endforelse
