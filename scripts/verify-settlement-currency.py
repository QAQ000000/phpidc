"""Verify C20 currency boundaries and simultaneous callbacks on disposable MySQL 8."""

import argparse
import base64
import os
import json
import pathlib
import subprocess
import tempfile
import time
import uuid


def run_callback_races(repo, root, env):
    (root / 'storage/installed.lock').write_text('isolated C20 currency races')
    env = dict(env, QUEUE_CONNECTION='database')
    worker = root / 'currency-race.php'
    worker.write_text(r'''<?php
require getenv('CURRENCY_TEST_REPO').'/vendor/autoload.php';
$app = require getenv('CURRENCY_TEST_REPO').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Http::preventStrayRequests();
use App\Actions\HandlePaymentCallbackAction;
use App\Drivers\Payment\EpayGateway;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Facades\DB;
if ($argv[1] === 'fixture') {
    $user = User::factory()->create(['credit'=>'17.00','frozen_credit'=>'4.00']);
    $invoice = Invoice::factory()->create(['user_id'=>$user->id,'status'=>InvoiceStatus::from($argv[3]),'currency'=>$argv[2],
        'total_amount'=>'10.00','credit_applied'=>'4.00','gateway_amount'=>'6.00','gateway'=>'epay_wechat']);
    $invoice->attempts()->create(['gateway'=>'epay_wechat','out_trade_no'=>'RACE-PAY-'.$invoice->id,'amount'=>'6.00','status'=>'created']);
    echo json_encode(['invoice_id'=>$invoice->id],JSON_THROW_ON_ERROR);
} elseif ($argv[1] === 'callback') {
    $invoice = Invoice::findOrFail($argv[2]);
    $payload = ['pid'=>'merchant','type'=>'wxpay','out_trade_no'=>'RACE-PAY-'.$invoice->id,
        'trade_no'=>$argv[4],'money'=>'6.00','trade_status'=>'TRADE_SUCCESS'];
    ksort($payload,SORT_STRING);$pairs=[];
    foreach ($payload as $key=>$value) { $pairs[]=$key.'='.$value; }
    $payload['sign']=md5(implode('&',$pairs).'currency-race-key');
    $gateway = new EpayGateway('epay_wechat','wxpay','https://unused.test','merchant','currency-race-key');
    $verified = $gateway->verifyCallback(Illuminate\Http\Request::create('/api/payments/epay_wechat/notify','POST',$payload));
    $barrier = getenv('CURRENCY_TEST_ROOT').'/'.$argv[3];
    file_put_contents($barrier.'.ready.'.getmypid(),'ready');
    $deadline=microtime(true)+15;
    while (!is_file($barrier.'.go')) {
        if (microtime(true)>$deadline) { throw new RuntimeException('Race barrier timed out.'); }
        usleep(10000);
    }
    $ack=app(HandlePaymentCallbackAction::class)->execute($verified);
    echo json_encode(['ack'=>$ack],JSON_THROW_ON_ERROR);
} elseif ($argv[1] === 'inspect') {
    $invoice=Invoice::findOrFail($argv[2]);$user=$invoice->user;
    echo json_encode(['credit'=>$user->credit,'frozen'=>$user->frozen_credit,'status'=>$invoice->status->value,
        'callbacks'=>DB::table('payment_callbacks')->where('invoice_id',$invoice->id)->pluck('outcome')->all(),
        'ledger'=>DB::table('financial_transactions')->where('invoice_id',$invoice->id)->count(),
        'instances'=>DB::table('instances')->where('user_id',$user->id)->count(),
        'tasks'=>DB::table('fulfillment_tasks')->where('invoice_id',$invoice->id)->count()],JSON_THROW_ON_ERROR);
} else { throw new RuntimeException('Unknown isolated test operation.'); }
''')

    def call(*args):
        result = subprocess.run(['php', str(worker), *map(str, args)], cwd=repo, env=env,
                                capture_output=True, text=True, check=True, timeout=20)
        return json.loads(result.stdout)

    scenarios = [(currency, status, distinct) for currency, statuses in
                 [('USD', ['Unpaid', 'Paid', 'Expired', 'Cancelled', 'Refunded']), ('CNY', ['Unpaid'])]
                 for status in statuses for distinct in [False, True]]
    for currency, status, distinct in scenarios:
        fixture = call('fixture', currency, status)
        invoice_id = fixture['invoice_id']
        barrier = 'race-' + uuid.uuid4().hex
        processes = [subprocess.Popen(['php', str(worker), 'callback', str(invoice_id), barrier,
                                      'TRADE-' + str(invoice_id) + ('-B' if distinct and i else '-A')],
                                     cwd=repo, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
                     for i in range(2)]
        try:
            deadline = time.monotonic() + 15
            while len(list(root.glob(barrier + '.ready.*'))) != 2:
                if time.monotonic() >= deadline or any(p.poll() is not None for p in processes):
                    raise RuntimeError('Callback workers did not reach the barrier.')
                time.sleep(0.01)
            (root / (barrier + '.go')).write_text('go')
            for process in processes:
                output, error = process.communicate(timeout=20)
                if process.returncode != 0:
                    raise RuntimeError('Isolated callback failed: ' + error + output)
                assert json.loads(output)['ack'] == 'success'
        finally:
            for process in processes:
                if process.poll() is None:
                    process.kill()
                    process.wait()
        result = call('inspect', invoice_id)
        assert len(result['callbacks']) == (2 if distinct else 1), result
        if currency == 'USD':
            assert result['credit'] == '17.00' and result['frozen'] == '4.00', result
            assert result['status'] == status and set(result['callbacks']) == {'rejected_unsupported_currency'}, result
            assert result['ledger'] == result['instances'] == result['tasks'] == 0, result
        else:
            assert result['status'] == 'Paid' and result['frozen'] == '0.00', result
            assert result['credit'] == ('23.00' if distinct else '17.00'), result
            assert result['instances'] == result['tasks'] == 1, result
            assert result['ledger'] == (3 if distinct else 2), result
        print('PASS race: ' + currency + '/' + status + ('/distinct trades' if distinct else '/duplicate trade'), flush=True)
    log = root / 'storage/logs/laravel.log'
    assert log.is_file() and 'rejected_unsupported_currency' in log.read_text(), 'Post-commit currency alert missing'


parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--image', default='mysql:8.0.35', help='Locally available MySQL 8 image; never pulled')
args = parser.parse_args()
repo = pathlib.Path(__file__).resolve().parents[1]
subprocess.run(['docker', 'image', 'inspect', args.image], capture_output=True, check=True, timeout=10)
container = 'laravel13idc-fix002-' + uuid.uuid4().hex[:12]

with tempfile.TemporaryDirectory(prefix='laravel13idc-fix002-mysql-') as temporary:
    root = pathlib.Path(temporary)
    socket_dir = root / 'socket'
    socket_dir.mkdir(mode=0o777)
    storage = root / 'storage'
    for folder in ['framework/views', 'framework/cache/data', 'logs', 'app/private']:
        (storage / folder).mkdir(parents=True)
    env = dict(os.environ, APP_ENV='testing', APP_DEBUG='false',
               APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(),
               APP_CONFIG_CACHE=str(root / 'config.php'), APP_ROUTES_CACHE=str(root / 'routes.php'),
               LARAVEL_STORAGE_PATH=str(storage), VIEW_COMPILED_PATH=str(storage / 'framework/views'),
               DB_CONNECTION='mysql', DB_URL='', DB_HOST='localhost', DB_PORT='3306',
               DB_SOCKET=str(socket_dir / 'mysqld.sock'), DB_DATABASE='currency_isolated',
               DB_USERNAME='root', DB_PASSWORD='', CACHE_STORE='array', QUEUE_CONNECTION='sync', SESSION_DRIVER='array', LOG_CHANNEL='single', BCRYPT_ROUNDS='4',
               CURRENCY_TEST_REPO=str(repo), CURRENCY_TEST_ROOT=str(root))
    probe = root / 'probe.php'
    probe.write_text("""<?php
$pdo = new PDO('mysql:unix_socket='.getenv('DB_SOCKET').';dbname=currency_isolated', 'root', '');
$version = $pdo->query('SELECT VERSION()')->fetchColumn();
if (!str_starts_with($version, '8.')) { throw new RuntimeException('MySQL 8 is required.'); }
echo $version;
""")
    try:
        subprocess.run(['docker', 'run', '--detach', '--pull=never', '--name', container,
                        '--label', 'laravel13idc.remediation=FIX-002', '--network', 'none',
                        '--tmpfs', '/var/lib/mysql:rw,size=768m',
                        '--mount', 'type=bind,source=' + str(socket_dir) + ',target=/var/run/mysqld',
                        '--env', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes', '--env', 'MYSQL_DATABASE=currency_isolated',
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
        subprocess.run(['vendor/bin/pest', 'tests/Feature/SettlementCurrencyTest.php', '--compact'],
                       cwd=repo, env=env, check=True, timeout=120)
        run_callback_races(repo, root, env)
        print('PASS: isolated MySQL 8 CNY boundaries and concurrent callback deduplication; business databases untouched.', flush=True)
    finally:
        subprocess.run(['docker', 'rm', '--force', container], capture_output=True, timeout=20)
