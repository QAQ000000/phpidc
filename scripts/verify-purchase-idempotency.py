"""Verify C22 purchase idempotency on a disposable, offline MySQL 8 database."""

import argparse
import base64
import json
import os
import pathlib
import subprocess
import tempfile
import time
import uuid


def run_purchase_races(repo, root, env):
    (root / 'storage/installed.lock').write_text('isolated C22 purchase races')
    env = dict(env, QUEUE_CONNECTION='database')
    worker = root / 'purchase-race.php'
    worker.write_text(r'''<?php
require getenv('PURCHASE_TEST_REPO').'/vendor/autoload.php';
$app = require getenv('PURCHASE_TEST_REPO').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Actions\ClaimCouponAction;
use App\Actions\CreateComboInvoiceAction;
use App\Contracts\FulfillmentDriver;
use App\Contracts\LiveQuotable;
use App\Data\QuoteResult;
use App\Enums\BillingCycle;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Services\DriverManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
Http::preventStrayRequests();
$root = getenv('PURCHASE_TEST_ROOT');
function awaitFile(string $file): void {
    $deadline=microtime(true)+20;
    while (!is_file($file)) {
        if (microtime(true)>$deadline) { throw new RuntimeException('Purchase process barrier timed out.'); }
        usleep(10000);
    }
}
$data = json_decode($argv[2] ?? '{}',true,flags:JSON_THROW_ON_ERROR);
if ($argv[1]==='fixture') {
    $user=User::factory()->create(['credit'=>$data['credit']]);
    $product=Product::factory()->create(['local_prices'=>['onetime'=>['price'=>$data['price'],'enabled'=>true]]]);
    $coupon=Coupon::create(['code'=>'RACE'.Str::ulid(),'type'=>'fixed','value'=>'1.00','min_order_amount'=>'0.00',
        'applies_to'=>'new_order','total_quota'=>$data['quota'],'per_user_limit'=>$data['quota'],'is_active'=>true]);
    app(ClaimCouponAction::class)->execute($user,$coupon->code);
    echo json_encode(['user'=>$user->id,'product'=>$product->id,'coupon'=>$coupon->code,'request_id'=>(string)Str::ulid()],JSON_THROW_ON_ERROR);
} elseif ($argv[1]==='inspect') {
    $user=User::findOrFail($data['user']);
    $invoices=Invoice::where('user_id',$user->id)->orderBy('id')->get();
    echo json_encode(['credit'=>$user->credit,'frozen'=>$user->frozen_credit,
        'invoices'=>$invoices->map(fn($i)=>['id'=>$i->id,'total'=>$i->total_amount,'credit'=>$i->credit_applied,'gateway'=>$i->gateway_amount,'status'=>$i->status->value])->all(),
        'ledger'=>DB::table('financial_transactions')->where('user_id',$user->id)->orderBy('id')->get(['reference_key','amount','type'])->all(),
        'tasks'=>DB::table('fulfillment_tasks')->where('user_id',$user->id)->count(),
        'instances'=>DB::table('instances')->where('user_id',$user->id)->count(),
        'uses'=>DB::table('coupon_usages')->where('user_id',$user->id)->count(),
        'coupon_count'=>Coupon::where('code',$data['coupon'])->value('used_count'),
        'attempts'=>DB::table('payment_attempts')->whereIn('invoice_id',$invoices->modelKeys())->count()],JSON_THROW_ON_ERROR);
} elseif ($argv[1]==='run') {
    $mode=$argv[3]; $barrier=$argv[4];
    if ($barrier!=='-') {
        file_put_contents($root.'/'.$barrier.'.ready.'.getmypid(),'ready');
        awaitFile($root.'/'.$barrier.'.go');
    }
    $user=User::findOrFail($data['user']); $product=Product::findOrFail($data['product']);
    if ($mode==='quote') {
        $driver=Mockery::mock(FulfillmentDriver::class.', '.LiveQuotable::class);
        $driver->shouldReceive('validateParams')->once()->andReturn([]);
        $driver->shouldReceive('quotePurchase')->once()->andReturnUsing(function() use($root,$data): QuoteResult {
            if (DB::transactionLevel()!==0) { throw new LogicException('Quotation held a database lock.'); }
            file_put_contents($root.'/quote-'.$data['user'],'waiting');
            awaitFile($root.'/return-'.$data['user']);
            return new QuoteResult('0.00','99.00');
        });
        $manager=Mockery::mock(DriverManager::class);
        $manager->shouldReceive('fulfillment')->andReturn($driver);
        app()->instance(DriverManager::class,$manager);
    }
    try {
        $invoice=app(CreateComboInvoiceAction::class)->execute($user,$product,BillingCycle::ONETIME,[],$data['wallet'],'epay_wechat',
            ['request_id'=>$data['request_id'],'coupon_code'=>$data['coupon'],'hostname'=>$data['hostname'] ?? null]);
        echo json_encode(['id'=>$invoice->id,'total'=>$invoice->total_amount],JSON_THROW_ON_ERROR);
    } catch (ValidationException $exception) {
        // A conflict is an asserted test result, never converted into purchase success.
        echo json_encode(['validation'=>$exception->errors()],JSON_THROW_ON_ERROR);
    }
} elseif ($argv[1]==='migration') {
    // Only the disposable database: prove populated legacy rows survive down/up.
    $legacy=Invoice::factory()->count(2)->create(['purchase_request_id'=>null]);
    $before=DB::table('invoices')->whereIn('id',$legacy->modelKeys())->orderBy('id')->get()->toJson();
    $migration=require getenv('PURCHASE_TEST_REPO').'/database/migrations/2026_11_18_000001_add_purchase_request_id_to_invoices.php';
    $migration->down(); $migration->up();
    $after=DB::table('invoices')->whereIn('id',$legacy->modelKeys())->orderBy('id')->get()->toJson();
    if ($before!==$after) { throw new RuntimeException('Migration changed legacy invoice values.'); }
    echo json_encode(['preserved'=>2],JSON_THROW_ON_ERROR);
} else { throw new RuntimeException('Unknown purchase test operation.'); }
''')

    def call(op, data):
        result = subprocess.run(['php', str(worker), op, json.dumps(data)], cwd=repo, env=env,
                                capture_output=True, text=True, check=True, timeout=30)
        return json.loads(result.stdout)

    def start(data, mode='local', barrier='-'):
        return subprocess.Popen(['php', str(worker), 'run', json.dumps(data), mode, barrier],
                                cwd=repo, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)

    def finish(process):
        stdout, stderr = process.communicate(timeout=30)
        assert process.returncode == 0, (process.returncode, stderr)
        return json.loads(stdout)

    def wait_for(predicate, processes):
        deadline = time.monotonic() + 20
        while not predicate():
            if time.monotonic() >= deadline or any(p.poll() is not None for p in processes):
                raise RuntimeError('Purchase workers failed to reach their barrier.')
            time.sleep(0.01)

    def race(first, second, label):
        processes = [start(first, barrier=label), start(second, barrier=label)]
        wait_for(lambda: len(list(root.glob(label + '.ready.*'))) == 2, processes)
        (root / (label + '.go')).write_text('go')
        return [finish(p) for p in processes]

    def fixture(credit='100.00', price='10.00', wallet=True, quota=1):
        return dict(call('fixture', dict(credit=credit, price=price, quota=quota)), wallet=wallet)

    cases = [('wallet', '100.00', '10.00', True, '91.00', '0.00', '0.00', 1, 2),
             ('combo', '3.00', '10.00', True, '0.00', '3.00', '6.00', 0, 1),
             ('gateway', '100.00', '10.00', False, '100.00', '0.00', '9.00', 0, 0),
             ('free', '0.00', '1.00', True, '0.00', '0.00', '0.00', 1, 1)]
    for label, credit, price, wallet, balance, frozen, gateway, tasks, ledger in cases:
        data = fixture(credit, price, wallet)
        results = race(data, data, label)
        assert results[0]['id'] == results[1]['id'], results
        state = call('inspect', data)
        assert state['credit'] == balance and state['frozen'] == frozen, state
        assert len(state['invoices']) == 1 and state['invoices'][0]['gateway'] == gateway, state
        assert state['tasks'] == tasks and state['instances'] == tasks and len(state['ledger']) == ledger, state
        assert state['uses'] == 1 and state['coupon_count'] == 1 and state['attempts'] == 0, state
        # Response lost after commit: a third independent process must return the winner.
        assert finish(start(data))['id'] == results[0]['id'] and call('inspect', data) == state
        print('PASS: MySQL concurrent ' + label + ' checkout plus lost-response replay.', flush=True)

    data = fixture()
    results = race(dict(data, hostname='node-a'), dict(data, hostname='node-b'), 'conflict')
    assert sum('id' in result for result in results) == 1, results
    assert sum('request_id' in result.get('validation', {}) for result in results) == 1, results
    state = call('inspect', data)
    assert state['credit'] == '91.00' and len(state['invoices']) == 1 and state['uses'] == 1 and state['tasks'] == 1, state
    print('PASS: MySQL same key/different intent rejects exactly one caller before duplicate money or coupon writes.', flush=True)

    data = fixture(quota=2)
    other = dict(data, request_id='01KAD0000000000000000000XY')
    results = race(data, other, 'new-intent')
    assert all('id' in result for result in results), results
    assert results[0]['id'] != results[1]['id'], results
    state = call('inspect', data)
    assert state['credit'] == '82.00' and len(state['invoices']) == 2 and state['uses'] == 2 and state['tasks'] == 2, state
    print('PASS: MySQL different purchase keys remain two legitimate purchases.', flush=True)

    data = fixture()
    waiting = start(data, mode='quote')
    wait_for(lambda: (root / ('quote-' + str(data['user']))).exists(), [waiting])
    winner = finish(start(data))
    (root / ('return-' + str(data['user']))).write_text('return')
    loser = finish(waiting)
    assert loser == winner and winner['total'] == '9.00', (winner, loser)
    state = call('inspect', data)
    assert state['credit'] == '91.00' and len(state['invoices']) == 1 and state['uses'] == 1 and state['tasks'] == 1, state
    print('PASS: MySQL quote-in-flight loser returns the committed winner without applying its changed quote.', flush=True)
    assert call('migration', {}) == {'preserved': 2}
    print('PASS: MySQL migration down/up preserves populated nullable legacy invoices.', flush=True)


parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--image', default='mysql:8.0.35', help='Locally available MySQL 8 image; never pulled')
args = parser.parse_args()
repo = pathlib.Path(__file__).resolve().parents[1]
subprocess.run(['docker', 'image', 'inspect', args.image], capture_output=True, check=True, timeout=10)
container = 'laravel13idc-fix004-' + uuid.uuid4().hex[:12]

with tempfile.TemporaryDirectory(prefix='laravel13idc-fix004-mysql-') as temporary:
    root = pathlib.Path(temporary)
    socket_dir = root / 'socket'
    socket_dir.mkdir(mode=0o777)
    storage = root / 'storage'
    for folder in ['framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private']:
        (storage / folder).mkdir(parents=True)
    env = dict(os.environ, APP_ENV='testing', APP_DEBUG='false',
               APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(),
               APP_CONFIG_CACHE=str(root / 'config.php'), APP_ROUTES_CACHE=str(root / 'routes.php'),
               LARAVEL_STORAGE_PATH=str(storage), VIEW_COMPILED_PATH=str(storage / 'framework/views'),
               DB_CONNECTION='mysql', DB_URL='', DB_HOST='localhost', DB_PORT='3306',
               DB_SOCKET=str(socket_dir / 'mysqld.sock'), DB_DATABASE='purchase_isolated',
               DB_USERNAME='root', DB_PASSWORD='', CACHE_STORE='array', QUEUE_CONNECTION='sync', SESSION_DRIVER='array', LOG_CHANNEL='single', BCRYPT_ROUNDS='4',
               PURCHASE_TEST_REPO=str(repo), PURCHASE_TEST_ROOT=str(root))
    probe = root / 'probe.php'
    probe.write_text("""<?php
$pdo = new PDO('mysql:unix_socket='.getenv('DB_SOCKET').';dbname=purchase_isolated', 'root', '');
$version = $pdo->query('SELECT VERSION()')->fetchColumn();
if (!str_starts_with($version, '8.')) { throw new RuntimeException('MySQL 8 is required.'); }
echo $version;
""")
    try:
        subprocess.run(['docker', 'run', '--detach', '--pull=never', '--name', container,
                        '--label', 'laravel13idc.remediation=FIX-004', '--network', 'none',
                        '--tmpfs', '/var/lib/mysql:rw,size=768m',
                        '--mount', 'type=bind,source=' + str(socket_dir) + ',target=/var/run/mysqld',
                        '--env', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes', '--env', 'MYSQL_DATABASE=purchase_isolated',
                        args.image, '--performance-schema=OFF', '--innodb-buffer-pool-size=32M',
                        '--mysqlx=OFF', '--skip-log-bin'], capture_output=True, check=True, timeout=20)
        print('Started dedicated MySQL container with no network and a private test socket.', flush=True)
        deadline = time.monotonic() + 90
        while True:
            ready = subprocess.run(['php', str(probe)], env=env, cwd=repo, capture_output=True, text=True, timeout=5)
            # The image initializes with a temporary server before starting its final process.
            stable = subprocess.run(['docker', 'exec', container, 'cat', '/proc/1/comm'],
                                    capture_output=True, text=True, timeout=5)
            if ready.returncode == 0 and stable.stdout.strip() == 'mysqld':
                break
            if time.monotonic() >= deadline:
                raise RuntimeError('Disposable MySQL did not become ready; no business database was used.')
            time.sleep(0.5)
        print('Connected to disposable MySQL ' + ready.stdout.strip(), flush=True)
        subprocess.run(['vendor/bin/pest', 'tests/Feature/PurchaseIdempotencyTest.php', 'tests/Feature/ComboInvoiceTest.php', 'tests/Feature/CheckoutValidationTest.php', 'tests/Feature/SecretRotationTest.php', '--compact'],
                       cwd=repo, env=env, check=True, timeout=180)
        run_purchase_races(repo, root, env)
        print('PASS: isolated MySQL 8 purchase requests, unique constraint, concurrent wallet/coupon/outbox and quote races; business databases untouched.', flush=True)
    finally:
        subprocess.run(['docker', 'rm', '--force', container], capture_output=True, timeout=20)
