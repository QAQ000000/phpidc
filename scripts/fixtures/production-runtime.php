<?php

declare(strict_types=1);

// Only the disposable B5 runner supplies this bootstrap and its private connections.
use App\Actions\AssignAdminRoleAction;
use App\Actions\AssignUpstreamRoleAction;
use App\Actions\ConfigureClassicUpstreamAction;
use App\Actions\ConfigureEpayAction;
use App\Actions\ConfigureIdentityAction;
use App\Actions\CreateLocalCardProductAction;
use App\Actions\ImportClassicProductAction;
use App\Actions\ImportRedemptionCodesAction;
use App\Actions\SyncClassicCatalogAction;
use App\Enums\BillingCycle;
use App\Enums\FulfillmentTaskStatus;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Enums\PaymentCallbackOutcome;
use App\Enums\TransactionType;
use App\Models\FinancialTransaction;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\NotificationDelivery;
use App\Models\PaymentAttempt;
use App\Models\PaymentCallback;
use App\Models\Product;
use App\Models\RedemptionCode;
use App\Models\User;
use App\Support\InstallationState;
use App\Support\MaintenanceState;
use App\Support\Money;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

function requireRuntime(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$root = realpath(getenv('B5_ROOT') ?: '');
$repo = realpath(getenv('B5_REPO') ?: '');
$bootstrap = realpath(getenv('B5_BOOTSTRAP') ?: '');
requireRuntime($root !== false && $repo !== false && $bootstrap !== false
    && str_starts_with($bootstrap, $root.DIRECTORY_SEPARATOR), 'A private B5 bootstrap is required.');
require $repo.'/vendor/autoload.php';
$app = require $bootstrap;
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
requireRuntime($app->environment('production') && $connection->getDriverName() === 'mysql'
    && $connection->getDatabaseName() === 'production_runtime_only'
    && str_starts_with((string) $connection->getConfig('unix_socket'), $root.DIRECTORY_SEPARATOR), 'Private B5 MySQL only.');
requireRuntime(str_starts_with((string) config('database.redis.default.host'), $root.DIRECTORY_SEPARATOR)
    && str_starts_with((string) config('database.redis.cache.host'), $root.DIRECTORY_SEPARATOR)
    && config('queue.default') === 'redis', 'Private B5 Redis only.');
app(InstallationState::class)->assertKey(requireSeal: true);

function runtimeFixture(): array
{
    return json_decode(file_get_contents(getenv('B5_ROOT').'/fixture.json'), true, flags: JSON_THROW_ON_ERROR);
}

function runtimeSnapshot(): array
{
    $fixture = runtimeFixture();
    $queues = [];
    foreach (['default', 'maintenance', 'lifecycle-urgent', 'lifecycle', 'catalog', 'receivable-check'] as $queue) {
        $redis = Redis::connection(config('queue.connections.redis.connection', 'default'));
        $queues[$queue] = ['ready' => (int) $redis->llen('queues:'.$queue),
            'delayed' => (int) $redis->zcard('queues:'.$queue.':delayed'),
            'reserved' => (int) $redis->zcard('queues:'.$queue.':reserved')];
    }
    $maintenance = [];
    $state = app(MaintenanceState::class);
    foreach (config('maintenance.kinds') as $kind) {
        $maintenance[$kind] = array_intersect_key($state->snapshot($kind), array_flip([
            'started_at', 'completed_at', 'failed_at', 'selected', 'attempted', 'succeeded', 'failed',
            'units', 'deferred', 'budget_exhausted', 'error_code',
        ]));
    }

    return ['fixture' => $fixture, 'migrations' => DB::table('migrations')->count(),
        'users' => User::orderBy('id')->get()->map(fn (User $user): array => $user->only([
            'id', 'credit', 'frozen_credit', 'wallet_clearance_frozen', 'affiliate_balance', 'affiliate_frozen_balance',
        ]))->all(),
        'invoices' => Invoice::orderBy('id')->get()->map(fn (Invoice $invoice): array => [
            'id' => $invoice->id, 'user_id' => $invoice->user_id, 'instance_id' => $invoice->instance_id,
            'product_id' => $invoice->product_id, 'type' => $invoice->type->value, 'status' => $invoice->status->value,
            'total_amount' => $invoice->total_amount, 'gateway_amount' => $invoice->gateway_amount,
            'credit_applied' => $invoice->credit_applied, 'discount_amount' => $invoice->discount_amount,
            'currency' => $invoice->currency, 'upstream_expected_cost' => $invoice->upstream_expected_cost,
        ])->all(),
        'attempts' => PaymentAttempt::orderBy('id')->get()->map(fn (PaymentAttempt $attempt): array => $attempt->only([
            'id', 'invoice_id', 'gateway', 'out_trade_no', 'amount', 'status',
        ]))->all(),
        'callbacks' => PaymentCallback::orderBy('id')->get()->map(fn (PaymentCallback $callback): array => [
            'id' => $callback->id, 'invoice_id' => $callback->invoice_id, 'gateway' => $callback->gateway,
            'outcome' => $callback->outcome->value, 'amount' => $callback->amount,
        ])->all(),
        'tasks' => FulfillmentTask::orderBy('id')->get()->map(fn (FulfillmentTask $task): array => [
            'id' => $task->id, 'user_id' => $task->user_id, 'invoice_id' => $task->invoice_id,
            'instance_id' => $task->instance_id, 'action' => $task->action, 'status' => $task->status->value,
            'attempts' => $task->attempts, 'idempotency_key' => $task->idempotency_key,
        ])->all(),
        'instances' => Instance::orderBy('id')->get()->map(fn (Instance $instance): array => [
            'id' => $instance->id, 'user_id' => $instance->user_id, 'product_id' => $instance->product_id,
            'status' => $instance->status->value, 'driver_type' => $instance->driver_type->value,
            'upstream_instance_id' => $instance->upstream_instance_id,
            'recurring_amount' => $instance->recurring_amount,
            'next_due_at' => $instance->next_due_at?->toIso8601String(),
            'last_synced_at' => $instance->last_synced_at?->toIso8601String(),
            'configoption' => $instance->active_config['upstream_configoption'] ?? [],
            'has_credentials' => $instance->getRawOriginal('encrypted_credentials') !== null,
        ])->all(),
        'ledger' => FinancialTransaction::orderBy('id')->get()->map(fn (FinancialTransaction $row): array => [
            'id' => $row->id, 'user_id' => $row->user_id, 'invoice_id' => $row->invoice_id,
            'type' => $row->type->value, 'amount' => $row->amount, 'reference_key' => $row->reference_key,
            'balance_after' => $row->balance_after, 'frozen_after' => $row->frozen_after,
        ])->all(),
        'notifications' => NotificationDelivery::orderBy('id')->get()->map(fn (NotificationDelivery $delivery): array => $delivery->only([
            'id', 'notification_event_id', 'user_id', 'job_kind', 'channel', 'status', 'attempts',
        ]))->all(),
        'notification_events' => DB::table('notification_events')->orderBy('id')->get(['id', 'user_id', 'event_type', 'event_key'])->all(),
        'inventory' => DB::table('redemption_codes')->orderBy('id')->get(['id', 'product_id', 'status', 'instance_id'])->all(),
        'maintenance' => $maintenance, 'queues' => $queues, 'failed_jobs' => DB::table('failed_jobs')->count()];
}

function verifyRuntime(): array
{
    $fixture = runtimeFixture();
    $buyer = User::findOrFail($fixture['customer_id']);
    $invoices = Invoice::where('user_id', $buyer->id)->where('product_id', $fixture['classic_product_id'])->orderBy('id')->get();
    requireRuntime($invoices->count() === 3, 'Classic replay created or lost an invoice.');
    $expected = [[InvoiceType::NEW_ORDER, '9.60', '8.00'], [InvoiceType::RENEW, '9.60', '8.00'], [InvoiceType::UPGRADE, '2.40', '2.00']];
    foreach ($invoices as $offset => $invoice) {
        [$type, $amount, $cost] = $expected[$offset];
        requireRuntime($invoice->type === $type && $invoice->status === InvoiceStatus::PAID
            && $invoice->currency === 'CNY' && $invoice->total_amount === $amount
            && $invoice->gateway_amount === $amount && $invoice->credit_applied === '0.00'
            && $invoice->discount_amount === '0.00' && $invoice->upstream_expected_cost === $cost,
            'Classic immutable invoice or payment identity differs.');
        $task = FulfillmentTask::where('invoice_id', $invoice->id)->sole();
        requireRuntime($task->status === FulfillmentTaskStatus::SUCCESS && $task->attempts === 1
            && $task->user_id === $buyer->id && $task->instance_id === $invoice->instance_id,
            'Classic delivery is incomplete, repeated or assigned to another identity.');
        $callback = PaymentCallback::where('invoice_id', $invoice->id)->sole();
        requireRuntime($callback->outcome === PaymentCallbackOutcome::PROCESSED_PAID && $callback->amount === $amount,
            'Duplicate callback or wrong payment outcome.');
        $attempt = PaymentAttempt::where('invoice_id', $invoice->id)->sole();
        requireRuntime($attempt->status === 'paid' && $attempt->amount === $amount, 'Payment attempt differs.');
        $payment = FinancialTransaction::where('reference_key', 'payment:'.$invoice->id)->sole();
        $settlement = FinancialTransaction::where('reference_key', 'settle:'.$invoice->id)->sole();
        requireRuntime($payment->type === TransactionType::PAYMENT && $payment->amount === $amount
            && $payment->user_id === $buyer->id && $payment->invoice_id === $invoice->id
            && $settlement->type === TransactionType::CREDIT_SETTLE && $settlement->amount === '0.00'
            && $settlement->user_id === $buyer->id && $settlement->invoice_id === $invoice->id,
            'Payment and wallet settlement ledger identity differs.');
        requireRuntime(FinancialTransaction::where('invoice_id', $invoice->id)->count() === 2, 'Unexpected classic ledger movement.');
    }
    requireRuntime($invoices->pluck('instance_id')->unique()->count() === 1, 'Renewal/upgrade created another service.');
    $instance = Instance::where('product_id', $fixture['classic_product_id'])->sole();
    requireRuntime($instance->status === InstanceStatus::ACTIVE && $instance->user_id === $buyer->id
        && $instance->upstream_instance_id === '999' && $instance->recurring_amount === '12.00'
        && (int) ($instance->active_config['upstream_configoption']['50'] ?? 0) === 502,
        'Final classic binding, recurring price or selected configuration differs.');
    requireRuntime(($instance->encrypted_credentials['password'] ?? null) === 'DELIVERED-HOST-PASSWORD'
        && ! str_contains($instance->getRawOriginal('encrypted_credentials'), 'DELIVERED-HOST-PASSWORD'),
        'Delivered host credentials are missing or plaintext.');
    foreach (['credit', 'frozen_credit', 'wallet_clearance_frozen', 'affiliate_balance', 'affiliate_frozen_balance'] as $column) {
        requireRuntime($buyer->getAttribute($column) === '0.00', 'A full-gateway purchase changed a wallet balance.');
    }
    $code = RedemptionCode::findOrFail($fixture['card_code_id']);
    requireRuntime($code->code === 'B5-LOCAL-RUNTIME-CARD'
        && ! str_contains($code->getRawOriginal('code'), 'B5-LOCAL-RUNTIME-CARD')
        && hash_equals($code->code_hash, hash_hmac('sha256', 'B5-LOCAL-RUNTIME-CARD', config('app.key'))),
        'Local inventory encryption or lookup HMAC differs.');
    requireRuntime(NotificationDelivery::count() > 0 && ! NotificationDelivery::where('status', '!=', 'sent')->exists(),
        'Notification outbox has an unconfirmed or simulated delivery.');
    requireRuntime(! NotificationDelivery::where('attempts', '!=', 1)->exists(), 'A notification was sent more than once.');
    requireRuntime(DB::table('failed_jobs')->count() === 0, 'A resident worker failed a job.');
    $provisioningPassword = getenv('B5_PROVISIONING_PASSWORD') ?: '';
    requireRuntime($provisioningPassword !== '', 'The generated provisioning password is required for redaction verification.');
    foreach (DB::table('upstream_operations')->get(['request_payload', 'response_body']) as $operation) {
        $serialized = json_encode($operation, JSON_THROW_ON_ERROR);
        foreach (['supplier-api-secret', 'supplier-private-jwt', $provisioningPassword, 'DELIVERED-HOST-PASSWORD'] as $secret) {
            requireRuntime(! str_contains($serialized, $secret), 'Upstream operation history leaked a private transport value.');
        }
    }

    return ['status' => 'PASS', 'checks' => ['classic_invoice_identity', 'callback_and_task_idempotency',
        'payment_and_zero_wallet_settlement_ledger', 'original_owner_and_binding', 'encrypted_host_and_local_card',
        'smtp_outbox_sent_once', 'no_failed_jobs', 'redacted_upstream_history'], 'snapshot' => runtimeSnapshot()];
}

$mode = $argv[1] ?? '';
if ($mode === 'seed') {
    requireRuntime(! is_file($root.'/fixture.json') && Product::count() === 0 && Invoice::count() === 0,
        'B5 seed requires its newly installed empty business database.');
    $url = getenv('B5_SUPPLIER_URL') ?: '';
    $parts = parse_url($url);
    requireRuntime(is_array($parts) && ($parts['scheme'] ?? null) === 'http' && ($parts['host'] ?? null) === '127.0.0.1'
        && isset($parts['port']) && ! isset($parts['user']) && ! isset($parts['pass'])
        && ! isset($parts['query']) && ! isset($parts['fragment'])
        && in_array($parts['path'] ?? '', ['', '/'], true), 'Only the owned loopback supplier is allowed.');
    $password = getenv('B5_CUSTOMER_PASSWORD') ?: '';
    $smtp = getenv('B5_SMTP_PORT') ?: '';
    requireRuntime(strlen($password) >= 12 && ctype_digit($smtp) && (int) $smtp > 0 && (int) $smtp <= 65535,
        'Private customer password and SMTP port are required.');
    app(ConfigureEpayAction::class)->execute($url, 'test-merchant', 'epay-test-secret', 'B5 isolated gateway');
    app(ConfigureIdentityAction::class)->execute(['mail.transport' => 'smtp', 'mail.host' => '127.0.0.1',
        'mail.port' => (int) $smtp, 'mail.username' => '', 'mail.password' => '',
        'mail.from_address' => 'runtime@example.test'], 'B5 owned loopback SMTP');
    $account = app(ConfigureClassicUpstreamAction::class)->execute('B5 classic supplier', $url,
        'supplier@example.test', 'supplier-api-secret', 'B5 isolated classic connection');
    $product = app(ImportClassicProductAction::class)->execute($account->id, '17', BillingCycle::MONTHLY,
        'CNY', '20.00', 'B5 existing classic protocol product');
    app(SyncClassicCatalogAction::class)->execute($product, 'B5 synchronized configuration');
    $customer = User::create(['name' => 'Runtime Buyer', 'email' => 'runtime-buyer@example.test', 'password' => $password]);
    $operator = User::create(['name' => 'Runtime Operator', 'email' => 'runtime-operator@example.test', 'password' => $password]);
    foreach (['upstream-operator', 'upstream-lifecycle', 'upstream-migrator'] as $role) {
        app(AssignUpstreamRoleAction::class)->execute($operator, $role, 'B5 isolated operator');
    }
    app(AssignAdminRoleAction::class)->execute($operator, 'finance-viewer', 'B5 configured operations recipient');
    $card = app(CreateLocalCardProductAction::class)->execute('Runtime Card', '10.00', Money::zero(), 'CNY', 'B5 local delivery');
    requireRuntime(app(ImportRedemptionCodesAction::class)->execute($card, ['B5-LOCAL-RUNTIME-CARD'], 'B5 private inventory') === 1,
        'Local inventory was not created exactly once.');
    requireRuntime(app(ImportRedemptionCodesAction::class)->execute($card, ['B5-LOCAL-RUNTIME-CARD'], 'B5 duplicate inventory') === 0,
        'Repeated inventory import created another card.');
    $fixture = ['customer_id' => $customer->id, 'customer_email' => $customer->email, 'operator_id' => $operator->id,
        'classic_product_id' => $product->id, 'card_product_id' => $card->id,
        'card_code_id' => RedemptionCode::where('product_id', $card->id)->sole()->id];
    requireRuntime(file_put_contents($root.'/fixture.json', json_encode($fixture, JSON_THROW_ON_ERROR)) !== false,
        'Could not persist the private fixture IDs.');
    $result = $fixture;
} elseif ($mode === 'snapshot') {
    $result = runtimeSnapshot();
} elseif ($mode === 'verify') {
    $result = verifyRuntime();
} else {
    throw new InvalidArgumentException('Use seed, snapshot or verify.');
}
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE).PHP_EOL;
