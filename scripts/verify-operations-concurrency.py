"""Run money races on a disposable MySQL 5.7+ process; never connect to a business DB."""
import argparse
import base64
import json
import os
import pathlib
import pwd
import subprocess
import tempfile
import time

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--mysqld', default='/www/server/mysql/bin/mysqld')
args = parser.parse_args()
repo = pathlib.Path(__file__).resolve().parents[1]
mysqld = pathlib.Path(args.mysqld).resolve()
assert mysqld.is_file(), 'Supply a local mysqld binary for a disposable instance'
with tempfile.TemporaryDirectory(prefix='laravel13idc-ops-mysql-') as tmp:
    root = pathlib.Path(tmp)
    datadir = root / 'mysql'
    datadir.mkdir()
    storage = root / 'storage'
    for folder in ['framework/views', 'framework/cache/data', 'logs', 'app/private']:
        (storage / folder).mkdir(parents=True)
    (storage / 'installed.lock').write_text('disposable operations concurrency')
    common = [str(mysqld), '--no-defaults', '--basedir=' + str(mysqld.parent.parent), '--datadir=' + str(datadir),
              '--user=' + pwd.getpwuid(os.getuid()).pw_name]
    subprocess.run(common + ['--initialize-insecure'], capture_output=True, check=True, timeout=60)
    logs = open(root / 'mysql.log', 'w+')
    server = subprocess.Popen(common + ['--skip-networking', '--socket=' + str(root / 'mysql.sock'), '--pid-file=' + str(root / 'mysql.pid'),
                                        '--innodb-buffer-pool-size=32M', '--innodb-log-file-size=4M', '--performance-schema=OFF'], stdout=logs, stderr=logs)
    try:
        for _ in range(100):
            if (root / 'mysql.sock').exists():
                break
            assert server.poll() is None, 'Disposable MySQL failed to start; see its log'
            time.sleep(.1)
        else:
            raise RuntimeError('Disposable MySQL did not start')
        env = dict(os.environ, OPS_TEST_REPO=str(repo), OPS_TEST_ROOT=str(root), APP_ENV='testing', APP_DEBUG='false',
                   APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(), APP_CONFIG_CACHE=str(root / 'config.php'),
                   APP_ROUTES_CACHE=str(root / 'routes.php'), LARAVEL_STORAGE_PATH=str(storage), VIEW_COMPILED_PATH=str(storage / 'framework/views'),
                   DB_CONNECTION='mysql', DB_URL='', DB_HOST='localhost', DB_SOCKET=str(root / 'mysql.sock'), DB_USERNAME='root', DB_PASSWORD='',
                   DB_DATABASE='operations_isolated', QUEUE_CONNECTION='database', CACHE_STORE='array', SESSION_DRIVER='array', LOG_CHANNEL='single')
        bootstrap = "require getenv('OPS_TEST_REPO').'/vendor/autoload.php';\n$app=require getenv('OPS_TEST_REPO').'/bootstrap/app.php';\n"
        create_db = root / 'create.php'
        create_db.write_text("<?php\n(new PDO('mysql:unix_socket='.getenv('DB_SOCKET'), 'root', ''))->exec('CREATE DATABASE operations_isolated CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');\n")
        subprocess.run(['php', str(create_db)], cwd=repo, env=env, capture_output=True, check=True, timeout=20)
        console = root / 'console.php'
        console.write_text('<?php\n' + bootstrap + 'exit($app->handleCommand(new Symfony\\Component\\Console\\Input\\ArgvInput));\n')
        subprocess.run(['php', str(console), 'migrate', '--force'], cwd=repo, env=env, capture_output=True, check=True, timeout=45)
        worker = root / 'worker.php'
        worker.write_text('<?php\n' + bootstrap + r'''
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Actions\ClaimCouponAction;
use App\Actions\CreateComboInvoiceAction;
use App\Actions\RequestAffiliateWithdrawalAction;
use App\Actions\ProcessAffiliateWithdrawalAction;
use App\Models\User;
use App\Models\Coupon;
use App\Models\AffiliateWithdrawal;
use App\Models\FinancialTransaction;
$op=$argv[1];
if ($op === 'fixture') {
    $product=app(App\Actions\CreateLocalCardProductAction::class)->execute('Race card','10.00','0.00','CNY','Disposable MySQL fixture');
    $a=User::factory()->create(['credit'=>'100.00','affiliate_balance'=>'20.00']);
    $b=User::factory()->create(['credit'=>'100.00']);
    $operator=User::factory()->create();
    app(App\Actions\AssignAdminRoleAction::class)->execute($operator,'finance-payout','Disposable race operator');
    $coupon=Coupon::create(['code'=>'RACE','type'=>'percent','value'=>'10.00','min_order_amount'=>'0.00','applies_to'=>'both','per_user_limit'=>1,'total_quota'=>1,'is_active'=>true]);
    app(ClaimCouponAction::class)->execute($a,'RACE'); app(ClaimCouponAction::class)->execute($b,'RACE');
    echo json_encode(['a'=>$a->id,'b'=>$b->id,'operator'=>$operator->id,'product'=>$product->id]); exit;
}
if ($op === 'check') {
    echo json_encode(['invoices'=>App\Models\Invoice::count(),'usages'=>Illuminate\Support\Facades\DB::table('coupon_usages')->count(),
        'quota'=>Coupon::first()->used_count,'users'=>User::orderBy('id')->get(['id','credit','affiliate_balance']),
        'withdrawals'=>AffiliateWithdrawal::get(['id','status','amount']),'payouts'=>FinancialTransaction::where('type','affiliate_payout')->get(['amount','reference_key'])]); exit;
}
$barrier=getenv('OPS_TEST_ROOT').'/'.$argv[2];
file_put_contents($barrier.'.'.getmypid().'.ready','ready');
$until=microtime(true)+15;
while (!is_file($barrier.'.go')) { if(microtime(true)>$until) { throw new RuntimeException('Race barrier timed out'); } usleep(10000); }
$data=json_decode($argv[3],true,flags:JSON_THROW_ON_ERROR);
try {
    $result=match($op) {
        'checkout'=>app(CreateComboInvoiceAction::class)->execute(User::findOrFail($data['user']),App\Models\Product::findOrFail($data['product']),App\Enums\BillingCycle::ONETIME,[],true,null,['request_id'=>'01KAD000000000000000000001','coupon_code'=>'RACE'])->id,
        'withdraw'=>app(RequestAffiliateWithdrawalAction::class)->execute(User::findOrFail($data['user']),'15.00',$data['request'])->id,
        'approve'=>(function () use($data) { app(ProcessAffiliateWithdrawalAction::class)->execute(AffiliateWithdrawal::findOrFail($data['id']),User::findOrFail($data['operator']),true,'Concurrent timeout retry'); return $data['id']; })(),
    };
    echo json_encode(['result'=>'accepted','id'=>$result]);
} catch (Illuminate\Validation\ValidationException $e) {
    echo json_encode(['result'=>'rejected','fields'=>array_keys($e->errors())]);
}
''')
        def run(op):
            result = subprocess.run(['php', str(worker), op], cwd=repo, env=env, capture_output=True, text=True, timeout=30)
            assert result.returncode == 0, result.stdout + result.stderr
            return json.loads(result.stdout)

        def race(op, payloads, name):
            processes = [subprocess.Popen(['php', str(worker), op, name, json.dumps(payload)], cwd=repo, env=env,
                                          stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) for payload in payloads]
            try:
                for _ in range(150):
                    if len(list(root.glob(name + '.*.ready'))) == len(processes):
                        break
                    assert all(p.poll() is None for p in processes), 'Race process exited before barrier'
                    time.sleep(.05)
                else:
                    raise RuntimeError('Race participants not ready')
                (root / (name + '.go')).write_text('go')
                results = []
                for process in processes:
                    out, err = process.communicate(timeout=20)
                    assert process.returncode == 0, out + err
                    results.append(json.loads(out))
                return results
            finally:
                for process in processes:
                    if process.poll() is None:
                        process.kill()
                        process.wait(timeout=5)

        fixture = run('fixture')
        result = race('checkout', [{'user': fixture['a'], 'product': fixture['product']}, {'user': fixture['b'], 'product': fixture['product']}], 'coupon')
        assert sorted(r['result'] for r in result) == ['accepted', 'rejected'], result
        state = run('check')
        assert state['invoices'] == state['usages'] == state['quota'] == 1, state
        assert sorted(u['credit'] for u in state['users'][:2]) == ['100.00', '91.00'], state
        request_ids = ['01KAA000000000000000000001', '01KAA000000000000000000002']
        result = race('withdraw', [{'user': fixture['a'], 'request': key} for key in request_ids], 'occupancy')
        assert sorted(r['result'] for r in result) == ['accepted', 'rejected'], result
        withdrawal_id = next(r['id'] for r in result if r['result'] == 'accepted')
        retry_key = request_ids[next(i for i, r in enumerate(result) if r['result'] == 'accepted')]
        retried = race('withdraw', [{'user': fixture['a'], 'request': retry_key}] * 2, 'request-idempotency')
        assert all(r['result'] == 'accepted' and r['id'] == withdrawal_id for r in retried), retried
        before = run('check')['users'][0]['credit']
        result = race('approve', [{'id': withdrawal_id, 'operator': fixture['operator']}] * 2, 'approval')
        assert all(r['result'] == 'accepted' for r in result), result
        state = run('check')
        from decimal import Decimal
        assert state['users'][0]['affiliate_balance'] == '5.00', state
        assert Decimal(state['users'][0]['credit']) == Decimal(before) + Decimal('15.00'), state
        assert len(state['withdrawals']) == len(state['payouts']) == 1 and state['withdrawals'][0]['status'] == 'approved', state
        print('PASS: disposable MySQL coupon quota race, pending occupancy race, duplicate request key and duplicate approval; one payout ledger, correct balances. Business DB untouched.')
    finally:
        server.terminate()
        server.wait(timeout=20)
        logs.close()
