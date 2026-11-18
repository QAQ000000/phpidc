"""Verify wallet receipts with real processes in an offline disposable MySQL 8 container."""

import base64
import json
import os
import pathlib
import subprocess
import tempfile
import time
import uuid

repo = pathlib.Path(__file__).resolve().parents[1]
image = 'mysql:8.0.35'
subprocess.run(['docker', 'image', 'inspect', image], check=True, capture_output=True, timeout=10)
root = pathlib.Path(tempfile.mkdtemp(prefix='laravel13idc-wallet-mysql8-'))
socket_dir = root / 'socket'
socket_dir.mkdir(mode=0o777)
socket_dir.chmod(0o777)
storage = root / 'storage'
for folder in ['framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private']:
    (storage / folder).mkdir(parents=True)
(storage / 'installed.lock').write_text('offline disposable wallet acceptance')
env = dict(os.environ, APP_ENV='testing', APP_DEBUG='false',
           APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(),
           APP_CONFIG_CACHE=str(root / 'config.php'), APP_ROUTES_CACHE=str(root / 'routes.php'),
           LARAVEL_STORAGE_PATH=str(storage), VIEW_COMPILED_PATH=str(storage / 'framework/views'),
           DB_CONNECTION='mysql', DB_URL='', DB_HOST='localhost', DB_PORT='3306',
           DB_SOCKET=str(socket_dir / 'mysqld.sock'), DB_DATABASE='wallet_topup_only',
           DB_USERNAME='root', DB_PASSWORD='', CACHE_STORE='array', SESSION_DRIVER='array', QUEUE_CONNECTION='database',
           LOG_CHANNEL='single', BCRYPT_ROUNDS='4', WALLET_TEST_REPO=str(repo), WALLET_TEST_ROOT=str(root))
container = 'laravel13idc-wallet-' + uuid.uuid4().hex[:12]
print('Evidence directory: ' + str(root), flush=True)
(root / 'test-env.json').write_text(json.dumps(env))
(root / 'bootstrap.php').write_text(r'''<?php
declare(strict_types=1);
require getenv('WALLET_TEST_REPO').'/vendor/autoload.php';
$app=require getenv('WALLET_TEST_REPO').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config('database.default')!=='mysql'||config('database.connections.mysql.database')!=='wallet_topup_only'
    ||config('database.connections.mysql.unix_socket')!==getenv('WALLET_TEST_ROOT').'/socket/mysqld.sock') {
    throw new RuntimeException('Only the private offline wallet database is authorized.');
}
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Queue::fake();
''')
(root / 'worker.php').write_text(r'''<?php
require __DIR__.'/bootstrap.php';
use App\Actions\CreateWalletTopupInvoiceAction;
use App\Actions\HandlePaymentCallbackAction;
use App\Actions\CreateComboInvoiceAction;
use App\Actions\ExpireUnpaidInvoicesAction;
use App\Actions\ConfigureWalletTopupAction;
use App\Models\User;
use App\Models\Invoice;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
$op=$argv[1];
$data=json_decode($argv[3]??'{}',true,flags:JSON_THROW_ON_ERROR);
if(($argv[2]??'')!=='') {
    $barrier=__DIR__.'/'.$argv[2];
    file_put_contents($barrier.'.'.getmypid().'.ready','ready');
    $deadline=microtime(true)+20;
    while(!is_file($barrier.'.go')) {
        if(microtime(true)>$deadline)throw new RuntimeException('Wallet race barrier timed out');
        usleep(10000);
    }
}
if($op==='setup') {
    foreach(['topup_enabled'=>'1'] as $key=>$value) DB::table('system_settings')->insert(['group'=>'wallet','key'=>$key,'value'=>$value,'is_encrypted'=>false]);
    foreach(['epay_api_url'=>'https://pay.example.test','epay_pid'=>'wallet-test','epay_key'=>'wallet-test-only-key'] as $key=>$value) {
        DB::table('system_settings')->insert(['group'=>'payment','key'=>$key,'value'=>$key==='epay_key'?Illuminate\Support\Facades\Crypt::encryptString($value):$value,'is_encrypted'=>$key==='epay_key']);
    }
    $user=User::factory()->create(['credit'=>'100.00','frozen_credit'=>'0.00']);
    $product=App\Models\Product::factory()->create(['local_prices'=>['onetime'=>['price'=>'1000.00','setup_fee'=>'0.00','enabled'=>true]]]);
    $operator=User::factory()->create();
    app(App\Actions\AssignAdminRoleAction::class)->execute($operator,'finance-wallet-settings','Isolated wallet switch race');
    echo json_encode(['user'=>$user->id,'product'=>$product->id,'operator'=>$operator->id]);exit;
}
if($op==='state') {
    $user=User::withTrashed()->findOrFail($data['user']);
    echo json_encode(['credit'=>$user->credit,'frozen'=>$user->frozen_credit,'closed'=>$user->trashed(),
        'invoices'=>Invoice::where('user_id',$user->id)->orderBy('id')->get(['id','type','status','topup_request_id','total_amount','credit_applied']),
        'ledger'=>App\Models\FinancialTransaction::where('user_id',$user->id)->orderBy('id')->get(['type','amount','reference_key','balance_after','frozen_after']),
        'callbacks'=>App\Models\PaymentCallback::orderBy('id')->get(['gateway_trade_no','outcome']),
        'instances'=>App\Models\Instance::where('user_id',$user->id)->count(),'tasks'=>App\Models\FulfillmentTask::count(),
        'events'=>App\Models\NotificationEvent::count()]);exit;
}
if($op==='prepare_expiry') {
    Invoice::findOrFail($data['invoice'])->update(['expires_at'=>now()->subMinute()]);echo '{}';exit;
}
try {
    $result=match($op) {
        'create'=>app(CreateWalletTopupInvoiceAction::class)->execute(User::findOrFail($data['user']),
            ['request_id'=>$data['request'],'amount'=>'10.00','gateway'=>'epay_wechat'])->id,
        'callback'=>app(HandlePaymentCallbackAction::class)->execute(new App\Data\VerifiedPaymentResult(gateway:'epay_wechat',
            gatewayTradeNo:$data['trade'],outTradeNo:Invoice::findOrFail($data['invoice'])->invoice_no,paidAmount:'10.00')),
        'freeze'=>app(CreateComboInvoiceAction::class)->execute(User::findOrFail($data['user']),App\Models\Product::findOrFail($data['product']),
            App\Enums\BillingCycle::ONETIME,[],true,'epay_wechat',['request_id'=>$data['request']])->id,
        'expire'=>(function(){app(ExpireUnpaidInvoicesAction::class)->execute();return 'expired';})(),
        'disable'=>(function()use($data){app(ConfigureWalletTopupAction::class)->execute(User::findOrFail($data['operator']),false,'Concurrent stop of new topups');return 'disabled';})(),
    };
    echo json_encode(['result'=>'accepted','value'=>$result]);
} catch(Illuminate\Validation\ValidationException $error) {
    echo json_encode(['result'=>'rejected','fields'=>array_keys($error->errors())]);
}
''')


def run(command, name, timeout=90):
    result = subprocess.run(command, env=env, cwd=repo, capture_output=True, text=True, timeout=timeout)
    (root / name).write_text(result.stdout + result.stderr)
    if result.returncode:
        raise RuntimeError('Wallet verification failed; see ' + str(root / name) + '\n' + result.stdout[-3000:] + result.stderr[-3000:])
    return result.stdout


def operation(op, payload=None, name=None):
    return json.loads(run(['php', str(root / 'worker.php'), op, '', json.dumps(payload or {})], name or op + '.log'))


def race(calls, name):
    processes = [subprocess.Popen(['php', str(root / 'worker.php'), op, name, json.dumps(payload)], env=env, cwd=repo,
                                  stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) for op, payload in calls]
    try:
        deadline = time.monotonic() + 20
        while len(list(root.glob(name + '.*.ready'))) < len(processes):
            if time.monotonic() > deadline or any(p.poll() is not None for p in processes):
                raise RuntimeError('Wallet race participants failed to reach barrier: ' + name)
            time.sleep(.05)
        (root / (name + '.go')).write_text('go')
        result = []
        for index, process in enumerate(processes):
            out, err = process.communicate(timeout=30)
            (root / (name + '-' + str(index) + '.log')).write_text(out + err)
            if process.returncode:
                raise RuntimeError('Wallet race failed: ' + name + '\n' + out + err)
            result.append(json.loads(out))
        return result
    finally:
        for process in processes:
            if process.poll() is None:
                process.kill()
                process.wait(timeout=5)


evidence = {}
try:
    subprocess.run(['docker', 'run', '--detach', '--pull=never', '--name', container,
                    '--label', 'laravel13idc.review=wallet-topup', '--network', 'none', '--tmpfs', '/var/lib/mysql:rw,size=768m',
                    '--mount', 'type=bind,source=' + str(socket_dir) + ',target=/var/run/mysqld',
                    '--env', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes', '--env', 'MYSQL_DATABASE=wallet_topup_only',
                    image, '--performance-schema=OFF', '--innodb-buffer-pool-size=32M', '--mysqlx=OFF', '--skip-log-bin'],
                   check=True, capture_output=True, timeout=20)
    probe = "echo (new PDO('mysql:unix_socket='.getenv('DB_SOCKET').';dbname=wallet_topup_only','root',''))->query('SELECT VERSION()')->fetchColumn();"
    deadline = time.monotonic() + 45
    while True:
        result = subprocess.run(['php', '-r', probe], env=env, cwd=repo, capture_output=True, text=True, timeout=5)
        stable = subprocess.run(['docker', 'exec', container, 'cat', '/proc/1/comm'], capture_output=True, text=True, timeout=5)
        if result.returncode == 0 and stable.stdout.strip() == 'mysqld':
            evidence['mysql_version'] = result.stdout
            break
        if time.monotonic() > deadline:
            raise RuntimeError('Private MySQL 8 did not become ready')
        time.sleep(.2)
    assert evidence['mysql_version'].startswith('8.'), evidence
    run(['php', 'artisan', 'migrate', '--force'], 'migrate.log')
    output = run(['./vendor/bin/pest', 'tests/Feature/WalletTopupTest.php', '--compact', '--colors=never'], 'mysql-wallet-tests.log')
    print(output[-1000:], flush=True)
    # PHPUnit runs rolled back test fixtures; the independent multi-process fixture starts from empty tables.
    fixture = operation('setup')
    owner = {'user': fixture['user']}
    request = '01KAT000000000000000000001'
    same = race([('create', owner | {'request': request}), ('create', owner | {'request': request})], 'same-request')
    assert all(row['result'] == 'accepted' for row in same) and same[0]['value'] == same[1]['value'], same
    invoice = same[0]['value']
    evidence['same_request'] = same
    duplicate = race([('callback', {'invoice': invoice, 'trade': 'DUPLICATE'})] * 2, 'same-trade')
    state = operation('state', owner, 'after-same-trade.json')
    assert state['credit'] == '110.00' and len(state['ledger']) == 2 and len(state['callbacks']) == 1, state
    assert state['instances'] == state['tasks'] == 0, state
    evidence['same_trade'] = duplicate
    invoice2 = operation('create', owner | {'request': '01KAT000000000000000000002'})['value']
    distinct = race([('callback', {'invoice': invoice2, 'trade': 'DISTINCT-A'}),
                     ('callback', {'invoice': invoice2, 'trade': 'DISTINCT-B'})], 'distinct-trades')
    state = operation('state', owner, 'after-distinct-trades.json')
    assert state['credit'] == '130.00' and len(state['ledger']) == 5 and len(state['callbacks']) == 3, state
    evidence['distinct_trades'] = distinct
    invoice3 = operation('create', owner | {'request': '01KAT000000000000000000003'})['value']
    freeze = race([('callback', {'invoice': invoice3, 'trade': 'WITH-FREEZE'}),
                   ('freeze', owner | {'product': fixture['product'], 'request': '01KAT000000000000000000004'})], 'topup-freeze')
    state = operation('state', owner, 'after-topup-freeze.json')
    assert state['credit'] in ['0.00', '10.00'] and state['frozen'] in ['130.00', '140.00'], state
    assert (state['credit'], state['frozen']) in [('0.00', '140.00'), ('10.00', '130.00')], state
    purchase = next(row for row in state['invoices'] if row['type'] == 'new_order')
    operation('prepare_expiry', {'invoice': purchase['id']})
    invoice4 = operation('create', owner | {'request': '01KAT000000000000000000005'})['value']
    expiry = race([('callback', {'invoice': invoice4, 'trade': 'WITH-UNFREEZE'}), ('expire', {})], 'topup-unfreeze')
    state = operation('state', owner, 'after-topup-unfreeze.json')
    assert state['credit'] == '150.00' and state['frozen'] == '0.00', state
    evidence['purchase_freeze'] = freeze
    evidence['expiry_unfreeze'] = expiry
    switch = race([('create', owner | {'request': '01KAT000000000000000000006'}),
                   ('disable', {'operator': fixture['operator']})], 'topup-switch')
    assert switch[1]['result'] == 'accepted' and switch[0]['result'] in ['accepted', 'rejected'], switch
    denied = operation('create', owner | {'request': '01KAT000000000000000000007'})
    assert denied['result'] == 'rejected', denied
    evidence['disable_create'] = switch
    state = operation('state', owner, 'final-state.json')
    assert state['credit'] == '150.00' and state['frozen'] == '0.00' and state['instances'] == state['tasks'] == 0, state
    evidence['all_races_passed'] = True
    (root / 'evidence.json').write_text(json.dumps(evidence, indent=2))
    print(json.dumps(evidence, indent=2), flush=True)
finally:
    try:
        logs = subprocess.run(['docker', 'logs', container], capture_output=True, text=True, timeout=10)
        (root / 'mysql-container.log').write_text(logs.stdout + logs.stderr)
    finally:
        removed = subprocess.run(['docker', 'rm', '--force', container], capture_output=True, text=True, timeout=20)
        (root / 'container-removal.log').write_text(removed.stdout + removed.stderr)
        if removed.returncode:
            raise RuntimeError('Could not remove the isolated wallet container: ' + container)
