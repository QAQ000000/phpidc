"""Verify review renewal fixes using a disposable offline MySQL, including real lock races."""

import base64
import json
import os
import pathlib
import subprocess
import tempfile
import time
import uuid

repo = pathlib.Path(__file__).resolve().parents[1]
root = pathlib.Path(tempfile.mkdtemp(prefix='laravel13idc-review-renewal-mysql-'))
socket_dir = root / 'socket'
socket_dir.mkdir(mode=0o777)
storage = root / 'storage'
for folder in ['framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private']:
    (storage / folder).mkdir(parents=True)
env = dict(os.environ, APP_ENV='testing', APP_DEBUG='false',
           APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(),
           APP_CONFIG_CACHE=str(root / 'config.php'), APP_ROUTES_CACHE=str(root / 'routes.php'),
           APP_PACKAGES_CACHE=str(root / 'packages.php'), APP_SERVICES_CACHE=str(root / 'services.php'),
           LARAVEL_STORAGE_PATH=str(storage), VIEW_COMPILED_PATH=str(storage / 'framework/views'),
           DB_CONNECTION='mysql', DB_URL='', DB_HOST='localhost', DB_PORT='3306',
           DB_SOCKET=str(socket_dir / 'mysqld.sock'), DB_DATABASE='review_renewal',
           DB_USERNAME='root', DB_PASSWORD='', CACHE_STORE='array', QUEUE_CONNECTION='sync',
           SESSION_DRIVER='array', MAIL_MAILER='array', LOG_CHANNEL='single', BCRYPT_ROUNDS='4',
           REVIEW_REPO=str(repo), REVIEW_ROOT=str(root))
php = '/www/server/php/84/bin/php'
container = 'laravel13idc-review-renewal-' + uuid.uuid4().hex[:12]
probe = root / 'probe.php'
probe.write_text(r'''<?php
declare(strict_types=1);
require getenv('REVIEW_REPO').'/vendor/autoload.php';
$app = require getenv('REVIEW_REPO').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Queue::fake();
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Carbon::setTestNow('2026-11-18T00:00:00Z');
$mode = $argv[1];
$case = $argv[2] ?? 'duplicate';
$root = getenv('REVIEW_ROOT');
$check = static function(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } };
if ($mode === 'ready') { echo Illuminate\Support\Facades\DB::selectOne('SELECT VERSION() AS version')->version; exit; }
if ($mode === 'waiting') { echo Illuminate\Support\Facades\DB::selectOne("SELECT COUNT(*) AS n FROM information_schema.innodb_trx WHERE trx_state = 'LOCK WAIT'")->n; exit; }
if ($mode === 'seed') {
    $user = App\Models\User::factory()->create(['credit' => '3.00']);
    $account = App\Models\UpstreamApi::factory()->create();
    $product = App\Models\Product::factory()->create(['driver_type' => App\Enums\DriverType::ZJMF_API, 'upstream_api_id' => $account->id, 'markup_percent' => '0.00']);
    $instance = App\Models\Instance::factory()->active()->create(['user_id' => $user->id, 'product_id' => $product->id,
        'driver_type' => App\Enums\DriverType::ZJMF_API, 'upstream_api_id' => $account->id, 'upstream_instance_id' => '99',
        'billing_cycle' => 'monthly', 'next_due_at' => now()->addDay(), 'recurring_amount' => '10.00', 'active_config' => []]);
    file_put_contents($root.'/'.$case.'.json', json_encode(['user' => $user->id, 'instance' => $instance->id,
        'request' => (string) Illuminate\Support\Str::ulid(), 'due' => $instance->next_due_at->toIso8601String()], JSON_THROW_ON_ERROR));
}
$fixture = json_decode(file_get_contents($root.'/'.$case.'.json'), true, flags: JSON_THROW_ON_ERROR);
$user = App\Models\User::findOrFail($fixture['user']);
$instance = App\Models\Instance::findOrFail($fixture['instance']);
Illuminate\Support\Facades\Http::fake(static function($request) use ($fixture, $check) {
    $check(Illuminate\Support\Facades\DB::transactionLevel() === 0, 'HTTP under transaction');
    return Illuminate\Support\Facades\Http::response(match (parse_url($request->url(), PHP_URL_PATH)) {
        '/zjmf_api_login' => ['status' => 200, 'jwt' => 'isolated-review-renewal'],
        '/host/header' => ['status' => 200, 'data' => ['host_data' => ['domainstatus' => 'Active', 'nextduedate' => $fixture['due']]]],
        '/host/renewpage' => ['status' => 200, 'data' => ['host' => ['amount' => '10.00', 'billingcycle' => 'monthly'], 'currency' => ['code' => 'CNY']]],
        default => throw new LogicException('Unexpected offline endpoint'),
    });
});
if ($mode === 'seed') {
    $invoice = app(App\Actions\CreateComboInvoiceAction::class)->automaticRenewal($user, $instance);
    $fixture['invoice'] = $invoice->id;
    if ($case === 'callback') {
        app(App\Actions\CreateComboInvoiceAction::class)->renewal($user, $instance, true, 'epay_alipay', $fixture['request']);
        App\Models\PaymentAttempt::create(['invoice_id' => $invoice->id, 'gateway' => 'epay_alipay',
            'out_trade_no' => 'PAY'.$fixture['request'], 'amount' => '7.00', 'status' => 'created']);
    }
    file_put_contents($root.'/'.$case.'.json', json_encode($fixture, JSON_THROW_ON_ERROR));
    echo 'seeded'; exit;
}
if ($mode === 'hold') {
    Illuminate\Support\Facades\DB::beginTransaction();
    App\Models\User::whereKey($user->id)->lockForUpdate()->firstOrFail();
    touch($root.'/'.$case.'.held');
    $deadline = microtime(true) + 20;
    while (!file_exists($root.'/'.$case.'.release')) { if (microtime(true) > $deadline) { throw new RuntimeException('Lock release timeout'); } usleep(20000); }
    Illuminate\Support\Facades\DB::commit(); echo 'released'; exit;
}
if (in_array($mode, ['confirm', 'terminate', 'callback'], true)) {
    try {
        $result = match ($mode) {
            'confirm' => app(App\Actions\CreateComboInvoiceAction::class)->renewal($user, $instance, true, 'epay_alipay', $fixture['request']),
            'terminate' => app(App\Actions\RequestInstanceOperationAction::class)->execute($instance, 'terminate', 'Owner ends service during a concurrent operation', $fixture['request'], $user, 'client_action'),
            'callback' => app(App\Actions\HandlePaymentCallbackAction::class)->execute(new App\Data\VerifiedPaymentResult('epay_alipay', 'TRADE'.$fixture['request'], 'PAY'.$fixture['request'], '7.00')),
        };
        echo json_encode(['status' => 'committed', 'id' => $result instanceof Illuminate\Database\Eloquent\Model ? $result->id : null]);
    } catch (Illuminate\Validation\ValidationException|DomainException $e) { echo json_encode(['status' => 'rejected', 'reason' => $e->getMessage()]); }
    exit;
}
if ($mode !== 'verify') { throw new RuntimeException('Unknown verification mode'); }
$invoice = App\Models\Invoice::findOrFail($fixture['invoice']);
$user->refresh();
$tasks = App\Models\FulfillmentTask::where('instance_id', $instance->id)->get();
$ledger = App\Models\FinancialTransaction::where('invoice_id', $invoice->id)->get();
$check(App\Models\Invoice::where('instance_id', $instance->id)->count() === 1, 'Duplicate renewal invoice');
if ($case === 'duplicate') {
    $check($invoice->status === App\Enums\InvoiceStatus::UNPAID && $invoice->credit_applied === '3.00' && $invoice->gateway_amount === '7.00' && $invoice->gateway === 'epay_alipay', 'Incorrect confirmation');
    $check($ledger->count() === 1 && $tasks->isEmpty() && $user->credit === '0.00' && $user->frozen_credit === '3.00', 'Duplicate freeze');
    $check(Illuminate\Support\Facades\DB::table('audit_logs')->where('action', 'invoice.automatic_renewal_confirm')->where('subject_id', $invoice->id)->count() === 1, 'Duplicate confirmation audit');
} elseif ($case === 'termination') {
    $check($invoice->status === App\Enums\InvoiceStatus::CANCELLED && $tasks->count() === 1 && $tasks->sole()->action === 'terminate', 'Termination did not cancel renewal');
    $check($user->credit === '3.00' && $user->frozen_credit === '0.00' && in_array($ledger->count(), [0, 2], true), 'Incorrect cancellation wallet');
} else {
    $check($tasks->count() === 1 && $user->frozen_credit === '0.00' && $ledger->count() === 3, 'Incorrect callback serialization');
    if ($invoice->status === App\Enums\InvoiceStatus::PAID) {
        $check($tasks->sole()->action === 'renew' && $user->credit === '0.00', 'Paid renewal lost funds');
    } else {
        $check($invoice->status === App\Enums\InvoiceStatus::CANCELLED && $tasks->sole()->action === 'terminate' && $user->credit === '10.00', 'Late payment reactivated cancelled service');
    }
}
echo json_encode(['case' => $case, 'invoice_status' => $invoice->status->value, 'wallet' => $user->credit,
    'frozen' => $user->frozen_credit, 'ledger' => $ledger->count(), 'tasks' => $tasks->count(), 'passed' => true]);
''')


def run_php(*args):
    return subprocess.run([php, str(probe), *args], cwd=repo, env=env, capture_output=True, text=True, timeout=30)


def wait_for(predicate, description, seconds=15):
    deadline = time.monotonic() + seconds
    while not predicate():
        if time.monotonic() > deadline:
            raise RuntimeError(description)
        time.sleep(0.1)


try:
    subprocess.run(['docker', 'run', '--detach', '--pull=never', '--name', container, '--network', 'none',
                    '--tmpfs', '/var/lib/mysql:rw,size=768m', '--mount', f'type=bind,source={socket_dir},target=/var/run/mysqld',
                    '--env', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes', '--env', 'MYSQL_DATABASE=review_renewal',
                    'mysql:8.0.35', '--performance-schema=OFF', '--innodb-buffer-pool-size=32M', '--mysqlx=OFF', '--skip-log-bin'],
                   capture_output=True, check=True, timeout=20)
    def ready():
        process = subprocess.run(['docker', 'exec', container, 'cat', '/proc/1/comm'], capture_output=True, text=True, timeout=5)
        return process.stdout.strip() == 'mysqld' and run_php('ready').returncode == 0
    wait_for(ready, 'Dedicated offline MySQL failed to start', seconds=90)
    suites = subprocess.run([php, 'vendor/bin/pest', 'tests/Feature/ReviewRenewalWorkflowTest.php', 'tests/Feature/RenewalRecoveryTest.php', '--compact', '--do-not-cache-result'],
                            cwd=repo, env=env, capture_output=True, text=True, timeout=180)
    (root / 'pest.log').write_text(suites.stdout + suites.stderr)
    if suites.returncode:
        raise RuntimeError('MySQL renewal regression failed: ' + suites.stdout[-5000:] + suites.stderr[-1000:])
    subprocess.run([php, 'artisan', 'migrate:fresh', '--force'], cwd=repo, env=env, capture_output=True, check=True, timeout=60)
    (storage / 'installed.lock').write_text('Isolated review verification only')
    results = []
    for case, actions in [('duplicate', ['confirm', 'confirm']), ('termination', ['confirm', 'terminate']), ('callback', ['terminate', 'callback'])]:
        seeded = run_php('seed', case)
        if seeded.returncode:
            raise RuntimeError(seeded.stdout + seeded.stderr)
        holder = subprocess.Popen([php, str(probe), 'hold', case], cwd=repo, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        workers = []
        try:
            wait_for(lambda: (root / f'{case}.held').exists(), 'User lock was not acquired')
            workers = [subprocess.Popen([php, str(probe), action, case], cwd=repo, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) for action in actions]
            wait_for(lambda: run_php('waiting').stdout.strip() == '2', 'Both workers must actually wait for the user row lock')
        finally:
            (root / f'{case}.release').touch()
        holder_output = holder.communicate(timeout=30)
        if holder.returncode:
            raise RuntimeError(str(holder_output))
        worker_results = []
        for worker in workers:
            output, errors = worker.communicate(timeout=30)
            if worker.returncode:
                raise RuntimeError(output + errors)
            worker_results.append(json.loads(output))
        verified = run_php('verify', case)
        if verified.returncode:
            raise RuntimeError(verified.stdout + verified.stderr)
        result = json.loads(verified.stdout)
        result['workers'] = worker_results
        results.append(result)
        print(json.dumps(result, ensure_ascii=False), flush=True)
    (root / 'results.json').write_text(json.dumps(results, indent=2, ensure_ascii=False))
    print('PASS: isolated MySQL renewal suites and three real row-lock races. Evidence: ' + str(root), flush=True)
finally:
    subprocess.run(['docker', 'rm', '--force', container], capture_output=True, timeout=20)
