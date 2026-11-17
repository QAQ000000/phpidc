<?php

declare(strict_types=1);

namespace App\Drivers\Payment;

use App\Contracts\PaymentGatewayInterface;
use App\Data\GatewayChargeResult;
use App\Data\VerifiedPaymentResult;
use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class EpayGateway implements PaymentGatewayInterface
{
    public function __construct(private readonly string $identifier, private readonly string $channel,
        private readonly string $apiUrl, private readonly string $pid, private readonly string $key) {}

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function isEnabled(): bool
    {
        return $this->apiUrl !== '' && $this->pid !== '' && $this->key !== '';
    }

    public function charge(Invoice $invoice, string $amount, string $outTradeNo): GatewayChargeResult
    {
        if (! $this->isEnabled() || ! preg_match('/^\d+\.\d{2}$/D', $amount)) {
            return new GatewayChargeResult(success: false, errorMessage: 'Payment gateway is not configured or amount is invalid.', outTradeNo: $outTradeNo);
        }
        $params = ['pid' => $this->pid, 'type' => $this->channel, 'out_trade_no' => $outTradeNo,
            'notify_url' => route('payments.notify', ['gateway' => $this->identifier]),
            'return_url' => url('/console/invoices/'.$invoice->id), 'name' => $invoice->invoice_no, 'money' => $amount,
            'clientip' => request()->ip() ?? '127.0.0.1'];
        $params['sign'] = $this->sign($params);
        $params['sign_type'] = 'MD5';
        $response = Http::asForm()->timeout(30)->post(rtrim($this->apiUrl, '/').'/mapi.php', $params);
        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || ($body['code'] ?? null) !== 1) {
            return new GatewayChargeResult(success: false, errorMessage: 'Payment initiation failed; the attempt was preserved.', outTradeNo: $outTradeNo);
        }
        $url = isset($body['payurl']) && is_string($body['payurl']) ? $body['payurl'] : null;
        $qr = isset($body['qrcode']) && is_string($body['qrcode']) ? $body['qrcode'] : null;

        return new GatewayChargeResult(success: $url !== null || $qr !== null, paymentUrl: $url, qrCodeUrl: $qr,
            errorMessage: $url === null && $qr === null ? 'Gateway returned no payment link.' : null, outTradeNo: $outTradeNo);
    }

    public function verifyCallback(Request $request): VerifiedPaymentResult
    {
        $data = $request->validate([
            'pid' => ['required', 'string'], 'out_trade_no' => ['required', 'string', 'max:64'],
            'trade_no' => ['required', 'string', 'max:128'], 'money' => ['required', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/D'],
            'trade_status' => ['required', 'in:TRADE_SUCCESS'], 'sign' => ['required', 'string', 'size:32'],
        ]);
        $payload = $request->all();
        foreach ($payload as $value) {
            if (! is_scalar($value) && $value !== null) {
                throw ValidationException::withMessages(['signature' => 'Malformed payment callback.']);
            }
        }
        if (! $this->isEnabled() || $data['pid'] !== $this->pid
            || (isset($payload['type']) && $payload['type'] !== $this->channel)
            || ! hash_equals($this->sign($payload), strtolower($data['sign']))) {
            throw ValidationException::withMessages(['signature' => 'Invalid payment signature or channel.']);
        }

        return new VerifiedPaymentResult(gateway: $this->identifier, gatewayTradeNo: $data['trade_no'], outTradeNo: $data['out_trade_no'],
            paidAmount: Money::of($data['money']), rawPayload: $payload);
    }

    /** @param array<string, scalar|null> $params */
    private function sign(array $params): string
    {
        unset($params['sign'], $params['sign_type']);
        $params = array_filter($params, fn ($value) => $value !== null && $value !== '');
        ksort($params, SORT_STRING);
        $pairs = [];
        foreach ($params as $name => $value) {
            $pairs[] = $name.'='.$value;
        }

        return md5(implode('&', $pairs).$this->key);
    }
}
