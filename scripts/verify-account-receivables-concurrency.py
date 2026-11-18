"""Verify account closure and receivable row locks in a disposable offline MySQL 8."""

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
root = pathlib.Path(tempfile.mkdtemp(prefix='laravel13idc-account-receivables-mysql-'))
socket_dir = root / 'socket'
socket_dir.mkdir(mode=0o777)
socket_dir.chmod(0o777)
storage = root / 'storage'
for folder in ['framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private']:
    (storage / folder).mkdir(parents=True)
(storage / 'installed.lock').write_text('isolated account and receivable acceptance')
env = dict(os.environ, APP_ENV='testing', APP_DEBUG='false',
           APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(),
           APP_CONFIG_CACHE=str(root / 'config.php'), APP_ROUTES_CACHE=str(root / 'routes.php'),
           LARAVEL_STORAGE_PATH=str(storage), VIEW_COMPILED_PATH=str(storage / 'framework/views'),
           DB_CONNECTION='mysql', DB_URL='', DB_HOST='localhost', DB_PORT='3306',
           DB_SOCKET=str(socket_dir / 'mysqld.sock'), DB_DATABASE='account_receivables_only',
           DB_USERNAME='root', DB_PASSWORD='', CACHE_STORE='array', SESSION_DRIVER='array', QUEUE_CONNECTION='database',
           LOG_CHANNEL='single', BCRYPT_ROUNDS='4', ACCOUNT_TEST_REPO=str(repo), ACCOUNT_TEST_ROOT=str(root))
container = 'laravel13idc-account-receivables-' + uuid.uuid4().hex[:12]
(root / 'test-env.json').write_text(json.dumps(env))
(root / 'test-env.json').chmod(0o600)
print('Evidence directory: ' + str(root), flush=True)
(root / 'bootstrap.php').write_text(r'''<?php
require getenv('ACCOUNT_TEST_REPO').'/vendor/autoload.php';
$app = require getenv('ACCOUNT_TEST_REPO').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'account_receivables_only'
    || config('database.connections.mysql.unix_socket') !== getenv('ACCOUNT_TEST_ROOT').'/socket/mysqld.sock') {
    throw new RuntimeException('Only the private offline account/receivables database is authorized.');
}
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Queue::fake();
''')
(root / 'worker.php').write_text(r'''<?php
require __DIR__.'/bootstrap.php';
use App\Actions\AssignAdminRoleAction;
use App\Actions\CloseCustomerAccountAction;
use App\Actions\CreateWalletTopupInvoiceAction;
use App\Actions\ManageTicketAction;
use App\Actions\RecordAffiliateReceivableSettlementAction;
use App\Actions\RefundInvoiceAction;
use App\Actions\RequestAffiliateWithdrawalAction;
use App\Actions\ReverseAffiliateReceivableSettlementAction;
use App\Enums\TransactionType;
use App\Models\AffiliateCommission;
use App\Models\AffiliateReceivableSettlement;
use App\Models\FinancialTransaction;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AffiliateReceivableBalance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
$op=$argv[1];
$data=json_decode($argv[3] ?? '{}',true,flags:JSON_THROW_ON_ERROR);
$held = ($data['hold_user'] ?? false) === true;
if ($held) {
    DB::beginTransaction();
    User::withTrashed()->whereKey($data['user'])->lockForUpdate()->firstOrFail();
}
if (($argv[2] ?? '') !== '') {
    $barrier=__DIR__.'/'.$argv[2];
    file_put_contents($barrier.'.'.getmypid().'.ready','ready');
    $deadline=microtime(true)+20;
    while (!is_file($barrier.'.go')) {
        if (microtime(true)>$deadline) throw new RuntimeException('Account/receivable barrier timed out');
        usleep(10000);
    }
}
if ($op==='setup') {
    DB::table('system_settings')->insert(['group'=>'wallet','key'=>'topup_enabled','value'=>'1','is_encrypted'=>false]);
    foreach (['epay_api_url'=>'https://pay.example.test','epay_pid'=>'isolated','epay_key'=>'isolated-key'] as $key=>$value) {
        DB::table('system_settings')->insert(['group'=>'payment','key'=>$key,
            'value'=>$key==='epay_key'?Illuminate\Support\Facades\Crypt::encryptString($value):$value,'is_encrypted'=>$key==='epay_key']);
    }
    echo '{}';exit;
}
if ($op==='fixture') {
    $actor=User::factory()->create();
    foreach (['finance-operator','finance-receivable','finance-payout'] as $role) {
        app(AssignAdminRoleAction::class)->execute($actor,$role,'Isolated account receivable fixture');
    }
    $otherActor=User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($otherActor,'finance-receivable','Independent concurrent collector');
    $owner=User::factory()->create(['password'=>'Isolated-Account-123','credit'=>'0.00','frozen_credit'=>'0.00',
        'affiliate_balance'=>'0.00','affiliate_frozen_balance'=>'0.00']);
    $invoice=Invoice::factory()->paid()->create(['total_amount'=>'100.00','gateway_amount'=>'100.00']);
    AffiliateCommission::factory()->create(['referrer_id'=>$owner->id,'buyer_id'=>$invoice->user_id,'invoice_id'=>$invoice->id,'status'=>'available','amount'=>'10.00']);
    app(RefundInvoiceAction::class)->execute($invoice,'external_record',keepService:true,reason:'Real completed external refund',actor:$actor);
    $root=FinancialTransaction::where('user_id',$owner->id)->where('type',TransactionType::AFFILIATE_CLAWBACK)->sole();
    echo json_encode(['user'=>$owner->id,'actor'=>$actor->id,'other_actor'=>$otherActor->id,'root'=>$root->id,
        'version'=>app(AffiliateReceivableBalance::class)->project($root)['version']]);exit;
}
if ($op==='fresh_user') {
    $owner=User::factory()->create(['password'=>'Isolated-Account-123','credit'=>'0.00','frozen_credit'=>'0.00',
        'affiliate_balance'=>'0.00','affiliate_frozen_balance'=>'0.00']);
    echo json_encode(['user'=>$owner->id]);exit;
}
if ($op==='state') {
    $user=User::withTrashed()->findOrFail($data['user']);
    echo json_encode(['closed'=>$user->trashed(),'credit'=>$user->credit,'affiliate_balance'=>$user->affiliate_balance,
        'projections'=>app(AffiliateReceivableBalance::class)->forUser($user->id)->values(),
        'settlements'=>AffiliateReceivableSettlement::where('user_id',$user->id)->get(['id','amount','request_id','corrects_settlement_id','active_receipt_key','reversed_at']),
        'withdrawals'=>App\Models\AffiliateWithdrawal::where('user_id',$user->id)->count(),
        'unpaid'=>Invoice::where('user_id',$user->id)->where('status','Unpaid')->count(),
        'tickets'=>App\Models\Ticket::where('user_id',$user->id)->where('status','!=','closed')->count(),
        'anonymization_audits'=>DB::table('audit_logs')->where('action','identity.account_anonymized')->where('subject_id',$user->id)->count()]);exit;
}
if ($op==='fund') {
    User::findOrFail($data['user'])->forceFill(['affiliate_balance'=>'20.00'])->save();echo '{}';exit;
}
try {
    $result=match ($op) {
        'settle'=>app(RecordAffiliateReceivableSettlementAction::class)->execute(FinancialTransaction::findOrFail($data['root']),User::findOrFail($data['actor']),
            ['amount'=>$data['amount'] ?? '6.00','source'=>'external_received','request_id'=>$data['request'],
             'external_channel'=>'bank','external_reference'=>$data['receipt'],'received_at'=>'2026-01-01 00:00:00',
             'reason'=>'Verified isolated external receipt','confirm'=>'1','version'=>$data['version'],'corrects_settlement_id'=>$data['corrects'] ?? null])->id,
        'reverse'=>app(ReverseAffiliateReceivableSettlementAction::class)->execute(AffiliateReceivableSettlement::findOrFail($data['settlement']),User::findOrFail($data['actor']),
            ['request_id'=>$data['request'],'reason'=>'Isolated correction of completed registration','correction_reference'=>'CORRECTION-ISOLATED',
             'confirm'=>'1','version'=>$data['version']])->id,
        'close'=>app(CloseCustomerAccountAction::class)->execute(User::withTrashed()->findOrFail($data['user']),'Isolated-Account-123',true)->id,
        'topup'=>app(CreateWalletTopupInvoiceAction::class)->execute(User::withTrashed()->findOrFail($data['user']),
            ['request_id'=>$data['request'],'amount'=>'10.00','gateway'=>'epay_wechat'])->id,
        'ticket'=>app(ManageTicketAction::class)->create(User::withTrashed()->findOrFail($data['user']),['subject'=>'Concurrent support obligation','body'=>'Isolated customer request'])->id,
        'withdraw'=>app(RequestAffiliateWithdrawalAction::class)->execute(User::withTrashed()->findOrFail($data['user']),'2.00',$data['request'])->id,
    };
    if ($held) DB::commit();
    echo json_encode(['outcome'=>'accepted','id'=>$result]);
} catch (Illuminate\Validation\ValidationException $error) {
    if ($held) DB::rollBack();
    echo json_encode(['outcome'=>'rejected','fields'=>array_keys($error->errors())]);
} catch (Illuminate\Database\Eloquent\ModelNotFoundException) {
    if ($held) DB::rollBack();
    echo json_encode(['outcome'=>'rejected','fields'=>['inactive_account']]);
}
''')


def run(command, name, timeout=120):
    result = subprocess.run(command, env=env, cwd=repo, capture_output=True, text=True, timeout=timeout)
    (root / name).write_text(result.stdout + result.stderr)
    if result.returncode:
        raise RuntimeError('Account/receivable verification failed; see ' + str(root / name) + '\n' + result.stdout[-4000:] + result.stderr[-2000:])
    return result.stdout


def operation(op, payload=None, name=None):
    return json.loads(run(['php', str(root / 'worker.php'), op, '', json.dumps(payload or {})], name or op + '-' + uuid.uuid4().hex[:6] + '.log'))


def request_id():
    # Valid ULID: timestamp/random uniqueness is sufficient for isolated fixture request IDs.
    return '01' + uuid.uuid4().hex[:24].upper()


def race(calls, name, ordered_first=False):
    workers = []
    try:
        for index, (op, payload) in enumerate(calls):
            worker = subprocess.Popen(['php', str(root / 'worker.php'), op, name, json.dumps(payload)],
                                      env=env, cwd=repo, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
            workers.append(worker)
            if index == 0 and ordered_first:
                deadline = time.monotonic() + 20
                while not (root / (name + '.' + str(worker.pid) + '.ready')).exists():
                    if time.monotonic() > deadline or worker.poll() is not None:
                        raise RuntimeError('First participant failed to acquire account lock: ' + name)
                    time.sleep(.02)
        deadline = time.monotonic() + 20
        while len(list(root.glob(name + '.*.ready'))) < len(workers):
            if time.monotonic() > deadline or any(worker.poll() is not None for worker in workers):
                raise RuntimeError('Workers failed to reach barrier: ' + name)
            time.sleep(.02)
        (root / (name + '.go')).write_text('go')
        results = []
        for index, worker in enumerate(workers):
            out, err = worker.communicate(timeout=30)
            (root / (name + '-' + str(index) + '.log')).write_text(out + err)
            if worker.returncode:
                raise RuntimeError(name + ': ' + out + err)
            results.append(json.loads(out))
        return results
    finally:
        for worker in workers:
            if worker.poll() is None:
                worker.kill()
                worker.wait(timeout=5)


def one_accepted(results):
    assert sorted(row['outcome'] for row in results) == ['accepted', 'rejected'], results


evidence = {}
try:
    subprocess.run(['docker', 'run', '--detach', '--pull=never', '--name', container,
                    '--label', 'laravel13idc.review=account-receivables', '--network', 'none',
                    '--tmpfs', '/var/lib/mysql:rw,size=768m', '--mount', 'type=bind,source=' + str(socket_dir) + ',target=/var/run/mysqld',
                    '--env', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes', '--env', 'MYSQL_DATABASE=account_receivables_only',
                    image, '--performance-schema=OFF', '--innodb-buffer-pool-size=32M', '--mysqlx=OFF', '--skip-log-bin'],
                   check=True, capture_output=True, timeout=20)
    deadline = time.monotonic() + 90
    while True:
        ready = subprocess.run(['php', '-r', '$pdo = new PDO("mysql:unix_socket=".getenv("DB_SOCKET").";dbname=account_receivables_only", "root", ""); echo $pdo->query("SELECT VERSION()")->fetchColumn();'],
                               env=env, capture_output=True, text=True, timeout=5)
        stable = subprocess.run(['docker', 'exec', container, 'cat', '/proc/1/comm'], capture_output=True, text=True, timeout=5)
        if ready.returncode == 0 and stable.stdout.strip() == 'mysqld':
            break
        if time.monotonic() > deadline:
            raise RuntimeError('Dedicated offline MySQL did not become ready')
        time.sleep(.5)
    evidence['mysql_version'] = ready.stdout.strip()
    # SQLite triggers are separately checked by the feature suite; concurrency below uses actual MySQL locks.
    run(['php', 'artisan', 'migrate:fresh', '--force', '--no-interaction'], 'isolated-migration.log')
    operation('setup')

    fixture = operation('fixture')
    first = fixture | {'request': request_id(), 'receipt': 'REMAIN-A'}
    second = fixture | {'actor': fixture['other_actor'], 'request': request_id(), 'receipt': 'REMAIN-B'}
    results = race([('settle', first), ('settle', second)], 'contended-remaining')
    one_accepted(results)
    state = operation('state', fixture)
    assert state['projections'][0]['remaining'] == '4.00' and len(state['settlements']) == 1, state
    evidence['contended_remaining'] = {'results': results, 'remaining': '4.00', 'one_settlement': True}

    other = operation('fixture')
    a = fixture | {'version': state['projections'][0]['version'], 'amount': '1.00', 'request': request_id(), 'receipt': 'CROSS-OWNER-ONE'}
    b = other | {'amount': '1.00', 'request': request_id(), 'receipt': 'CROSS-OWNER-ONE'}
    results = race([('settle', a), ('settle', b)], 'same-receipt-different-owners')
    one_accepted(results)
    evidence['cross_owner_receipt'] = results

    state = operation('state', fixture)
    entry = state['settlements'][0]
    reverse = fixture | {'settlement': entry['id'], 'version': state['projections'][0]['version'], 'request': request_id()}
    results = race([('reverse', reverse), ('reverse', reverse | {'actor': fixture['other_actor'], 'request': request_id()})], 'one-complete-reversal')
    one_accepted(results)
    state = operation('state', fixture)
    assert state['projections'][0]['reversed'] == '6.00', state
    evidence['one_reversal'] = results
    corrected = fixture | {'amount': '3.00', 'version': state['projections'][0]['version'], 'request': request_id(),
                           'receipt': '', 'corrects': entry['id']}
    # Select the actual first receipt from the original winner, independent of process order.
    corrected['receipt'] = 'REMAIN-A' if evidence['contended_remaining']['results'][0]['outcome'] == 'accepted' else 'REMAIN-B'
    results = race([('settle', corrected), ('settle', corrected | {'actor': fixture['other_actor'], 'request': request_id()})], 'unbranched-correction')
    one_accepted(results)
    evidence['unbranched_correction'] = results

    paid = operation('fixture')
    paid_receipt = paid | {'amount': '10.00', 'request': request_id(), 'receipt': 'CLOSE-COLLECTED'}
    entry = operation('settle', paid_receipt)
    state = operation('state', paid)
    reverse = paid | {'settlement': entry['id'], 'version': state['projections'][0]['version'], 'request': request_id()}
    results = race([('close', paid), ('reverse', reverse)], 'closure-versus-reversal')
    assert results[1]['outcome'] == 'accepted', results
    state = operation('state', paid)
    assert state['projections'][0]['remaining'] == '10.00', state
    assert state['closed'] == (results[0]['outcome'] == 'accepted'), state
    evidence['closure_versus_reversal'] = {'results': results, 'state': state}

    collected = operation('fixture')
    operation('fund', collected)
    receipt = collected | {'amount': '10.00', 'request': request_id(), 'receipt': 'COLLECTION-WITHDRAW'}
    results = race([('settle', receipt), ('withdraw', collected | {'request': request_id()})], 'collection-versus-withdrawal')
    assert results[0]['outcome'] == 'accepted', results
    state = operation('state', collected)
    assert state['projections'][0]['remaining'] == '0.00', state
    assert state['withdrawals'] == (1 if results[1]['outcome'] == 'accepted' else 0), state
    evidence['collection_versus_withdrawal'] = results

    for obligation in ['topup', 'ticket']:
        user = operation('fresh_user')
        results = race([('close', user), (obligation, user | {'request': request_id()})], 'closure-versus-' + obligation)
        one_accepted(results)
        state = operation('state', user)
        assert state['closed'] == (results[0]['outcome'] == 'accepted'), state
        assert state['unpaid' if obligation == 'topup' else 'tickets'] == (0 if state['closed'] else 1), state
        assert state['anonymization_audits'] == (1 if state['closed'] else 0), state
        evidence['closure_versus_' + obligation] = {'results': results, 'state': state}

    for obligation in ['topup', 'ticket']:
        for first in ['close', obligation]:
            user = operation('fresh_user')
            second = obligation if first == 'close' else 'close'
            results = race([(first, user | {'request': request_id(), 'hold_user': True}),
                            (second, user | {'request': request_id()})], 'ordered-' + first + '-before-' + second, ordered_first=True)
            one_accepted(results)
            assert results[0]['outcome'] == 'accepted', results
            state = operation('state', user)
            assert state['closed'] == (first == 'close'), state
            assert state['unpaid' if obligation == 'topup' else 'tickets'] == (0 if state['closed'] else 1), state
            evidence['ordered_' + first + '_before_' + second] = {'results': results, 'state': state}

    user = operation('fresh_user')
    results = race([('close', user), ('close', user)], 'same-account-closure')
    assert all(result['outcome'] == 'accepted' for result in results), results
    assert operation('state', user)['anonymization_audits'] == 1
    evidence['one_effect_closure'] = results
    (root / 'evidence.json').write_text(json.dumps(evidence, indent=2))
    print(json.dumps(evidence, indent=2), flush=True)
    print('PASS: MySQL remaining/receipt/reversal/correction uniqueness; closure with reversal/topup/ticket; withdrawal visibility; one-effect closure.', flush=True)
finally:
    logs = subprocess.run(['docker', 'logs', container], capture_output=True, text=True, timeout=10)
    (root / 'mysql-container.log').write_text(logs.stdout + logs.stderr)
    subprocess.run(['docker', 'rm', '--force', container], capture_output=True, timeout=20)
    print('Dedicated container removed; retained evidence: ' + str(root), flush=True)
