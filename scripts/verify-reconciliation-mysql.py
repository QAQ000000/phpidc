"""Verify W3 reconciliation on disposable offline MySQL and an optional read-only preview copy.

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
    legacy = [path for path in migrations if path.name < '2026_11_18_000011']
    upgrade = [path for path in migrations if '2026_11_18_000011' <= path.name < '2026_11_18_000013']
    if len(legacy) != 16 or [path.name[11:17] for path in upgrade] != ['000011', '000012']:
        raise RuntimeError('Expected sixteen legacy migrations and only W3 migrations 000011/000012.')
    subprocess.run(['docker', 'image', 'inspect', image], check=True, capture_output=True, timeout=10)
    root = pathlib.Path(tempfile.mkdtemp(prefix='laravel13idc-reconciliation-'))
    print('Private evidence: ' + str(root), flush=True)
    socket_dir = root / 'socket'
    socket_dir.mkdir(mode=0o777)
    socket_dir.chmod(0o777)
    for folder, files in [('legacy-migrations', legacy), ('upgrade-migrations', upgrade)]:
        (root / folder).mkdir()
        for path in files:
            shutil.copyfile(path, root / folder / path.name)
    container = 'laravel13idc-reconciliation-' + uuid.uuid4().hex[:12]
    clean = {key: value for key, value in os.environ.items()
             if not key.startswith(('APP_', 'DB_', 'REDIS_', 'LARAVEL_', 'VIEW_', 'QUEUE_',
                                    'CACHE_', 'SESSION_', 'MAIL_', 'LOG_', 'BROADCAST_'))}
    password = 'Offline-' + os.urandom(16).hex() + '!'
    base = dict(clean, APP_ENV='testing', APP_DEBUG='false', APP_URL='https://offline.example.test',
                BCRYPT_ROUNDS='4', CACHE_STORE='array', SESSION_DRIVER='array', QUEUE_CONNECTION='database',
                MAIL_MAILER='array', LOG_CHANNEL='single', DB_URL='', DB_CONNECTION='mysql',
                DB_HOST='localhost', DB_PORT='3306', DB_SOCKET=str(socket_dir / 'mysqld.sock'),
                DB_USERNAME='reconciliation_fixture', DB_PASSWORD=password, DB_DATABASE='reconciliation_only',
                W3_REPO=str(repo), W3_ROOT=str(root), W3_PASSWORD=password)

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
require getenv('W3_REPO').'/vendor/autoload.php';
$app=require getenv('W3_REPO').'/bootstrap/app.php';
$app->usePublicPath(storage_path('public'));
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function(Throwable $error):void {fwrite(STDERR,(string)$error.PHP_EOL);exit(20);});
use App\Models\User;
use App\Models\Invoice;
use App\Models\PaymentCallback;
use App\Models\FinancialTransaction;
use App\Models\ReconciliationCase;
use App\Models\ReconciliationEntry;
use App\Models\FulfillmentTask;
use App\Models\InstanceUpstreamBinding;
use App\Models\InstanceMappingReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
function demand(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
$c=DB::connection();
if($c->getDriverName()==='mysql')demand($c->getDatabaseName()==='reconciliation_only'
    &&$c->getConfig('unix_socket')===getenv('W3_ROOT').'/socket/mysqld.sock','Private MySQL only');
else demand($c->getDriverName()==='sqlite'&&$c->getDatabaseName()===getenv('W3_ROOT').'/preview-copy/database.sqlite','Private SQLite copy only');
Http::preventStrayRequests();Http::fake(fn()=>throw new RuntimeException('External HTTP forbidden'));
Illuminate\Support\Facades\Mail::fake();Illuminate\Support\Facades\Queue::fake();
$mode=$argv[1];$location=storage_path();
function tables():array {return Schema::getTableListing(DB::getDriverName()==='mysql'?DB::getDatabaseName():'main',false);}
function manifest(?array $old=null):array {
    $result=[];$tables=$old===null?tables():array_keys($old);sort($tables);
    foreach($tables as $table){if($table==='migrations')continue;
        $columns=$old[$table]['columns']??Schema::getColumnListing($table);$rows=[];
        foreach(DB::table($table)->get($columns) as $row)$rows[]=json_encode((array)$row,JSON_THROW_ON_ERROR);
        sort($rows,SORT_STRING);$result[$table]=['columns'=>$columns,'count'=>count($rows),'sha256'=>hash('sha256',implode("\n",$rows))];
    }return $result;
}
function migrate(string $path):void {$m=app(Illuminate\Database\Migrations\Migrator::class);if(!$m->repositoryExists())$m->getRepository()->createRepository();$m->run([$path],['force'=>true]);}
function op(string $name,array $extra=[]):array {return array_replace(['request_id'=>(string)Str::ulid(),'operation'=>$name,'reason'=>'Offline W3 acceptance','confirm'=>'1'],$extra);}
if($mode==='baseline'){
    demand(tables()===[],'Baseline must be empty');migrate(getenv('W3_ROOT').'/legacy-migrations');
    $owner=User::factory()->create(['credit'=>'30.00','frozen_credit'=>'2.00','affiliate_balance'=>'7.50']);
    $admin=User::factory()->create();app(App\Actions\AssignAdminRoleAction::class)->execute($admin,'access-manager','Offline baseline');
    $invoice=Invoice::factory()->paid()->create(['user_id'=>$owner->id,'gateway'=>'epay_wechat']);
    FinancialTransaction::create(['user_id'=>$owner->id,'invoice_id'=>$invoice->id,'type'=>App\Enums\TransactionType::PAYMENT,
        'amount'=>'10.00','balance_after'=>'30.00','frozen_after'=>'2.00','reference_key'=>'w3:historical:payment','meta'=>['currency'=>'CNY']]);
    $instance=App\Models\Instance::factory()->create(['user_id'=>$owner->id]);
    InstanceUpstreamBinding::create(['instance_id'=>$instance->id,'driver_type'=>$instance->driver_type,'bound_at'=>now()]);
    $callback=PaymentCallback::factory()->create(['invoice_id'=>$invoice->id]);
    ReconciliationCase::create(['user_id'=>$owner->id,'payment_callback_id'=>$callback->id,'kind'=>'payment_attention',
        'source_type'=>'payment_callback','source_id'=>(string)$callback->id,
        'source_fingerprint'=>app(App\Services\PaymentAttentionCases::class)->callbackFingerprint($callback,$invoice),'initial_reason'=>'wallet_capacity']);
    $state=app(App\Support\InstallationState::class);$state->write('installed.lock',$state->seal(['format'=>1,'admin_id'=>$admin->id],config('app.key')));
    echo json_encode(['migrations'=>DB::table('migrations')->count(),'synthetic_legacy_binding_and_case'=>true]);
}elseif($mode==='checkpoint'){
    demand(DB::table('migrations')->count()===16&&!Schema::hasTable('instance_mapping_reviews'),'Expected W2 schema');
    $before=manifest();file_put_contents($location.'/before.json',json_encode($before,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    app(App\Support\InstallationState::class)->assertKey(requireSeal:true);
    echo json_encode(['tables'=>count($before),'rows'=>array_sum(array_column($before,'count')),'migrations'=>16]);
}elseif($mode==='upgrade'){
    migrate(getenv('W3_ROOT').'/upgrade-migrations');$before=json_decode(file_get_contents($location.'/before.json'),true,flags:JSON_THROW_ON_ERROR);
    demand(manifest($before)===$before,'Legacy data or columns changed');demand(DB::table('migrations')->count()===18,'Expected eighteen migrations');
    $expected=array_merge(array_keys($before),['migrations','instance_mapping_reviews']);sort($expected);$actual=tables();sort($actual);
    demand($actual===$expected,'Unexpected tables');demand(DB::table('instance_mapping_reviews')->count()===0,'Migration added business data');
    foreach($before as $table=>$old){$columns=Schema::getColumnListing($table);$extra=array_values(array_diff($columns,$old['columns']));sort($extra);
        $allowed=match($table){'instance_upstream_bindings'=>['source_snapshot'],'reconciliation_cases'=>['instance_upstream_binding_id'],default=>[]};
        demand($extra===$allowed,'Unexpected columns on '.$table);
    }
    demand(DB::table('instance_upstream_bindings')->whereNotNull('source_snapshot')->count()===0,'Historical endpoints were inferred');
    demand(DB::table('reconciliation_cases')->whereNotNull('instance_upstream_binding_id')->count()===0,'Historical binding cases were inferred');
    if(DB::getDriverName()==='sqlite'){demand(DB::select('PRAGMA foreign_key_check')===[],'SQLite FK check');demand(DB::select('PRAGMA quick_check')[0]->quick_check==='ok','SQLite integrity');}
    else foreach($actual as $table)demand(DB::select('CHECK TABLE `'.$table.'`')[0]->Msg_text==='OK','MySQL integrity');
    demand(Artisan::call('app:deployment-check')===0,'Deployment check: '.Artisan::output());
    file_put_contents($location.'/after.json',json_encode(manifest(),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo json_encode(['old_tables_preserved'=>count($before),'old_rows_preserved'=>array_sum(array_column($before,'count')),
        'migrations'=>18,'new_tables'=>['instance_mapping_reviews'],'new_columns'=>['source_snapshot','instance_upstream_binding_id'],
        'historical_endpoint_snapshots'=>'NULL','integrity'=>'PASS','deployment_check'=>'PASS']);
}elseif($mode==='setup'){
    $owner=User::factory()->create(['password'=>getenv('W3_PASSWORD'),'credit'=>'9999999995.99','frozen_credit'=>'4.00']);
    $actor=User::factory()->create();foreach(['finance-reconciliation','wallet-clearance-reviewer','wallet-clearance-operator','wallet-clearance-confirmer'] as $role)
        app(App\Actions\AssignAdminRoleAction::class)->execute($actor,$role,'Offline W3 authority');
    $invoice=Invoice::factory()->create(['user_id'=>$owner->id,'gateway'=>'epay_wechat','credit_applied'=>'4.00','gateway_amount'=>'6.00']);
    FinancialTransaction::create(['user_id'=>$owner->id,'invoice_id'=>$invoice->id,'type'=>App\Enums\TransactionType::CREDIT_FREEZE,
        'amount'=>'4.00','balance_after'=>$owner->credit,'frozen_after'=>'4.00','reference_key'=>'freeze:'.$invoice->id]);
    $receipt=new App\Data\VerifiedPaymentResult('epay_wechat','OFFLINE-W3-TRADE',$invoice->invoice_no,'11.00',
        ['out_trade_no'=>$invoice->invoice_no,'money'=>'11.00','trade_no'=>'OFFLINE-W3-TRADE','original'=>'UNCHANGED-VERIFIED-FACT']);
    app(App\Actions\HandlePaymentCallbackAction::class)->execute($receipt);
    $callback=PaymentCallback::where('gateway_trade_no','OFFLINE-W3-TRADE')->sole();$case=ReconciliationCase::where('payment_callback_id',$callback->id)->sole();
    demand($case->revision===1&&$case->status==='open'&&$case->initial_reason==='wallet_capacity','Expected original unposted case');
    demand(FulfillmentTask::where('invoice_id',$invoice->id)->count()===0,'Receipt prematurely delivered');
    $clearance=app(App\Actions\RequestWalletClearanceAction::class)->execute($owner,['request_id'=>(string)Str::ulid(),'amount'=>'1.00',
        'payout_method'=>'bank_transfer','payee_name'=>'Synthetic Original Owner','payee_account'=>'SIMULATED-ONLY','bank_name'=>'Offline Bank',
        'current_password'=>getenv('W3_PASSWORD'),'confirm'=>'1']);
    $process=app(App\Actions\ProcessWalletClearanceAction::class);$process->execute($clearance,$actor,op('approve'));
    $started=$process->execute($clearance,$actor,op('start'));
    $process->execute($clearance,$actor,op('paid',['intent_key'=>$started->payout_intent_key,'external_reference'=>'OFFLINE-W3-CLEARANCE',
        'confirmed_amount'=>'1.00','currency'=>'CNY','evidence'=>'SIMULATED-PAID-TRANSFER']));
    file_put_contents($location.'/race.json',json_encode(['owner'=>$owner->id,'actor'=>$actor->id,'invoice'=>$invoice->id,'case'=>$case->id,
        'callback'=>$callback->id,'original_callback'=>$callback->fresh()->getRawOriginal(),
        'original_attention'=>DB::table('audit_logs')->where('action','payment.callback.attention')->where('subject_id',$callback->id)->get()->toJson(),
        'left'=>(string)Str::ulid(),'right'=>(string)Str::ulid()],JSON_THROW_ON_ERROR));
    echo json_encode(['real_clearance_transition'=>'1.00','case_revision'=>1,'workers_share_original_owner_and_case'=>true]);
}elseif($mode==='race'){
    $side=$argv[2];demand(in_array($side,['left','right'],true),'Invalid worker');$data=json_decode(file_get_contents($location.'/race.json'),true,flags:JSON_THROW_ON_ERROR);$barrier=false;
    DB::connection()->beforeExecuting(function(string $sql)use(&$barrier,$location,$side):void {
        if(!$barrier&&str_contains($sql,'`users`')&&str_contains(strtolower($sql),'for update')){
            $barrier=true;file_put_contents($location.'/race-'.$side.'.ready',(string)getmypid());$deadline=microtime(true)+20;
            while(!is_file($location.'/race.go')){demand(microtime(true)<$deadline,'Owner lock barrier timeout');usleep(10000);}
        }
    });
    $result='posted';try{app(App\Actions\ReconcilePaymentCallbackAction::class)->execute(ReconciliationCase::findOrFail($data['case']),User::findOrFail($data['actor']),
        ['request_id'=>$data[$side],'expected_revision'=>1,'reason'=>'Offline original payment reconciled','evidence'=>'SIMULATED-VERIFIED-RECEIPT','confirm'=>'1']);}
    catch(Illuminate\Validation\ValidationException $error){demand(array_key_exists('expected_revision',$error->errors()),'Loser must fail specifically on stale revision');$result='stale_revision';}
    demand($barrier,'Worker missed actual owner lock');Http::assertNothingSent();echo json_encode(['side'=>$side,'pid'=>getmypid(),'owner_lock_barrier'=>true,'result'=>$result]);
}elseif($mode==='race-verify'){
    $data=json_decode(file_get_contents($location.'/race.json'),true,flags:JSON_THROW_ON_ERROR);$case=ReconciliationCase::findOrFail($data['case']);
    demand($case->status==='resolved'&&$case->revision===3&&app(App\Services\PaymentAttentionCases::class)->isResolved($case),'Result is not verifiably resolved');
    $owner=User::findOrFail($data['owner']);demand($owner->credit==='9999999999.99'&&$owner->frozen_credit==='0.00'&&$owner->wallet_clearance_frozen==='0.00','Wrong wallet result');
    foreach(['settle:'.$data['invoice']=>'4.00','payment:'.$data['invoice']=>'6.00','overpaid:'.$data['callback']=>'5.00'] as $key=>$amount){
        $rows=FinancialTransaction::where('reference_key',$key)->get();demand($rows->count()===1&&$rows->first()->amount===$amount,'Incorrect unique ledger '.$key);
    }
    demand(FulfillmentTask::where('invoice_id',$data['invoice'])->count()===1,'Duplicate fulfillment');
    demand(ReconciliationEntry::where('case_id',$case->id)->count()===3,'Duplicate reconciliation entries');
    demand(DB::table('notification_events')->where('event_key','invoice:'.$data['invoice'].':paid')->count()===1,'Paid event missing or duplicated');
    demand(DB::table('notification_events')->where('event_key','reconciliation-case:'.$case->id.':3')->count()===1,'Case event missing or duplicated');
    demand(PaymentCallback::findOrFail($data['callback'])->getRawOriginal()===$data['original_callback'],'Original callback changed');
    demand(DB::table('audit_logs')->where('action','payment.callback.attention')->where('subject_id',$data['callback'])->get()->toJson()===$data['original_attention'],'Original attention changed');
    demand(DB::table('jobs')->count()===0,'Queue jobs ran');
    echo json_encode(['case_status'=>'resolved','case_revision'=>3,'settle'=>'4.00','payment'=>'6.00','excess'=>'5.00','each_ledger_count'=>1,
        'fulfillment_tasks'=>1,'case_entries'=>3,'paid_events'=>1,'case_events'=>1,'original_callback_unchanged'=>true,'original_attention_unchanged'=>true]);
}elseif($mode==='mapping-json'){
    $owner=User::factory()->create();$actor=User::factory()->create();$instance=App\Models\Instance::factory()->create(['user_id'=>$owner->id]);
    $snapshot=['z_historical_facts'=>['z_resource'=>'old','a_currency'=>'CNY'],'a_version'=>1,'target_product_id'=>$instance->product_id,'instance_id'=>$instance->id];
    $evidence=app(App\Services\InstanceMappingEvidence::class);$hash=$evidence->fingerprint($snapshot);
    $review=InstanceMappingReview::create(['instance_id'=>$instance->id,'user_id'=>$owner->id,'actor_id'=>$actor->id,
        'target_product_id'=>$instance->product_id,'kind'=>'mapping','request_id'=>(string)Str::ulid(),'review_fingerprint'=>$hash,
        'snapshot'=>$snapshot,'reason'=>'Offline native JSON persistence','checked_at'=>now(),'confirmed_at'=>now(),'applied_at'=>now()]);
    $reloaded=$review->fresh();demand($snapshot!==$reloaded->snapshot,'Fixture must exercise native JSON reordering');
    demand($hash===$evidence->fingerprint($reloaded->snapshot),'JSON reordering changed mapping fingerprint');
    $instance->forceFill(['active_config'=>['mapping_review_id'=>$review->id]])->save();
    demand($evidence->mapping($instance->fresh())->id===$review->id,'Persisted mapping evidence rejected');
    echo json_encode(['native_json_key_order_changed'=>true,'stable_mapping_fingerprint'=>true,'persisted_mapping_read'=>'PASS']);
}elseif($mode==='binding-unique'){
    $owner=User::factory()->create();$instance=App\Models\Instance::factory()->create(['user_id'=>$owner->id]);
    $binding=InstanceUpstreamBinding::create(['instance_id'=>$instance->id,'driver_type'=>$instance->driver_type,'bound_at'=>now(),
        'unbound_at'=>now(),'upstream_disposition'=>'kept_orphan','reason_detail'=>'Offline schema uniqueness']);
    $facts=['user_id'=>$owner->id,'kind'=>'binding_orphan','source_type'=>'binding_orphan','source_id'=>(string)$binding->id,
        'source_fingerprint'=>hash('sha256','offline-'.$binding->id),'initial_reason'=>'kept_orphan','instance_upstream_binding_id'=>$binding->id];
    ReconciliationCase::create($facts);$code=null;
    try{ReconciliationCase::create(array_replace($facts,['source_id'=>'duplicate-'.$binding->id]));throw new RuntimeException('Duplicate binding accepted');}
    catch(Illuminate\Database\QueryException $error){$code=$error->errorInfo[1]??null;demand($code===1062&&str_contains($error->getMessage(),'reconciliation_binding_unique'),'Wrong uniqueness error');}
    demand(ReconciliationCase::where('instance_upstream_binding_id',$binding->id)->count()===1,'Binding registered twice');
    demand($binding->fresh()->source_snapshot===null,'Historical endpoint was fabricated');
    echo json_encode(['binding_unique_error'=>$code,'registered_cases'=>1,'historical_source_snapshot'=>null]);
}else throw new RuntimeException('Unsupported mode');
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
                        '--label', 'laravel13idc.review=reconciliation', '--network', 'none',
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
        sql("CREATE DATABASE reconciliation_only; CREATE USER 'reconciliation_fixture'@'localhost' IDENTIFIED BY '" + password
            + "'; GRANT ALL ON reconciliation_only.* TO 'reconciliation_fixture'@'localhost';")
        mysql_env = environment('mysql')
        run(mysql_env, 'baseline', 'mysql-baseline.log')
        run(mysql_env, 'checkpoint', 'mysql-checkpoint.log')
        backup = subprocess.run(['docker', 'exec', container, 'mysqldump', '-uroot', '--single-transaction',
                                 '--routines', '--triggers', '--events', '--hex-blob', '--no-tablespaces',
                                 'reconciliation_only'], check=True, capture_output=True, timeout=30)
        (root / 'mysql-w2-checkpoint.sql').write_bytes(backup.stdout)
        preservation = run(mysql_env, 'upgrade', 'mysql-preservation.log')
        run(mysql_env, 'setup', 'mysql-race-setup.log')
        workers = {side: subprocess.Popen([php, '-d', 'memory_limit=512M', str(harness), 'race', side],
                                          cwd=repo, env=mysql_env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
                   for side in ['left', 'right']}
        outcomes = []
        try:
            deadline = time.monotonic() + 20
            while not all((root / 'mysql' / ('race-' + side + '.ready')).exists() for side in workers):
                if time.monotonic() >= deadline or any(worker.poll() is not None for worker in workers.values()):
                    for side, worker in workers.items():
                        if worker.poll() is not None:
                            output, error = worker.communicate()
                            (root / ('mysql-race-' + side + '.log')).write_text(output + error)
                    raise RuntimeError('Workers failed before their shared original owner lock')
                time.sleep(0.01)
            (root / 'mysql/race.go').touch()
            for side, worker in workers.items():
                output, error = worker.communicate(timeout=30)
                (root / ('mysql-race-' + side + '.log')).write_text(output + error)
                if worker.returncode:
                    raise RuntimeError('Race worker failed: ' + side)
                outcomes.append(json.loads(output))
            if len({outcome['pid'] for outcome in outcomes}) != 2 or sorted(outcome['result'] for outcome in outcomes) != ['posted', 'stale_revision']:
                raise RuntimeError('Expected independent processes and exactly one stale version')
        finally:
            for worker in workers.values():
                if worker.poll() is None:
                    worker.terminate()
                    worker.communicate(timeout=5)
        race = run(mysql_env, 'race-verify', 'mysql-race-result.log')
        mapping = run(mysql_env, 'mapping-json', 'mysql-mapping-json.log')
        binding = run(mysql_env, 'binding-unique', 'mysql-binding-unique.log')
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
                  'reconciliation_workers': outcomes, 'single_posting_arbitration': race,
                  'mapping_json_fingerprint': mapping, 'binding_source_uniqueness': binding, 'preview_read_only_copy': original,
                  'original_preview_migrated': False, 'real_external_actions': 0}
        (root / 'result.json').write_text(json.dumps(result, indent=2))
        print('PASS: offline MySQL single posting, immutable sources, mapping JSON and W3 incremental upgrade.', flush=True)
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
