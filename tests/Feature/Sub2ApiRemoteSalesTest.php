<?php

declare(strict_types=1);

use App\Actions\ConfigureSub2ApiAction;
use App\Actions\CreateComboInvoiceAction;
use App\Actions\CreateSub2ApiProductAction;
use App\Actions\ExecuteFulfillmentTaskAction;
use App\Actions\HandlePaymentCallbackAction;
use App\Actions\RecoverSub2ApiCardAction;
use App\Actions\RefundInvoiceAction;
use App\Actions\RetryFulfillmentTaskAction;
use App\Data\VerifiedPaymentResult;
use App\Enums\BillingCycle;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Models\FulfillmentTask;
use App\Models\Product;
use App\Models\RedemptionCode;
use App\Models\UpstreamApi;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);
beforeEach(function (): void {
    $this->installApplicationForTest();
    Queue::fake();
    Http::preventStrayRequests();
});

function remoteSub2Product(string $amount = '100'): Product
{
    $account = app(ConfigureSub2ApiAction::class)->execute('Test Sub2API', 'https://sub2.test/api/v1', 'SECRET-ADMIN-KEY', 'Configure test connection');

    return app(CreateSub2ApiProductAction::class)->execute($account, 'Sub2API balance '.$amount, '10.00', $amount, 'CNY', 'Launch remote code');
}

function sub2Code(string $value = '100', int $id = 71): array
{
    return ['id' => $id, 'code' => 'SECRET-ISSUED-CARD', 'type' => 'balance', 'value' => $value, 'status' => 'unused'];
}

it('issues one snapshotted remote face value independently of customer wallet split and later product edits', function (bool $combo): void {
    $product = remoteSub2Product();
    $buyer = User::factory()->create(['credit' => $combo ? '3.00' : '20.00']);
    $this->actingAs($buyer)->get($product->publicPath())->assertOk()->assertSee('付款后自动生成兑换码')->assertSee('创建订单')->assertDontSee('暂时售罄');
    $invoice = app(CreateComboInvoiceAction::class)->execute($buyer, $product, BillingCycle::ONETIME, [], true, 'epay_wechat');
    if ($combo) {
        app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult('epay_wechat', 'REMOTE-PAY', $invoice->invoice_no, '7.00'));
    }
    $task = FulfillmentTask::sole();
    $product->update(['provisioning_rules' => ['sub2api_face_value' => '900']]);
    Http::fake(function ($request) use ($task) {
        expect(DB::transactionLevel())->toBe(1); // RefreshDatabase wraps the test; HTTP has no additional business transaction.
        expect($task->fresh()->payload['remote_delivery']['state'])->toBe('requested')
            ->and($request->header('x-api-key'))->toBe(['SECRET-ADMIN-KEY'])
            ->and($request->header('Idempotency-Key')[0])->toBe($task->fresh()->payload['remote_delivery']['idempotency_key'])
            ->and($request->data())->toBe(['count' => 1, 'type' => 'balance', 'value' => 100]);

        return Http::response('{"code":0,"data":[{"id":71,"code":"SECRET-ISSUED-CARD","type":"balance","value":100,"status":"unused"}]}');
    });
    $execute = app(ExecuteFulfillmentTaskAction::class);
    $execute->execute($task->id);
    $execute->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)
        ->and($task->instance->fresh()->encrypted_credentials['face_value'])->toBe('100.00')
        ->and($task->instance->fresh()->upstream_instance_id)->toBe('71')
        ->and($invoice->fresh()->status)->toBe(InvoiceStatus::PAID)
        ->and($buyer->fresh()->credit)->toBe($combo ? '0.00' : '10.00')
        ->and(RedemptionCode::sole()->status)->toBe('sold');
    Http::assertSentCount(1);
    $this->get('/console/instances/'.$task->instance_id)->assertOk()->assertSee('SECRET-ISSUED-CARD')->assertSee('100.00')->assertSee('https://sub2.test/redeem')->assertHeader('Cache-Control', 'no-store, private');
    $this->actingAs(User::factory()->create())->get('/console/instances/'.$task->instance_id)->assertForbidden();
    foreach (['fulfillment_tasks' => 'payload', 'redemption_codes' => 'code', 'instances' => 'encrypted_credentials', 'audit_logs' => 'after', 'upstream_operations' => 'response_body', 'upstream_apis' => 'encrypted_secret'] as $table => $column) {
        $raw = DB::table($table)->pluck($column)->implode(' ');
        expect($raw)->not->toContain('SECRET-ISSUED-CARD')->not->toContain('SECRET-ADMIN-KEY');
    }
    expect(DB::table('financial_transactions')->where('reference_key', 'settle:'.$invoice->id)->count())->toBe(1);
})->with([false, true]);

it('blocks invalid remote face values before changing a wallet or contacting upstream', function (): void {
    $product = remoteSub2Product();
    $product->update(['provisioning_rules' => ['sub2api_face_value' => '1.5']]);
    $buyer = User::factory()->create(['credit' => '20.00']);
    expect(fn () => app(CreateComboInvoiceAction::class)->execute($buyer, $product, BillingCycle::ONETIME, [], true))->toThrow(ValidationException::class);
    expect($buyer->fresh()->credit)->toBe('20.00');
    $this->assertDatabaseCount('invoices', 0);
    Http::assertNothingSent();
});

it('never replays an uncertain or rejected request even after an explicit generic retry', function (string $failure): void {
    $product = remoteSub2Product();
    app(CreateComboInvoiceAction::class)->execute(User::factory()->create(['credit' => '20.00']), $product, BillingCycle::ONETIME, [], true);
    $task = FulfillmentTask::sole();
    Http::fake(function () use ($failure) {
        return match ($failure) {
            'timeout' => throw new ConnectionException('SECRET-ADMIN-KEY'),
            'server' => Http::response(['code' => 500, 'message' => 'SECRET-ADMIN-KEY'], 500),
            'malformed' => Http::response('not-json'),
            'wrong_amount' => Http::response(['code' => 0, 'data' => [sub2Code('99')]]),
            'multiple' => Http::response(['code' => 0, 'data' => [sub2Code(), sub2Code()]]),
            'rejected' => Http::response(['code' => 401, 'message' => 'SECRET-ADMIN-KEY'], 401),
        };
    });
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED)
        ->and($task->instance->fresh()->encrypted_credentials)->toBeNull();
    app(RetryFulfillmentTaskAction::class)->execute($task, 'Review only; no permission to issue a second code');
    Http::fake(fn () => throw new LogicException('A second issuance must never happen.'));
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED);
    Http::assertNothingSent();
    $this->assertDatabaseCount('redemption_codes', 0);
    expect(DB::table('upstream_operations')->value('response_body'))->not->toContain('SECRET-ADMIN-KEY');
})->with(['timeout', 'server', 'malformed', 'wrong_amount', 'multiple', 'rejected']);

it('fences a remote response after refund, lease loss or binding change while preserving encrypted evidence', function (string $change): void {
    $product = remoteSub2Product();
    $buyer = User::factory()->create(['credit' => '20.00']);
    $invoice = app(CreateComboInvoiceAction::class)->execute($buyer, $product, BillingCycle::ONETIME, [], true);
    $task = FulfillmentTask::sole();
    $actor = User::factory()->create();
    Gate::define('refund-invoice', fn (User $user) => $user->id === $actor->id);
    Http::fake(function () use ($change, $invoice, $task, $actor) {
        match ($change) {
            'refund' => app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', true, 'Refund during remote request', $actor),
            'lease' => DB::table('fulfillment_tasks')->where('id', $task->id)->update(['locked_by' => 'replacement-worker']),
            'binding' => $task->instance->update(['upstream_api_id' => UpstreamApi::factory()->create()->id]),
        };

        return Http::response(['code' => 0, 'data' => [sub2Code()]]);
    });
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->instance->fresh()->encrypted_credentials)->toBeNull()
        ->and($task->fresh()->payload['remote_delivery']['encrypted_result'])->toBeString();
    $this->assertDatabaseCount('redemption_codes', 0);
    Http::assertSentCount(1);
})->with(['refund', 'lease', 'binding']);

it('recovers an operator-confirmed existing code using a read-only lookup and the original task', function (): void {
    $product = remoteSub2Product();
    app(CreateComboInvoiceAction::class)->execute(User::factory()->create(['credit' => '20.00']), $product, BillingCycle::ONETIME, [], true);
    $task = FulfillmentTask::sole();
    Http::fake(['https://sub2.test/api/v1/admin/redeem-codes/generate' => Http::response('uncertain', 502)]);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    $recover = app(RecoverSub2ApiCardAction::class);
    expect(fn () => $recover->execute($task, 71, 'Reconciled with upstream administrator', false))->toThrow(DomainException::class);
    Http::fake(function ($request) {
        expect($request->method())->toBe('GET')->and($request->url())->toBe('https://sub2.test/api/v1/admin/redeem-codes/71');

        return Http::response(['code' => 0, 'data' => sub2Code()]);
    });
    $recover->execute($task, 71, 'Matched the original request and confirmed ownership', true);
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::SUCCESS)->and(RedemptionCode::count())->toBe(1);
    Http::assertSentCount(1);
    $this->assertDatabaseHas('audit_logs', ['action' => 'remote_card.recover', 'reason' => 'Matched the original request and confirmed ownership']);
});

it('records sold remote-card loss on wallet refund without reclaiming or calling an upstream refund API', function (): void {
    $product = remoteSub2Product();
    $buyer = User::factory()->create(['credit' => '20.00']);
    $invoice = app(CreateComboInvoiceAction::class)->execute($buyer, $product, BillingCycle::ONETIME, [], true);
    $task = FulfillmentTask::sole();
    Http::fake(fn () => Http::response(['code' => 0, 'data' => [sub2Code()]]));
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    $actor = User::factory()->create();
    Gate::define('refund-invoice', fn (User $user) => $user->id === $actor->id);
    app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', true, 'Remote code cannot be reclaimed', $actor);
    expect($buyer->fresh()->credit)->toBe('20.00')->and(RedemptionCode::sole()->status)->toBe('sold')
        ->and($task->instance->fresh()->status)->toBe(InstanceStatus::CANCELLED);
    $this->assertDatabaseHas('financial_transactions', ['reference_key' => 'refund_card_loss:'.$invoice->id, 'type' => 'expense']);
    Http::assertSentCount(1);
});

it('keeps a committed request intent after a crashed worker and prevents reissuance after manual retry', function (): void {
    $product = remoteSub2Product();
    app(CreateComboInvoiceAction::class)->execute(User::factory()->create(['credit' => '20.00']), $product, BillingCycle::ONETIME, [], true);
    $task = FulfillmentTask::sole();
    Http::fake(fn () => throw new RuntimeException('Simulated worker crash after sending the request'));
    expect(fn () => app(ExecuteFulfillmentTaskAction::class)->execute($task->id))->toThrow(RuntimeException::class);
    expect($task->fresh()->payload['remote_delivery']['state'])->toBe('requested');
    $task->update(['status' => FulfillmentTaskStatus::FAILED]);
    app(RetryFulfillmentTaskAction::class)->execute($task, 'Operator reviewed the interrupted attempt');
    Http::fake(fn () => throw new LogicException('The interrupted request must not be replayed'));
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    Http::assertNothingSent();
    expect($task->fresh()->status)->toBe(FulfillmentTaskStatus::FAILED);
});

it('fences results if the connection endpoint changes while a remote request is running', function (): void {
    $product = remoteSub2Product();
    app(CreateComboInvoiceAction::class)->execute(User::factory()->create(['credit' => '20.00']), $product, BillingCycle::ONETIME, [], true);
    $task = FulfillmentTask::sole();
    Http::fake(function () use ($product) {
        $product->upstreamApi->update(['base_url' => 'https://different-sub2.test']);

        return Http::response(['code' => 0, 'data' => [sub2Code()]]);
    });
    app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    expect($task->instance->fresh()->encrypted_credentials)->toBeNull()
        ->and($task->fresh()->payload['remote_delivery']['encrypted_result'])->toBeString();
    $this->assertDatabaseCount('redemption_codes', 0);
    Http::assertSentCount(1);
});

it('creates encrypted connections and fixed-face products through audited operator commands', function (): void {
    $this->artisan('sub2api:connect', ['name' => 'CLI Sub2API', 'url' => 'https://sub2.test/api/v1', '--reason' => 'Connect test service'])
        ->expectsQuestion('Administrator API key', 'CLI-PRIVATE-KEY')->assertSuccessful();
    $account = UpstreamApi::sole();
    expect($account->base_url)->toBe('https://sub2.test')->and($account->encrypted_secret)->toBe('CLI-PRIVATE-KEY')
        ->and(DB::table('upstream_apis')->value('encrypted_secret'))->not->toContain('CLI-PRIVATE-KEY');
    $this->artisan('sub2api:create-product', ['account' => $account->id, 'name' => 'Five credits', 'price' => '2.00', 'face_value' => '5', '--reason' => 'Launch fixed face product'])->assertSuccessful();
    $this->assertDatabaseHas('audit_logs', ['action' => 'sub2api.product_create', 'reason' => 'Launch fixed face product']);
    Http::assertNothingSent();
});
