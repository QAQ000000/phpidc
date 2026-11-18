"""Verify W2 payouts on disposable offline MySQL and an optional read-only preview copy.

Requires PHP 8.4 and an already cached mysql:8.0.35 Docker image. No database,
password, socket or remote endpoint may be supplied. All money is synthetic;
HTTP/mail/queues are faked and the MySQL container has no network or host ports.
--preview-pid copies its SQLite database with a read-only connection and never
migrates the original. Private evidence, backups and generated keys stay in /tmp.
"""

import argparse
import base64
import hashlib
import json
import os
import pathlib
import shutil
import sqlite3
import subprocess
import tempfile
import time
import uuid


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--preview-pid', type=int)
    args = parser.parse_args()
    os.umask(0o077)
    repo = pathlib.Path(__file__).resolve().parents[1]
    php = '/www/server/php/84/bin/php'
    image = 'mysql:8.0.35'
    migrations = sorted((repo / 'database/migrations').glob('*.php'))
    legacy = [path for path in migrations if path.name < '2026_11_18_000009']
    upgrade = [path for path in migrations if path.name >= '2026_11_18_000009']
    if len(legacy) != 14 or [path.name[11:17] for path in upgrade] != ['000009', '000010']:
        raise RuntimeError('Expected fourteen legacy migrations and only W2 migrations 000009/000010.')
    subprocess.run(['docker', 'image', 'inspect', image], check=True, capture_output=True, timeout=10)
    root = pathlib.Path(tempfile.mkdtemp(prefix='laravel13idc-wallet-receipts-'))
    print('Private evidence: ' + str(root), flush=True)
    socket_dir = root / 'socket'
    socket_dir.mkdir(mode=0o777)
    socket_dir.chmod(0o777)
    for folder, files in [('legacy-migrations', legacy), ('upgrade-migrations', upgrade)]:
        (root / folder).mkdir()
        for path in files:
            shutil.copyfile(path, root / folder / path.name)
    container = 'laravel13idc-wallet-' + uuid.uuid4().hex[:12]
    clean = {key: value for key, value in os.environ.items()
             if not key.startswith(('APP_', 'DB_', 'REDIS_', 'LARAVEL_', 'VIEW_', 'QUEUE_',
                                    'CACHE_', 'SESSION_', 'MAIL_', 'LOG_', 'BROADCAST_'))}
    password = 'Offline-' + os.urandom(16).hex() + '!'
    base = dict(clean, APP_ENV='testing', APP_DEBUG='false', APP_URL='https://offline.example.test',
                BCRYPT_ROUNDS='4', CACHE_STORE='array', SESSION_DRIVER='array', QUEUE_CONNECTION='database',
                MAIL_MAILER='array', LOG_CHANNEL='single', DB_URL='', DB_CONNECTION='mysql',
                DB_HOST='localhost', DB_PORT='3306', DB_SOCKET=str(socket_dir / 'mysqld.sock'),
                DB_USERNAME='wallet_fixture', DB_PASSWORD=password, DB_DATABASE='wallet_receipts_only',
                W2_REPO=str(repo), W2_ROOT=str(root), W2_PASSWORD=password)

    def environment(name, **extra):
        location = root / name
        for folder in ['framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private', 'public']:
            (location / folder).mkdir(parents=True, exist_ok=True)
        for asset in ['js/filament/filament/app.js', 'css/filament/filament/app.css', 'js/classic-config.js']:
            target = location / 'public' / asset
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(repo / 'public' / asset, target)
        env = dict(base, APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(),
                   LARAVEL_STORAGE_PATH=str(location), VIEW_COMPILED_PATH=str(location / 'framework/views'),
                   APP_CONFIG_CACHE=str(location / 'config.php'), APP_ROUTES_CACHE=str(location / 'routes.php'),
                   APP_EVENTS_CACHE=str(location / 'events.php'), APP_SERVICES_CACHE=str(location / 'services.php'),
                   APP_PACKAGES_CACHE=str(location / 'packages.php'))
        env.update(extra)
        return env

    harness = root / 'acceptance.php'
    harness.write_text(r'''<?php
declare(strict_types=1);
require getenv('W2_REPO').'/vendor/autoload.php';
$app = require getenv('W2_REPO').'/bootstrap/app.php';
$app->usePublicPath(storage_path('public'));
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function(Throwable $error):void {fwrite(STDERR,(string)$error.PHP_EOL);exit(20);});
use App\Models\User;
use App\Models\FinancialTransaction;
use App\Models\AffiliateWithdrawal;
use App\Models\WalletClearance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
function demand(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$connection=DB::connection();
if($connection->getDriverName()==='mysql') {
    demand($connection->getDatabaseName()==='wallet_receipts_only'
        &&$connection->getConfig('unix_socket')===getenv('W2_ROOT').'/socket/mysqld.sock','Private MySQL only');
} else demand($connection->getDriverName()==='sqlite'
    &&$connection->getDatabaseName()===getenv('W2_ROOT').'/preview-copy/database.sqlite','Private SQLite copy only');
Http::preventStrayRequests();
Http::fake(fn()=>throw new RuntimeException('External HTTP is forbidden'));
Illuminate\Support\Facades\Mail::fake();
Illuminate\Support\Facades\Queue::fake();
$mode=$argv[1];$case=storage_path();
function tables():array {return Schema::getTableListing(DB::getDriverName()==='mysql'?DB::getDatabaseName():'main',false);}
function manifest(?array $old=null):array {
    $result=[];$tables=$old===null?tables():array_keys($old);sort($tables);
    foreach($tables as $table) {
        if($table==='migrations')continue;
        $columns=$old[$table]['columns']??Schema::getColumnListing($table);$rows=[];
        foreach(DB::table($table)->get($columns) as $row)$rows[]=json_encode((array)$row,JSON_THROW_ON_ERROR);
        sort($rows,SORT_STRING);
        $result[$table]=['columns'=>$columns,'count'=>count($rows),'sha256'=>hash('sha256',implode("\n",$rows))];
    }return $result;
}
function migrate(string $path):void {
    $m=app(Illuminate\Database\Migrations\Migrator::class);
    if(!$m->repositoryExists())$m->getRepository()->createRepository();
    $m->run([$path],['force'=>true]);
}
function operation(string $name,array $extra=[]):array {
    return array_replace(['request_id'=>(string)Str::ulid(),'operation'=>$name,
        'reason'=>'Offline shared receipt acceptance','confirm'=>'1'],$extra);
}
if($mode==='baseline') {
    demand(tables()===[],'Baseline requires its empty private database');
    migrate(getenv('W2_ROOT').'/legacy-migrations');
    $user=User::factory()->create(['credit'=>'30.00','frozen_credit'=>'2.00','affiliate_balance'=>'7.50']);
    $admin=User::factory()->create();
    app(App\Actions\AssignAdminRoleAction::class)->execute($admin,'access-manager','Offline baseline');
    $invoice=App\Models\Invoice::factory()->paid()->create(['user_id'=>$user->id]);
    FinancialTransaction::create(['user_id'=>$user->id,'invoice_id'=>$invoice->id,'type'=>App\Enums\TransactionType::PAYMENT,
        'amount'=>'10.00','balance_after'=>'30.00','frozen_after'=>'2.00','reference_key'=>'w2:historical:payment','meta'=>['currency'=>'CNY']]);
    $state=app(App\Support\InstallationState::class);
    $state->write('installed.lock',$state->seal(['format'=>1,'admin_id'=>$admin->id],config('app.key')));
    echo json_encode(['migrations'=>DB::table('migrations')->count(),'synthetic_baseline'=>true]);
} elseif($mode==='checkpoint') {
    demand(DB::table('migrations')->count()===14&&!Schema::hasTable('wallet_clearances'),'Expected W1 schema');
    $before=manifest();file_put_contents($case.'/before.json',json_encode($before,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    app(App\Support\InstallationState::class)->assertKey(requireSeal:true);
    echo json_encode(['tables'=>count($before),'rows'=>array_sum(array_column($before,'count')),'migrations'=>14]);
} elseif($mode==='upgrade') {
    migrate(getenv('W2_ROOT').'/upgrade-migrations');
    $before=json_decode(file_get_contents($case.'/before.json'),true,flags:JSON_THROW_ON_ERROR);
    demand(manifest($before)===$before,'Legacy columns or rows changed');
    demand(DB::table('migrations')->count()===16,'Expected sixteen migrations');
    $new=['reconciliation_cases','wallet_clearances','external_payout_receipts','reconciliation_entries','instance_entitlement_archives'];
    $expected=array_merge(array_keys($before),['migrations'],$new);sort($expected);$actual=tables();sort($actual);
    demand($actual===$expected,'Unexpected schema additions/deletions');
    foreach($new as $table)demand(DB::table($table)->count()===0,'Migration inserted business data');
    if(DB::getDriverName()==='sqlite') {
        demand(DB::select('PRAGMA foreign_key_check')===[],'SQLite FK integrity failed');
        demand(DB::select('PRAGMA quick_check')[0]->quick_check==='ok','SQLite integrity failed');
    } else foreach($actual as $table)demand(DB::select('CHECK TABLE `'.$table.'`')[0]->Msg_text==='OK','MySQL integrity failed');
    demand(Artisan::call('app:deployment-check')===0,'Deployment check failed: '.Artisan::output());
    file_put_contents($case.'/after.json',json_encode(manifest(),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo json_encode(['old_tables_preserved'=>count($before),'old_rows_preserved'=>array_sum(array_column($before,'count')),
        'migrations'=>16,'new_tables'=>$new,'integrity'=>'PASS','deployment_check'=>'PASS']);
} elseif($mode==='setup') {
    $affiliateOwner=User::factory()->create(['affiliate_balance'=>'30.00']);$affiliateActor=User::factory()->create();
    $walletOwner=User::factory()->create(['password'=>getenv('W2_PASSWORD'),'credit'=>'25.00']);$walletActor=User::factory()->create();
    foreach(['external-payout-reviewer','external-payout-operator','external-payout-confirmer'] as $role)
        app(App\Actions\AssignAdminRoleAction::class)->execute($affiliateActor,$role,'Offline affiliate authority');
    foreach(['wallet-clearance-reviewer','wallet-clearance-operator','wallet-clearance-confirmer'] as $role)
        app(App\Actions\AssignAdminRoleAction::class)->execute($walletActor,$role,'Offline wallet authority');
    $input=['request_id'=>(string)Str::ulid(),'amount'=>'10.00','payout_method'=>'alipay',
        'payee_name'=>'Offline payee','payee_account'=>'SIMULATED-ONLY','current_password'=>getenv('W2_PASSWORD'),'confirm'=>'1'];
    $affiliate=app(App\Actions\RequestExternalAffiliateWithdrawalAction::class)->execute($affiliateOwner,$input);
    $wallet=app(App\Actions\RequestWalletClearanceAction::class)->execute($walletOwner,$input);
    foreach(['approve','start'] as $op) {
        $affiliate=app(App\Actions\ProcessExternalAffiliateWithdrawalAction::class)->execute($affiliate,$affiliateActor,operation($op));
        $wallet=app(App\Actions\ProcessWalletClearanceAction::class)->execute($wallet,$walletActor,operation($op));
    }
    file_put_contents($case.'/race.json',json_encode(['affiliate'=>$affiliate->id,'affiliate_owner'=>$affiliateOwner->id,
        'affiliate_actor'=>$affiliateActor->id,'wallet'=>$wallet->id,'wallet_owner'=>$walletOwner->id,'wallet_actor'=>$walletActor->id],JSON_THROW_ON_ERROR));
    demand(count(array_unique([$affiliateOwner->id,$affiliateActor->id,$walletOwner->id,$walletActor->id]))===4,'Race participants overlap');
    echo json_encode(['independent_owners_and_actors'=>4,'processing_sources'=>2,'frozen_per_source'=>'10.00']);
} elseif($mode==='race') {
    $kind=$argv[2];demand(in_array($kind,['affiliate','wallet'],true),'Invalid race kind');
    $data=json_decode(file_get_contents($case.'/race.json'),true,flags:JSON_THROW_ON_ERROR);$attempts=0;$barrier=false;
    DB::connection()->beforeExecuting(function(string $query)use(&$attempts):void {
        if(str_starts_with(strtolower($query),'insert into `external_payout_receipts`'))$attempts++;
    });
    DB::listen(function(Illuminate\Database\Events\QueryExecuted $query)use($case,$kind,&$barrier):void {
        if(!$barrier&&str_contains($query->sql,'`wallet_clearances`')&&str_contains($query->sql,'UPPER(TRIM(external_reference))')
            &&in_array('W2-SHARED-RECEIPT',$query->bindings,true)) {
            $barrier=true;file_put_contents($case.'/race-'.$kind.'.ready',(string)getmypid());$deadline=microtime(true)+20;
            while(!is_file($case.'/race.go')){demand(microtime(true)<$deadline,'Concurrent barrier timed out');usleep(10000);}
        }
    });
    $source=$kind==='affiliate'?AffiliateWithdrawal::findOrFail($data[$kind]):WalletClearance::findOrFail($data[$kind]);
    $actor=User::findOrFail($data[$kind.'_actor']);
    $action=app($kind==='affiliate'?App\Actions\ProcessExternalAffiliateWithdrawalAction::class:App\Actions\ProcessWalletClearanceAction::class);
    $result='paid';
    try {$action->execute($source,$actor,operation('paid',['intent_key'=>$source->payout_intent_key,'evidence'=>'SIMULATED-PAID-EVIDENCE',
        'external_reference'=>' w2-shared-receipt ','confirmed_amount'=>'10.00','currency'=>'CNY']));}
    catch(Illuminate\Validation\ValidationException $error) {$result='validation_conflict';}
    demand($barrier&&$attempts===1,'Each worker must pass prechecks and attempt one real INSERT');
    Http::assertNothingSent();echo json_encode(['kind'=>$kind,'pid'=>getmypid(),'precheck_barrier'=>true,'registry_insert_attempts'=>$attempts,'result'=>$result]);
} elseif($mode==='race-verify') {
    $data=json_decode(file_get_contents($case.'/race.json'),true,flags:JSON_THROW_ON_ERROR);
    $sources=['affiliate'=>AffiliateWithdrawal::findOrFail($data['affiliate']),'wallet'=>WalletClearance::findOrFail($data['wallet'])];
    $paid=array_keys(array_filter($sources,fn($source)=>$source->status==='paid'));
    demand(count($paid)===1,'Exactly one cross-business payment must succeed');$winner=$paid[0];$loser=$winner==='affiliate'?'wallet':'affiliate';
    demand($sources[$loser]->status==='processing','Losing source changed its state');
    demand(DB::table('external_payout_receipts')->count()===1,'Registry receipt count incorrect');
    $receipt=DB::table('external_payout_receipts')->sole();
    demand($receipt->external_reference==='W2-SHARED-RECEIPT'&&$receipt->amount==='10.00','Receipt changed');
    demand(($winner==='affiliate'?$receipt->affiliate_withdrawal_id:$receipt->wallet_clearance_id)===$data[$winner],'Registry winner mismatch');
    $keys=['affiliate'=>'payout:'.$data['affiliate'],'wallet'=>'wallet_clearance:'.$data['wallet'].':paid'];
    foreach($sources as $kind=>$source) {
        $owner=User::findOrFail($data[$kind.'_owner']);$frozen=$kind==='affiliate'?$owner->affiliate_frozen_balance:$owner->wallet_clearance_frozen;
        demand($frozen===($kind===$winner?'0.00':'10.00'),'Frozen funds mismatch');
        demand(FinancialTransaction::where('reference_key',$keys[$kind])->count()===($kind===$winner?1:0),'Paid ledger mismatch');
    }
    demand(Schema::hasIndex('external_payout_receipts','external_payout_receipt_unique'),'Unique receipt index absent');
    demand(DB::table('jobs')->count()===0,'No queue jobs may run or remain in this acceptance');
    echo json_encode(['winner'=>$winner,'loser'=>$loser,'receipts'=>1,'paid_ledgers'=>1,'losing_source'=>'processing',
        'losing_frozen'=>'10.00','unique_index'=>'external_payout_receipt_unique','real_external_actions'=>0]);
} elseif($mode==='check') {
    $data=json_decode(file_get_contents($case.'/race.json'),true,flags:JSON_THROW_ON_ERROR);$errors=[];
    foreach([false,true] as $both) {
        try {DB::table('external_payout_receipts')->insert(['payout_method'=>'bank_transfer','external_reference'=>'INVALID-'.($both?'BOTH':'NONE'),
            'amount'=>'1.00','currency'=>'CNY','confirmed_by'=>$data['wallet_actor'],'affiliate_withdrawal_id'=>$both?$data['affiliate']:null,
            'wallet_clearance_id'=>$both?$data['wallet']:null,'created_at'=>now()]);throw new RuntimeException('Invalid owner accepted');}
        catch(Illuminate\Database\QueryException $error) {
            demand(($error->errorInfo[1]??null)===3819&&str_contains($error->getMessage(),'external_payout_receipt_one_owner'),'Expected actual MySQL CHECK 3819');
            $errors[$both?'both_non_null':'both_null']=3819;
        }
    }
    echo json_encode(['owner_check'=>$errors,'constraint'=>'external_payout_receipt_one_owner']);
} elseif($mode==='refund-json') {
    $owner=User::factory()->create(['deleted_at'=>now(),'anonymized_at'=>now()]);$actor=User::factory()->create();
    app(App\Actions\AssignAdminRoleAction::class)->execute($actor,'finance-operator','Offline legal anonymous refund');
    $invoice=App\Models\Invoice::factory()->paid()->create(['user_id'=>$owner->id,'total_amount'=>'12.34','gateway_amount'=>'12.34']);
    $credit=app(App\Actions\RefundInvoiceAction::class)->execute($invoice,'refund_wallet',true,'Verified original anonymous refund',$actor);
    $reloaded=$credit->fresh();$case=App\Models\ReconciliationCase::where('source_credit_transaction_id',$credit->id)->sole()->fresh();
    demand($credit->meta!==$reloaded->meta,'Fixture must demonstrate real MySQL JSON key reordering');
    demand(app(App\Services\PaymentAttentionCases::class)->assertSource($case)->id===$credit->id,'Reloaded refund source rejected');
    demand(app(App\Services\PaymentAttentionCases::class)->creditFingerprint($credit)===app(App\Services\PaymentAttentionCases::class)->creditFingerprint($reloaded),'Fingerprint changed after MySQL JSON persistence');
    demand(User::withTrashed()->findOrFail($owner->id)->credit==='12.34','Original anonymous identity lost refund');
    Http::assertNothingSent();echo json_encode(['native_refund_action'=>'PASS','mysql_json_key_order_changed'=>true,
        'reloaded_case_assert_source'=>'PASS','stable_source_fingerprint'=>'PASS','original_identity_credit'=>'12.34']);
} else throw new RuntimeException('Unsupported mode');
echo PHP_EOL;
''')

    def run(env, mode, filename):
        process = subprocess.run([php, '-d', 'memory_limit=512M', str(harness), mode],
                                 cwd=repo, env=env, capture_output=True, text=True, timeout=120)
        (root / filename).write_text(process.stdout + process.stderr)
        if process.returncode:
            raise RuntimeError(mode + ' failed; see ' + str(root / filename))
        print(mode + ': ' + process.stdout.strip(), flush=True)
        return json.loads(process.stdout)

    def sql(text):
        subprocess.run(['docker', 'exec', '-i', container, 'mysql', '-uroot'], input=text.encode(),
                       check=True, capture_output=True, timeout=30)

    original = None
    try:
        subprocess.run(['docker', 'run', '--detach', '--pull=never', '--name', container,
                        '--label', 'laravel13idc.review=wallet-receipts', '--network', 'none',
                        '--tmpfs', '/var/lib/mysql:rw,size=768m', '--mount',
                        'type=bind,source=' + str(socket_dir) + ',target=/var/run/mysqld',
                        '--env', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes', image, '--performance-schema=OFF',
                        '--innodb-buffer-pool-size=32M', '--mysqlx=OFF', '--skip-log-bin'],
                       check=True, capture_output=True, timeout=20)
        deadline = time.monotonic() + 90
        while True:
            probe = subprocess.run(['docker', 'exec', container, 'mysql', '-uroot', '-Nse', 'SELECT VERSION()'],
                                   capture_output=True, text=True, timeout=5)
            process = subprocess.run(['docker', 'exec', container, 'cat', '/proc/1/comm'],
                                     capture_output=True, text=True, timeout=5)
            if probe.returncode == 0 and process.stdout.strip() == 'mysqld':
                break
            if time.monotonic() >= deadline:
                raise RuntimeError('Offline MySQL did not become ready')
            time.sleep(0.5)
        inspection = json.loads(subprocess.run(['docker', 'inspect', container], check=True,
                                               capture_output=True, text=True, timeout=10).stdout)[0]
        if inspection['HostConfig']['NetworkMode'] != 'none' or inspection['HostConfig']['PortBindings']:
            raise RuntimeError('MySQL isolation failed')
        sql("CREATE DATABASE wallet_receipts_only; CREATE USER 'wallet_fixture'@'localhost' IDENTIFIED BY '" + password
            + "'; GRANT ALL ON wallet_receipts_only.* TO 'wallet_fixture'@'localhost';")
        mysql_env = environment('mysql')
        run(mysql_env, 'baseline', 'mysql-baseline.log')
        run(mysql_env, 'checkpoint', 'mysql-checkpoint.log')
        backup = subprocess.run(['docker', 'exec', container, 'mysqldump', '-uroot', '--single-transaction',
                                 '--routines', '--triggers', '--events', '--hex-blob', '--no-tablespaces',
                                 'wallet_receipts_only'], check=True, capture_output=True, timeout=30)
        (root / 'mysql-w1-checkpoint.sql').write_bytes(backup.stdout)
        preservation = run(mysql_env, 'upgrade', 'mysql-preservation.log')
        run(mysql_env, 'setup', 'mysql-race-setup.log')
        workers = {kind: subprocess.Popen([php, '-d', 'memory_limit=512M', str(harness), 'race', kind],
                                          cwd=repo, env=mysql_env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
                   for kind in ['affiliate', 'wallet']}
        outcomes = []
        try:
            deadline = time.monotonic() + 20
            while not all((root / 'mysql' / ('race-' + kind + '.ready')).exists() for kind in workers):
                if time.monotonic() >= deadline or any(worker.poll() is not None for worker in workers.values()):
                    for kind, worker in workers.items():
                        if worker.poll() is not None:
                            output, error = worker.communicate()
                            (root / ('mysql-race-' + kind + '.log')).write_text(output + error)
                    raise RuntimeError('Workers failed before shared receipt INSERT barrier')
                time.sleep(0.01)
            (root / 'mysql/race.go').touch()
            for kind, worker in workers.items():
                output, error = worker.communicate(timeout=30)
                (root / ('mysql-race-' + kind + '.log')).write_text(output + error)
                if worker.returncode:
                    raise RuntimeError('Race worker failed: ' + kind)
                outcomes.append(json.loads(output))
            if len({outcome['pid'] for outcome in outcomes}) != 2 or sorted(outcome['result'] for outcome in outcomes) != ['paid', 'validation_conflict']:
                raise RuntimeError('Expected two independent processes and exactly one conflict')
        finally:
            for worker in workers.values():
                if worker.poll() is None:
                    worker.terminate()
                    worker.communicate(timeout=5)
        race = run(mysql_env, 'race-verify', 'mysql-race-result.log')
        checks = run(mysql_env, 'check', 'mysql-owner-check.log')
        refund = run(mysql_env, 'refund-json', 'mysql-refund-json.log')
        if args.preview_pid:
            proc = pathlib.Path('/proc') / str(args.preview_pid)
            preview = dict(part.split('=', 1) for part in (proc / 'environ').read_bytes().decode().split('\0') if '=' in part)
            if preview.get('DB_CONNECTION') != 'sqlite' or (proc / 'cwd').resolve() != repo / 'public' or (proc / 'exe').resolve() != pathlib.Path(php):
                raise RuntimeError('Source PID is not the expected PHP SQLite preview')
            source = pathlib.Path(preview['DB_DATABASE']).resolve()
            seal = pathlib.Path(preview['LARAVEL_STORAGE_PATH']) / 'installed.lock'
            original = {'pid': args.preview_pid, 'database_sha256_before': digest(source),
                        'seal_sha256_before': digest(seal), 'env_sha256_before': digest(repo / '.env')}
            copied_env = environment('preview-copy', DB_CONNECTION='sqlite', DB_DATABASE=str(root / 'preview-copy/database.sqlite'),
                                     DB_SOCKET='', APP_KEY=preview['APP_KEY'])
            with sqlite3.connect(source.as_uri() + '?mode=ro', uri=True) as read_only:
                original['migrations_before'] = [row[0] for row in read_only.execute('SELECT migration FROM migrations ORDER BY id')]
                with sqlite3.connect(copied_env['DB_DATABASE']) as target:
                    read_only.backup(target)
            shutil.copyfile(seal, root / 'preview-copy/installed.lock')
            run(copied_env, 'checkpoint', 'preview-copy-checkpoint.log')
            original['copy_upgrade'] = run(copied_env, 'upgrade', 'preview-copy-preservation.log')
            original.update(database_sha256_after=digest(source), seal_sha256_after=digest(seal),
                            env_sha256_after=digest(repo / '.env'), pid_alive=proc.exists())
            with sqlite3.connect(source.as_uri() + '?mode=ro', uri=True) as read_only:
                original['migrations_after'] = [row[0] for row in read_only.execute('SELECT migration FROM migrations ORDER BY id')]
            original['unchanged'] = all(original[key + '_before'] == original[key + '_after']
                                        for key in ['database_sha256', 'seal_sha256', 'env_sha256', 'migrations'])
            if not original['unchanged'] or not original['pid_alive']:
                raise RuntimeError('Original preview changed during copy rehearsal')
        result = {'status': 'PASS', 'php': php, 'mysql_version': probe.stdout.strip(), 'mysql_network': 'none',
                  'migration_files': {path.name: digest(path) for path in upgrade}, 'mysql_preservation': preservation,
                  'cross_business_workers': outcomes, 'cross_business_unique_arbitration': race, 'mysql_check': checks,
                  'legal_anonymous_refund_json': refund, 'preview_read_only_copy': original,
                  'original_preview_migrated': False, 'real_external_actions': 0}
        (root / 'result.json').write_text(json.dumps(result, indent=2))
        print('PASS: offline MySQL uniqueness/CHECK/JSON and W2 incremental upgrade.', flush=True)
    finally:
        logs = subprocess.run(['docker', 'logs', container], capture_output=True, text=True, timeout=10)
        (root / 'mysql-container.log').write_text(logs.stdout + logs.stderr)
        removed = subprocess.run(['docker', 'rm', '--force', container], capture_output=True, timeout=20)
        (root / 'cleanup.json').write_text(json.dumps({'container': container, 'removed': removed.returncode == 0}))
        for path in root.rglob('*'):
            if path.is_file():
                path.chmod(0o600)
        print('Disposable container removed; evidence retained: ' + str(root), flush=True)


if __name__ == '__main__':
    main()
