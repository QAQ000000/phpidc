<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CallUpstreamAction;
use App\Actions\CreateComboInvoiceAction;
use App\Actions\DescribeStoreProductAction;
use App\Actions\DowngradeCalculatorAction;
use App\Actions\RequestInstanceOperationAction;
use App\Actions\ResolveProductOptionsAction;
use App\Contracts\LiveQuotable;
use App\Enums\BillingCycle;
use App\Models\Instance;
use App\Models\Product;
use App\Models\User;
use App\Services\DriverManager;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ServiceBillingController extends Controller
{
    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function run(callable $call): mixed
    {
        try {
            return $call();
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['operation' => $e->getMessage()]);
        }
    }

    public function configure(Request $request, Product $product)
    {
        $this->user($request);
        abort_unless(Product::publicCatalog()->whereKey($product->id)->exists(), 404);

        return response()->view('sales.configure', ['product' => $product, 'details' => app(DescribeStoreProductAction::class)->execute($product), 'quote' => null, 'selection' => []])->header('Cache-Control', 'private, no-store');
    }

    public function quote(Request $request, Product $product)
    {
        $this->user($request);
        abort_unless(Product::publicCatalog()->whereKey($product->id)->exists(), 404);
        $data = $request->validate(['billing_cycle' => ['required', Rule::enum(BillingCycle::class)], 'options' => ['sometimes', 'array'],
            'request_id' => ['sometimes', 'ulid']]);
        $quote = $this->run(function () use ($product, $data) {
            $options = app(ResolveProductOptionsAction::class)->execute($product, $data['billing_cycle'], $data['options'] ?? []);
            $driver = app(DriverManager::class)->fulfillment($product->driver_type, $product->upstream_api_id);
            if (! $driver instanceof LiveQuotable) {
                throw new DomainException('This product has no live quote.');
            }

            return app(CallUpstreamAction::class)->execute(fn () => $driver->quotePurchase($product, $data['billing_cycle'], $options));
        });
        if ($request->expectsJson()) {
            return response()->json(['price' => $quote->downstreamPrice, 'setup_fee' => $quote->setupFee, 'currency' => $quote->currency])->header('Cache-Control', 'private, no-store');
        }

        return response()->view('sales.configure', ['product' => $product, 'details' => app(DescribeStoreProductAction::class)->execute($product),
            'quote' => $quote, 'selection' => $data, 'purchaseRequestId' => $data['request_id'] ?? null])->header('Cache-Control', 'private, no-store');
    }

    public function bill(Request $request, Instance $instance)
    {
        $user = $this->user($request);
        abort_unless($instance->user_id === $user->id, 403);
        $data = $request->validate(['action' => ['required', Rule::in(['renew', 'upgrade', 'downgrade', 'terminate'])], 'options' => ['sometimes', 'array'], 'request_id' => ['required', 'ulid'],
            'coupon_code' => ['nullable', 'string', 'max:64'], 'use_credit' => ['sometimes', 'boolean'], 'gateway' => ['nullable', Rule::in(['epay_wechat', 'epay_alipay'])], 'reason' => ['required', 'string', 'max:1000'], 'confirm' => ['accepted']]);
        if ($data['action'] === 'terminate') {
            $this->run(fn () => app(RequestInstanceOperationAction::class)->execute($instance, 'terminate', $data['reason'], $data['request_id'], $user, 'client_action'));

            return redirect('/console/instances/'.$instance->id)->with('status', '撤销申请已入队；等待上游确认删除。');
        }
        if ($data['action'] === 'downgrade') {
            $this->run(fn () => app(DowngradeCalculatorAction::class)->execute($instance, $data['options'] ?? [], $user, $data['reason']));

            return redirect('/console/instances/'.$instance->id)->with('status', '未来续费价格已调整；本期不退款，远程配置不变。');
        }
        $invoice = $this->run(fn () => $data['action'] === 'renew'
            ? app(CreateComboInvoiceAction::class)->renewal($user, $instance, $request->boolean('use_credit'), $data['gateway'] ?? 'epay_wechat', $data['request_id'], $data['coupon_code'] ?? null)
            : app(CreateComboInvoiceAction::class)->upgrade($user, $instance, $data['options'] ?? [], $data['request_id'], $request->boolean('use_credit'), $data['gateway'] ?? 'epay_wechat'));

        return redirect('/console/invoices/'.$invoice->id);
    }
}
