"""Verify approved C23 recovery races in a disposable offline MySQL 8 database."""

import argparse
import base64
import json
import os
import pathlib
import subprocess
import tempfile
import time
import uuid


def run_recovery_races(repo, root, env):
    (root / 'storage/installed.lock').write_text('isolated C23 renewal races')
    env = dict(env, QUEUE_CONNECTION='database')
    worker = root / 'recovery-race.php'
    worker.write_text(r'''<?php
require getenv('RENEWAL_TEST_REPO').'/vendor/autoload.php';
$app = require getenv('RENEWAL_TEST_REPO').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Actions\ClaimCouponAction;
use App\Actions\CreateComboInvoiceAction;
use App\Actions\ExecuteFulfillmentTaskAction;
use App\Actions\ExpireUnpaidInvoicesAction;
use App\Actions\HandlePaymentCallbackAction;
use App\Actions\QuoteRenewalRecoveryAction;
use App\Data\VerifiedPaymentResult;
use App\Enums\BillingCycle;
use App\Enums\DriverType;
use App\Enums\InstanceStatus;
use App\Models\Coupon;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
Http::preventStrayRequests();
$root = getenv('RENEWAL_TEST_ROOT');
$data = json_decode($argv[2] ?? '{}',true,flags:JSON_THROW_ON_ERROR);
function awaitFile(string $file): void {
    $deadline=microtime(true)+20;
    while (!is_file($file)) {
        if (microtime(true)>$deadline) { throw new RuntimeException('Recovery process barrier timed out.'); }
        usleep(10000);
    }
}
if ($argv[1]==='migration') {
    $legacy=Invoice::factory()->count(2)->create();
    $columns=Illuminate\Support\Facades\Schema::getColumnListing('invoices');
    $columns=array_values(array_diff($columns,['renewal_root_id','replaces_invoice_id']));
    $before=DB::table('invoices')->whereIn('id',$legacy->modelKeys())->orderBy('id')->get($columns)->toJson();
    $migration=require getenv('RENEWAL_TEST_REPO').'/database/migrations/2026_11_18_000002_add_renewal_recovery_links_to_invoices.php';
    $migration->down(); $migration->up();
    $after=DB::table('invoices')->whereIn('id',$legacy->modelKeys())->orderBy('id')->get($columns)->toJson();
    if ($before!==$after || Invoice::whereIn('id',$legacy->modelKeys())->whereNotNull('renewal_root_id')->exists()) {
        throw new RuntimeException('Recovery migration changed populated legacy invoices.');
    }
    echo json_encode(['preserved'=>2],JSON_THROW_ON_ERROR); exit;
}
if ($argv[1]==='fixture') {
    $user=User::factory()->create(['credit'=>$data['credit']]);
    $account=UpstreamApi::factory()->create();
    $product=Product::factory()->create(['driver_type'=>DriverType::ZJMF_API,'upstream_api_id'=>$account->id,'markup_percent'=>'0.00']);
    $instance=Instance::factory()->create(['user_id'=>$user->id,'product_id'=>$product->id,'driver_type'=>DriverType::ZJMF_API,
        'upstream_api_id'=>$account->id,'upstream_instance_id'=>'99','status'=>InstanceStatus::ACTIVE,'billing_cycle'=>BillingCycle::MONTHLY,
        'next_due_at'=>now()->addDay()->startOfSecond(),'recurring_amount'=>$data['cost'],'active_config'=>[]]);
    $coupon=Coupon::create(['code'=>'RECOVER'.Str::ulid(),'type'=>'fixed','value'=>'1.00','min_order_amount'=>'0.00',
        'applies_to'=>'renew','total_quota'=>4,'per_user_limit'=>4,'is_active'=>true]);
    app(ClaimCouponAction::class)->execute($user,$coupon->code);
    $data+=['user'=>$user->id,'instance'=>$instance->id,'coupon'=>$coupon->code,'request_id'=>(string)Str::ulid()];
} else {
    $user=User::findOrFail($data['user']); $instance=Instance::findOrFail($data['instance']);
}
$due=$instance->next_due_at->toIso8601String();
Http::fake(function($request) use(&$due,$data,$root,$argv) {
    if (DB::transactionLevel()!==0) { throw new LogicException('Upstream I/O under a database lock.'); }
    $path=parse_url($request->url(),PHP_URL_PATH);
    file_put_contents($root.'/calls-'.$data['user'],$path."\n",FILE_APPEND|LOCK_EX);
    if ($path==='/host/renewpage' && ($argv[3] ?? '')==='quote-wait') {
        file_put_contents($root.'/quote-'.$data['user'],'waiting');
        awaitFile($root.'/return-'.$data['user']);
    }
    if ($path==='/apply_credit') { $due=Carbon::parse($due)->addMonthNoOverflow()->toIso8601String(); }
    return Http::response(match($path) {
        '/zjmf_api_login'=>['status'=>200,'jwt'=>'isolated-recovery-jwt'],
        '/host/header'=>['status'=>200,'data'=>['host_data'=>['domainstatus'=>'Active','nextduedate'=>$due]]],
        '/host/renewpage'=>['status'=>200,'data'=>['host'=>['amount'=>$data['cost'],'billingcycle'=>'monthly'],'currency'=>['code'=>'CNY']]],
        '/host/renew'=>['status'=>200,'data'=>['invoiceid'=>765]],
        '/use_credit_page'=>['status'=>200,'data'=>['total'=>$data['cost'],'currency'=>['code'=>'CNY']]],
        '/apply_credit'=>['status'=>1001],
        default=>throw new RuntimeException('Unexpected recovery endpoint '.$path),
    });
});
if ($argv[1]==='fixture') {
    $original=app(CreateComboInvoiceAction::class)->renewal($user,$instance,false,'epay_wechat');
    $original->update(['expires_at'=>now()->subSecond()]);
    app(ExpireUnpaidInvoicesAction::class)->execute();
    $quote=app(QuoteRenewalRecoveryAction::class)->execute($user,$original->fresh(),$data['wallet'],'epay_wechat',$coupon->code);
    echo json_encode($data+['parent'=>$original->id,'quote'=>$quote['quote_token']],JSON_THROW_ON_ERROR);
} elseif ($argv[1]==='inspect') {
    $invoices=Invoice::where('user_id',$user->id)->orderBy('id')->get();
    $tasks=FulfillmentTask::where('user_id',$user->id)->orderBy('id')->get(['id','status','invoice_id']);
    echo json_encode(['credit'=>$user->credit,'frozen'=>$user->frozen_credit,
        'invoices'=>$invoices->map(fn($i)=>['id'=>$i->id,'status'=>$i->status->value,'parent'=>$i->replaces_invoice_id,'root'=>$i->renewal_root_id,
            'gateway'=>$i->gateway_amount,'credit'=>$i->credit_applied])->all(),
        'ledger'=>DB::table('financial_transactions')->where('user_id',$user->id)->orderBy('id')->get(['reference_key','amount','type'])->all(),
        'tasks'=>$tasks->toArray(),'uses'=>DB::table('coupon_usages')->where('user_id',$user->id)->count(),
        'callbacks'=>DB::table('payment_callbacks')->whereIn('invoice_id',$invoices->modelKeys())->count(),
        'audit'=>DB::table('audit_logs')->where('actor_id',$user->id)->where('action','invoice.renewal_recovery')->count(),
        'jobs'=>DB::table('jobs')->where('payload','like','%ExecuteFulfillmentTaskJob%')->get(['payload'])->filter(function($job) use($tasks) {
            $payload=json_decode($job->payload,true,flags:JSON_THROW_ON_ERROR);
            $command=$payload['data']['command'] ?? '';
            return $tasks->contains(fn($task)=>str_contains($command,'s:6:"taskId";i:'.$task->id.';'));
        })->count(),
        'attempts'=>DB::table('payment_attempts')->whereIn('invoice_id',$invoices->modelKeys())->count()],JSON_THROW_ON_ERROR);
} elseif ($argv[1]==='quote') {
    $quote=app(QuoteRenewalRecoveryAction::class)->execute($user,Invoice::findOrFail($data['parent']),$data['wallet'],'epay_wechat',$data['coupon']);
    echo json_encode(['quote'=>$quote['quote_token'],'request_id'=>(string)Str::ulid()],JSON_THROW_ON_ERROR);
} elseif ($argv[1]==='run') {
    $mode=$argv[3]; $barrier=$argv[4];
    if ($barrier!=='-') {
        file_put_contents($root.'/'.$barrier.'.ready.'.getmypid(),'ready');
        awaitFile($root.'/'.$barrier.'.go');
    }
    try {
        if ($mode==='expire') {
            app(ExpireUnpaidInvoicesAction::class)->execute();
            echo json_encode(['expired'=>true],JSON_THROW_ON_ERROR);
        } elseif ($mode==='late' || $mode==='paid') {
            $invoice=Invoice::findOrFail($mode==='late' ? $data['parent'] : $data['child']);
            $ack=app(HandlePaymentCallbackAction::class)->execute(new VerifiedPaymentResult('epay_wechat',$mode.'-'.$data['user'],$invoice->invoice_no,$invoice->gateway_amount));
            echo json_encode(['ack'=>$ack],JSON_THROW_ON_ERROR);
        } else {
            $invoice=app(CreateComboInvoiceAction::class)->recoverRenewal($user,Invoice::findOrFail($data['parent']),$data['request_id'],$data['quote'],
                $data['wallet'],'epay_wechat',$data['coupon'],$data['reason'] ?? 'Customer recovery race confirmation');
            echo json_encode(['id'=>$invoice->id,'total'=>$invoice->total_amount],JSON_THROW_ON_ERROR);
        }
    } catch (ValidationException $exception) {
        echo json_encode(['validation'=>$exception->errors()],JSON_THROW_ON_ERROR);
    }
} elseif ($argv[1]==='expire') {
    $invoice=Invoice::findOrFail($data['child']);
    $invoice->update(['expires_at'=>now()->subSecond()]);
    if (!($data['defer'] ?? false)) { app(ExpireUnpaidInvoicesAction::class)->execute(); }
    echo json_encode(['expired'=>true],JSON_THROW_ON_ERROR);
} elseif ($argv[1]==='fulfill') {
    foreach(FulfillmentTask::where('user_id',$user->id)->get() as $task) { app(ExecuteFulfillmentTaskAction::class)->execute($task->id); }
    echo json_encode(['fulfilled'=>true],JSON_THROW_ON_ERROR);
} else { throw new RuntimeException('Unknown recovery test operation.'); }
''')

    def call(op, data):
        result = subprocess.run(['php', str(worker), op, json.dumps(data)], cwd=repo, env=env,
                                capture_output=True, text=True, check=True, timeout=40)
        return json.loads(result.stdout)

    def start(data, mode='recover', barrier='-'):
        return subprocess.Popen(['php', str(worker), 'run', json.dumps(data), mode, barrier],
                                cwd=repo, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)

    def finish(process):
        stdout, stderr = process.communicate(timeout=40)
        assert process.returncode == 0, (process.returncode, stderr, stdout)
        return json.loads(stdout)

    def wait_for(predicate, processes):
        deadline = time.monotonic() + 20
        while not predicate():
            if time.monotonic() >= deadline or any(p.poll() is not None for p in processes):
                raise RuntimeError('Recovery workers failed to reach their barrier.')
            time.sleep(0.01)

    def race(first, second, label, modes=('recover', 'recover')):
        processes = [start(first, modes[0], label), start(second, modes[1], label)]
        wait_for(lambda: len(list(root.glob(label + '.ready.*'))) == 2, processes)
        (root / (label + '.go')).write_text('go')
        return [finish(p) for p in processes]

    def fixture(credit='100.00', cost='10.00', wallet=True):
        return call('fixture', dict(credit=credit, cost=cost, wallet=wallet))

    sqlite_file = root / 'migration.sqlite'
    sqlite_file.touch()
    sqlite_env = dict(env, DB_CONNECTION='sqlite', DB_DATABASE=str(sqlite_file), DB_SOCKET='')
    subprocess.run(['php', 'artisan', 'migrate', '--force', '--no-interaction'], cwd=repo, env=sqlite_env,
                   capture_output=True, text=True, check=True, timeout=40)
    migrated = subprocess.run(['php', str(worker), 'migration', '{}'], cwd=repo, env=sqlite_env,
                              capture_output=True, text=True, check=True, timeout=40)
    assert json.loads(migrated.stdout) == {'preserved': 2}
    print('PASS: SQLite populated migration down/up preserves legacy values.', flush=True)
    assert call('migration', {}) == {'preserved': 2}
    print('PASS: MySQL populated migration down/up preserves legacy values.', flush=True)
    cases = [('wallet', '100.00', '10.00', True, '91.00', '0.00', '0.00', 1, 2),
             ('combo', '3.00', '10.00', True, '0.00', '3.00', '6.00', 0, 1),
             ('gateway', '100.00', '10.00', False, '100.00', '0.00', '9.00', 0, 0),
             ('free', '0.00', '1.00', True, '0.00', '0.00', '0.00', 1, 1)]
    for label, credit, cost, wallet, balance, frozen, gateway, tasks, ledger in cases:
        data = fixture(credit, cost, wallet)
        results = race(data, data, label)
        assert results[0]['id'] == results[1]['id'], results
        state = call('inspect', data)
        assert state['credit'] == balance and state['frozen'] == frozen, state
        assert len(state['invoices']) == 2 and state['invoices'][0]['status'] == 'Expired', state
        assert state['invoices'][1]['gateway'] == gateway and state['invoices'][1]['parent'] == data['parent'], state
        assert len(state['tasks']) == tasks and len(state['ledger']) == ledger, state
        assert state['uses'] == 1 and state['audit'] == 1 and state['attempts'] == 0, state
        assert finish(start(data)) == results[0] and call('inspect', data) == state
        assert state['jobs'] == tasks, state
        print('PASS: MySQL concurrent ' + label + ' recovery and committed-response replay.', flush=True)

    data = fixture()
    results = race(data, dict(data, reason='Changed recovery confirmation'), 'changed-identity')
    assert sum('id' in r for r in results) == 1 and sum('request_id' in r.get('validation', {}) for r in results) == 1, results
    state = call('inspect', data)
    assert state['credit'] == '91.00' and len(state['invoices']) == 2 and state['uses'] == 1, state
    print('PASS: same key with different confirmation rejects one caller without duplicate debit.', flush=True)

    data = fixture(wallet=False)
    other = dict(data, request_id='01KAD0000000000000000000XY')
    results = race(data, other, 'different-keys')
    assert results[0]['id'] == results[1]['id'], results
    first_child = results[0]['id']
    call('expire', dict(data, child=first_child))
    next_data = dict(data, parent=first_child)
    next_data.update(call('quote', next_data))
    second = finish(start(next_data))
    state = call('inspect', data)
    assert len(state['invoices']) == 3 and state['invoices'][2]['root'] == data['parent'], state
    assert state['invoices'][2]['parent'] == first_child and second['id'] != first_child, state
    assert state['uses'] == 2 and state['credit'] == '100.00', state
    print('PASS: different keys cannot branch; another expiry requires a new tip quote and confirmation.', flush=True)

    data = fixture()
    waiting = start(data, 'quote-wait')
    wait_for(lambda: (root / ('quote-' + str(data['user']))).exists(), [waiting])
    winner = finish(start(data))
    (root / ('return-' + str(data['user']))).write_text('return')
    assert finish(waiting) == winner
    state = call('inspect', data)
    assert state['credit'] == '91.00' and state['uses'] == 1 and len(state['tasks']) == 1, state
    print('PASS: quotation in flight returns the committed successor after acquiring the user lock.', flush=True)

    data = fixture(credit='3.00')
    child = finish(start(data))['id']
    payments = dict(data, child=child)
    results = race(payments, payments, 'old-new-payment', ('late', 'paid'))
    assert results == [{'ack': 'success'}, {'ack': 'success'}], results
    call('fulfill', data)
    state = call('inspect', data)
    assert state['credit'] == '10.00' and state['frozen'] == '0.00' and len(state['tasks']) == 1, state
    assert state['tasks'][0]['status'] == 'success' and state['invoices'][0]['status'] == 'Expired', state
    assert finish(start(payments, 'late')) == {'ack': 'success'} and finish(start(payments, 'paid')) == {'ack': 'success'}
    assert call('inspect', data) == state
    calls = (root / ('calls-' + str(data['user']))).read_text().splitlines()
    assert calls.count('/host/renew') == 1 and calls.count('/apply_credit') == 1, calls
    print('PASS: old/new payment race and deduplication cause one upstream renewal; old money only credits the wallet.', flush=True)

    data = fixture(credit='3.00')
    results = race(data, data, 'old-before-recovery', ('late', 'recover'))
    # A late payment changes the displayed wallet split. Confirmation must re-quote if it lost.
    assert results[0] == {'ack': 'success'}, results
    if 'validation' in results[1]:
        data.update(call('quote', data))
        assert 'id' in finish(start(data))
    state = call('inspect', data)
    assert len(state['invoices']) == 2 and state['uses'] == 1 and state['audit'] == 1, state
    print('PASS: old payment versus recovery either preserves the confirmed split or requires a new quote.', flush=True)

    data = fixture(credit='3.00')
    child = finish(start(data))['id']
    payments = dict(data, child=child)
    call('expire', dict(payments, defer=True))
    results = race(payments, payments, 'expiry-payment', ('expire', 'paid'))
    state = call('inspect', data)
    status = state['invoices'][1]['status']
    assert status in ['Paid', 'Expired'] and state['frozen'] == '0.00', state
    assert (status == 'Paid' and len(state['tasks']) == 1 and state['credit'] == '0.00') or (status == 'Expired' and len(state['tasks']) == 0 and state['credit'] == '9.00'), state
    assert state['callbacks'] == 1, state
    print('PASS: recovery expiry versus callback has exactly one settlement or unfreeze-plus-late-credit outcome.', flush=True)


parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--image', default='mysql:8.0.35', help='Locally available MySQL 8 image; never pulled')
args = parser.parse_args()
repo = pathlib.Path(__file__).resolve().parents[1]
subprocess.run(['docker', 'image', 'inspect', args.image], capture_output=True, check=True, timeout=10)
container = 'laravel13idc-fix005-' + uuid.uuid4().hex[:12]

with tempfile.TemporaryDirectory(prefix='laravel13idc-fix005-mysql-') as temporary:
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
               DB_SOCKET=str(socket_dir / 'mysqld.sock'), DB_DATABASE='renewal_isolated',
               DB_USERNAME='root', DB_PASSWORD='', CACHE_STORE='array', QUEUE_CONNECTION='sync', SESSION_DRIVER='array', LOG_CHANNEL='single', BCRYPT_ROUNDS='4',
               RENEWAL_TEST_REPO=str(repo), RENEWAL_TEST_ROOT=str(root))
    probe = root / 'probe.php'
    probe.write_text("""<?php
$pdo = new PDO('mysql:unix_socket='.getenv('DB_SOCKET').';dbname=renewal_isolated', 'root', '');
$version = $pdo->query('SELECT VERSION()')->fetchColumn();
if (!str_starts_with($version, '8.')) { throw new RuntimeException('MySQL 8 is required.'); }
echo $version;
""")
    try:
        subprocess.run(['docker', 'run', '--detach', '--pull=never', '--name', container,
                        '--label', 'laravel13idc.remediation=FIX-005', '--network', 'none',
                        '--tmpfs', '/var/lib/mysql:rw,size=768m',
                        '--mount', 'type=bind,source=' + str(socket_dir) + ',target=/var/run/mysqld',
                        '--env', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes', '--env', 'MYSQL_DATABASE=renewal_isolated',
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
        subprocess.run(['vendor/bin/pest', 'tests/Feature/RenewalRecoveryTest.php', 'tests/Feature/ComboInvoiceTest.php', 'tests/Feature/SecretRotationTest.php', '--compact'],
                       cwd=repo, env=env, check=True, timeout=180)
        run_recovery_races(repo, root, env)
        print('PASS: isolated MySQL 8 renewal recovery, linear chains, concurrent wallet/coupon/outbox, quotes and callbacks; business databases untouched.', flush=True)
    finally:
        subprocess.run(['docker', 'rm', '--force', container], capture_output=True, timeout=20)
