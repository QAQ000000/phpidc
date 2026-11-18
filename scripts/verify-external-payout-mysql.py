"""D03: exercise real concurrent financial Actions in a disposable offline MySQL 8."""

import base64
import json
import os
import pathlib
import subprocess
import tempfile
import time
import uuid

repo = pathlib.Path(__file__).resolve().parents[1]
php = '/www/server/php/84/bin/php'
image = 'mysql:8.0.35'
subprocess.run(['docker', 'image', 'inspect', image], check=True, capture_output=True, timeout=10)
root = pathlib.Path(tempfile.mkdtemp(prefix='laravel13idc-external-payout-mysql8-'))
socket_dir = root / 'socket'
socket_dir.mkdir(mode=0o777)
socket_dir.chmod(0o777)
storage = root / 'storage'
for folder in ['framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private']:
    (storage / folder).mkdir(parents=True)
env = dict(os.environ, APP_ENV='testing', APP_DEBUG='false', APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(),
           APP_CONFIG_CACHE=str(root / 'config.php'), APP_ROUTES_CACHE=str(root / 'routes.php'),
           LARAVEL_STORAGE_PATH=str(storage), VIEW_COMPILED_PATH=str(storage / 'framework/views'),
           DB_CONNECTION='mysql', DB_URL='', DB_HOST='localhost', DB_PORT='3306', DB_SOCKET=str(socket_dir / 'mysqld.sock'),
           DB_DATABASE='external_payout_review_only', DB_USERNAME='root', DB_PASSWORD='', CACHE_STORE='array',
           SESSION_DRIVER='array', QUEUE_CONNECTION='database', LOG_CHANNEL='single', BCRYPT_ROUNDS='4',
           PAYOUT_REPO=str(repo), PAYOUT_ROOT=str(root))
container = 'laravel13idc-external-payout-' + uuid.uuid4().hex[:12]
print('Evidence directory: ' + str(root), flush=True)
(root / 'bootstrap.php').write_text(r'''<?php
declare(strict_types=1);
require getenv('PAYOUT_REPO').'/vendor/autoload.php';
$app = require getenv('PAYOUT_REPO').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'external_payout_review_only'
    || config('database.connections.mysql.unix_socket') !== getenv('PAYOUT_ROOT').'/socket/mysqld.sock') {
    throw new RuntimeException('Only the private offline payout database is authorized.');
}
function payoutRequire(bool $condition, string $message): void {
    if (! $condition) { throw new RuntimeException($message); }
}
function payoutInput(string $request, string $amount = '10.00'): array {
    return ['request_id' => $request, 'amount' => $amount, 'payout_method' => 'alipay', 'payee_name' => 'Isolated Test', 'payee_account' => 'SIMULATED-ONLY'];
}
function payoutOperation(string $operation, array $extra = []): array {
    return array_replace(['request_id' => (string) Illuminate\Support\Str::ulid(), 'operation' => $operation, 'reason' => 'Isolated concurrency verification', 'confirm' => '1'], $extra);
}
''')
(root / 'probe.php').write_text(r'''<?php
$pdo = new PDO('mysql:unix_socket='.getenv('DB_SOCKET').';dbname=external_payout_review_only', 'root', '');
echo $pdo->query('SELECT VERSION()')->fetchColumn();
''')
(root / 'setup.php').write_text(r'''<?php
require __DIR__.'/bootstrap.php';
use App\Actions\AssignAdminRoleAction;
use App\Actions\ProcessExternalAffiliateWithdrawalAction;
use App\Actions\RequestExternalAffiliateWithdrawalAction;
use App\Models\AffiliateCommission;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Str;
$actor = User::factory()->create();
$otherActor = User::factory()->create();
foreach (['external-payout-reviewer','external-payout-operator','external-payout-confirmer','finance-operator'] as $role) {
    app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated financial concurrency setup');
    app(AssignAdminRoleAction::class)->execute($otherActor, $role, 'Independent financial concurrency setup');
}
$fixtures = [];
foreach (['same_request','capacity','approval','settle_release','receipt','refund'] as $kind) {
    $owner = User::factory()->create(['credit' => '7.00', 'affiliate_balance' => '10.00']);
    $fixture = ['owner' => $owner->id, 'actor' => $actor->id, 'other_actor' => $otherActor->id, 'request' => (string) Str::ulid()];
    if ($kind === 'approval') {
        $one = app(RequestExternalAffiliateWithdrawalAction::class)->execute($owner, payoutInput((string) Str::ulid()));
        // A second historical pending request may overbook after subsequent refunds; approval must still serialize.
        $two = App\Models\AffiliateWithdrawal::create(['user_id' => $owner->id, 'request_key' => $owner->id.':'.Str::ulid(),
            'destination' => 'external', 'status' => 'pending', 'amount' => '10.00', 'payout_method' => 'alipay', 'encrypted_payout_details' => $one->encrypted_payout_details]);
        $fixture += ['one' => $one->id, 'two' => $two->id];
    }
    if (in_array($kind, ['settle_release', 'receipt', 'refund'], true)) {
        $withdrawal = app(RequestExternalAffiliateWithdrawalAction::class)->execute($owner, payoutInput((string) Str::ulid()));
        $fixture['one'] = $withdrawal->id;
        if ($kind !== 'refund') {
            app(ProcessExternalAffiliateWithdrawalAction::class)->execute($withdrawal, $actor, payoutOperation('approve'));
            $started = app(ProcessExternalAffiliateWithdrawalAction::class)->execute($withdrawal, $actor, payoutOperation('start'));
            $fixture['intent'] = $started->payout_intent_key;
        }
        if ($kind === 'receipt') {
            $other = User::factory()->create(['credit' => '7.00', 'affiliate_balance' => '10.00']);
            $otherWithdrawal = app(RequestExternalAffiliateWithdrawalAction::class)->execute($other, payoutInput((string) Str::ulid()));
            app(ProcessExternalAffiliateWithdrawalAction::class)->execute($otherWithdrawal, $actor, payoutOperation('approve'));
            $started = app(ProcessExternalAffiliateWithdrawalAction::class)->execute($otherWithdrawal, $actor, payoutOperation('start'));
            $fixture += ['other_owner' => $other->id, 'two' => $otherWithdrawal->id, 'other_intent' => $started->payout_intent_key];
        }
        if ($kind === 'refund') {
            $invoice = Invoice::factory()->paid()->create(['total_amount' => '100.00', 'gateway_amount' => '100.00']);
            AffiliateCommission::factory()->create(['referrer_id' => $owner->id, 'buyer_id' => $invoice->user_id,
                'invoice_id' => $invoice->id, 'amount' => '10.00', 'status' => 'available']);
            $fixture['invoice'] = $invoice->id;
        }
    }
    $fixtures[$kind] = $fixture;
}
file_put_contents(__DIR__.'/fixtures.json', json_encode($fixtures, JSON_THROW_ON_ERROR));
echo 'Financial race fixtures ready'.PHP_EOL;
''')
(root / 'worker.php').write_text(r'''<?php
require __DIR__.'/bootstrap.php';
use App\Actions\ProcessExternalAffiliateWithdrawalAction;
use App\Actions\RefundInvoiceAction;
use App\Actions\RequestExternalAffiliateWithdrawalAction;
use App\Models\AffiliateWithdrawal;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
[$script, $kind, $slot] = $argv;
$fixture = json_decode(file_get_contents(__DIR__.'/fixtures.json'), true, flags: JSON_THROW_ON_ERROR)[$kind];
file_put_contents(__DIR__.'/'.$kind.'-'.$slot.'.ready', 'ready');
$deadline = microtime(true) + 20;
while (! file_exists(__DIR__.'/'.$kind.'.start')) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Payout race barrier timed out'); }
    usleep(10000);
}
try {
    $owner = User::findOrFail($fixture['owner']);
    $actor = User::findOrFail($slot === 'b' ? $fixture['other_actor'] : $fixture['actor']);
    if (in_array($kind, ['same_request','capacity'], true)) {
        $request = $kind === 'same_request' ? $fixture['request'] : (string) Str::ulid();
        $record = app(RequestExternalAffiliateWithdrawalAction::class)->execute($owner, payoutInput($request));
    } elseif ($kind === 'refund' && $slot === 'b') {
        $record = app(RefundInvoiceAction::class)->execute(Invoice::findOrFail($fixture['invoice']), 'external_record', true, 'Simulated already completed refund', $actor);
    } else {
        $id = in_array($kind, ['approval','receipt'], true) && $slot === 'b' ? $fixture['two'] : $fixture['one'];
        $operation = in_array($kind, ['approval','refund'], true) ? 'approve' : ($kind === 'settle_release' && $slot === 'b' ? 'confirm_not_paid' : 'paid');
        $extra = ['intent_key' => $kind === 'receipt' && $slot === 'b' ? $fixture['other_intent'] : ($fixture['intent'] ?? null),
            'evidence' => 'Isolated evidence, no transfer was sent', 'currency' => 'CNY', 'confirmed_amount' => '10.00', 'external_reference' => 'receipt-'.$kind];
        $record = app(ProcessExternalAffiliateWithdrawalAction::class)->execute(AffiliateWithdrawal::findOrFail($id), $actor, payoutOperation($operation, $extra));
    }
    echo json_encode(['outcome' => 'accepted', 'id' => $record->id], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (ValidationException $error) {
    echo json_encode(['outcome' => 'rejected', 'fields' => array_keys($error->errors())], JSON_THROW_ON_ERROR).PHP_EOL;
}
''')
(root / 'verify.php').write_text(r'''<?php
require __DIR__.'/bootstrap.php';
use App\Enums\TransactionType;
use App\Models\AffiliateWithdrawal;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\AffiliateFunds;
use App\Support\Money;
$fixtures = json_decode(file_get_contents(__DIR__.'/fixtures.json'), true, flags: JSON_THROW_ON_ERROR);
$report = [];
foreach ($fixtures as $kind => $fixture) {
    $owner = User::findOrFail($fixture['owner']);
    payoutRequire($owner->credit === '7.00', $kind.' changed ordinary wallet');
    $records = AffiliateWithdrawal::where('user_id', $owner->id)->get();
    if (in_array($kind, ['same_request', 'capacity'], true)) {
        payoutRequire($records->count() === 1 && app(AffiliateFunds::class)->pending($owner->id) === '10.00', $kind.' over-reserved pending funds');
    } elseif ($kind === 'approval') {
        payoutRequire($records->where('status', 'reserved')->count() === 1 && $owner->affiliate_balance === '0.00' && $owner->affiliate_frozen_balance === '10.00', 'Competing approvals did not serialize');
    } elseif ($kind === 'settle_release') {
        $withdrawal = $records->sole();
        $entries = FinancialTransaction::whereIn('reference_key', ['payout:'.$withdrawal->id, 'external_payout_release:'.$withdrawal->id])->get();
        payoutRequire($entries->count() === 1 && $owner->affiliate_frozen_balance === '0.00', 'Settlement and release both consumed hold');
        payoutRequire(($withdrawal->status === 'paid' && $owner->affiliate_balance === '0.00') || ($withdrawal->status === 'failed' && $owner->affiliate_balance === '10.00'), 'Terminal payout money mismatch');
    } elseif ($kind === 'receipt') {
        $both = AffiliateWithdrawal::whereIn('id', [$fixture['one'], $fixture['two']])->get();
        $other = User::findOrFail($fixture['other_owner']);
        payoutRequire($both->where('status', 'paid')->count() === 1 && $both->where('status', 'processing')->count() === 1, 'Receipt paid more than one withdrawal');
        payoutRequire(Money::add($owner->affiliate_frozen_balance, $other->affiliate_frozen_balance) === '10.00', 'Receipt loser did not retain original hold');
    } elseif ($kind === 'refund') {
        $withdrawal = $records->sole();
        $debt = app(AffiliateFunds::class)->hasReceivable($owner->id);
        payoutRequire(($withdrawal->status === 'reserved' && $owner->affiliate_frozen_balance === '10.00' && $debt)
            || ($withdrawal->status === 'pending' && $owner->affiliate_frozen_balance === '0.00' && ! $debt), 'Refund consumed held funds or approval ignored clawback');
        payoutRequire($owner->affiliate_balance === '0.00', 'Refund race left a duplicate available balance');
    }
    $report[$kind] = ['statuses' => $records->pluck('status')->all(), 'affiliate_balance' => $owner->affiliate_balance,
        'affiliate_frozen_balance' => $owner->affiliate_frozen_balance, 'ordinary_wallet' => $owner->credit];
}
file_put_contents(__DIR__.'/verified.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
''')


def run_logged(command, name, timeout=180):
    result = subprocess.run(command, env=env, cwd=repo, capture_output=True, text=True, timeout=timeout)
    (root / name).write_text(result.stdout + result.stderr)
    print(result.stdout + result.stderr, end='', flush=True)
    if result.returncode:
        raise RuntimeError('Verification failed; see ' + str(root / name))
    return result.stdout


workers = []
try:
    subprocess.run(['docker', 'run', '--detach', '--pull=never', '--name', container,
                    '--label', 'laravel13idc.review=external-payout', '--network', 'none',
                    '--tmpfs', '/var/lib/mysql:rw,size=768m', '--mount', 'type=bind,source=' + str(socket_dir) + ',target=/var/run/mysqld',
                    '--env', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes', '--env', 'MYSQL_DATABASE=external_payout_review_only',
                    image, '--performance-schema=OFF', '--innodb-buffer-pool-size=32M', '--mysqlx=OFF', '--skip-log-bin'],
                   capture_output=True, check=True, timeout=20)
    deadline = time.monotonic() + 90
    while True:
        ready = subprocess.run([php, str(root / 'probe.php')], env=env, capture_output=True, text=True, timeout=5)
        stable = subprocess.run(['docker', 'exec', container, 'cat', '/proc/1/comm'], capture_output=True, text=True, timeout=5)
        if ready.returncode == 0 and stable.stdout.strip() == 'mysqld':
            break
        if time.monotonic() >= deadline:
            raise RuntimeError('Private MySQL 8 did not become ready')
        time.sleep(0.5)
    print('Dedicated offline MySQL ' + ready.stdout.strip(), flush=True)
    run_logged([php, 'vendor/bin/pest', '--compact', '--colors=never', 'tests/Feature/ExternalAffiliatePayoutTest.php'], 'pest.log')
    run_logged([php, 'artisan', 'migrate', '--force', '--no-interaction'], 'migrate.log')
    run_logged([php, str(root / 'setup.php')], 'setup.log')
    concurrency = {}
    for kind in ['same_request', 'capacity', 'approval', 'settle_release', 'receipt', 'refund']:
        batch = {slot: subprocess.Popen([php, str(root / 'worker.php'), kind, slot], cwd=repo, env=env,
                                         stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) for slot in ['a', 'b']}
        workers.extend(batch.values())
        deadline = time.monotonic() + 15
        while not all((root / (kind + '-' + slot + '.ready')).exists() for slot in batch):
            if time.monotonic() >= deadline or any(worker.poll() is not None for worker in batch.values()):
                raise RuntimeError('Concurrent worker failed to reach barrier: ' + kind)
            time.sleep(0.01)
        (root / (kind + '.start')).write_text('start')
        results = []
        for slot, worker in batch.items():
            output, error = worker.communicate(timeout=30)
            (root / (kind + '-' + slot + '.log')).write_text(output + error)
            if worker.returncode:
                raise RuntimeError('Payout worker failed; see ' + str(root / (kind + '-' + slot + '.log')))
            try:
                results.append(json.loads(output))
            except json.JSONDecodeError as error:
                raise RuntimeError('Worker did not return its outcome; inspect ' + str(root / (kind + '-' + slot + '.log'))) from error
        expected = ['accepted', 'accepted'] if kind == 'same_request' else ['accepted', 'rejected']
        if kind == 'refund':
            if not any(row['outcome'] == 'accepted' for row in results):
                raise RuntimeError('Refund must complete regardless of approval winner')
        elif sorted(row['outcome'] for row in results) != expected:
            raise RuntimeError('Unexpected race outcomes: ' + kind + ' ' + repr(results))
        concurrency[kind] = results
    (root / 'concurrency.json').write_text(json.dumps(concurrency, indent=2))
    run_logged([php, str(root / 'verify.php')], 'verify.log')
    print('PASS: six multi-process financial races and MySQL external payout regressions.', flush=True)
finally:
    for worker in workers:
        if worker.poll() is None:
            worker.kill()
            worker.wait(timeout=5)
    logs = subprocess.run(['docker', 'logs', container], capture_output=True, text=True, timeout=10)
    (root / 'mysql-container.log').write_text(logs.stdout + logs.stderr)
    subprocess.run(['docker', 'rm', '--force', container], capture_output=True, timeout=20)
    print('Dedicated container removed; evidence: ' + str(root), flush=True)
