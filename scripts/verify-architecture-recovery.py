"""Restore one stopped synthetic checkpoint into fresh offline MySQL/Redis instances.

Requires cached mysql:8.0.35 and redis:7-alpine images and the project's PHP 8.4.
Accepts no business database, endpoint or credentials. Both generations use
network=none, private Unix sockets and no published ports. No external send,
payment or supplier action is executed. Private evidence is retained in /tmp.
"""

import base64
import hashlib
import json
import os
import pathlib
import shutil
import subprocess
import tempfile
import time
import uuid


def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main():
    os.umask(0o077)
    repo = pathlib.Path(__file__).resolve().parents[1]
    php = '/www/server/php/84/bin/php'
    for image in ['mysql:8.0.35', 'redis:7-alpine']:
        subprocess.run(['docker', 'image', 'inspect', image], check=True, capture_output=True, timeout=10)
    root = pathlib.Path(tempfile.mkdtemp(prefix='laravel13idc-architecture-recovery-'))
    print('Private evidence: ' + str(root), flush=True)
    backup = root / 'checkpoint'
    backup.mkdir(mode=0o700)
    legacy_migrations = root / 'legacy-migrations'
    legacy_migrations.mkdir(mode=0o700)
    additions = {'2026_11_19_000013_add_receivable_evidence_revision_to_users.php',
                 '2026_11_19_000014_add_notification_recipient_cursors.php',
                 '2026_11_19_000015_add_operational_event_outbox.php'}
    for migration in (repo / 'database/migrations').glob('*.php'):
        if migration.name not in additions:
            shutil.copyfile(migration, legacy_migrations / migration.name)
    suffix = uuid.uuid4().hex[:10]
    password = 'Offline-' + os.urandom(20).hex() + '!'
    key = 'base64:' + base64.b64encode(os.urandom(32)).decode()
    customer_password = 'Offline-' + os.urandom(12).hex() + '!'
    created = []
    clean = {name: value for name, value in os.environ.items() if not name.startswith(
        ('APP_', 'DB_', 'REDIS_', 'LARAVEL_', 'VIEW_', 'QUEUE_', 'CACHE_', 'SESSION_', 'MAIL_', 'LOG_', 'BROADCAST_',
         'MAINTENANCE_', 'RECEIVABLE_'))}
    base = dict(clean, APP_ENV='testing', APP_DEBUG='false', APP_URL='https://offline.example.test', APP_KEY=key,
                BCRYPT_ROUNDS='4', CACHE_STORE='redis', SESSION_DRIVER='array', SESSION_SECURE_COOKIE='true',
                QUEUE_CONNECTION='redis', REDIS_QUEUE_RETRY_AFTER='240', MAIL_MAILER='array', LOG_CHANNEL='single',
                MAINTENANCE_CACHE_STORE='redis', RECEIVABLE_CHECK_CACHE_STORE='redis',
                DB_URL='', DB_CONNECTION='mysql', DB_HOST='localhost', DB_PORT='3306', DB_DATABASE='recovery_only',
                DB_USERNAME='recovery_fixture', DB_PASSWORD=password, REDIS_URL='', REDIS_PORT='0',
                REDIS_PASSWORD='', REDIS_DB='0', REDIS_CACHE_DB='1', REDIS_PREFIX='offline-recovery:',
                RECOVERY_REPO=str(repo), RECOVERY_ROOT=str(root), RECOVERY_PASSWORD=customer_password)

    def environment(generation):
        location = root / generation
        for folder in ['mysql-socket', 'redis-socket']:
            target = location / folder
            target.mkdir(parents=True, mode=0o777)
            target.chmod(0o777)
        storage = location / 'storage'
        for folder in ['framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private']:
            (storage / folder).mkdir(parents=True, exist_ok=True)
        for asset in ['js/filament/filament/app.js', 'css/filament/filament/app.css', 'js/classic-config.js']:
            target = location / 'public' / asset
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(repo / 'public' / asset, target)
        return dict(base, DB_SOCKET=str(location / 'mysql-socket/mysqld.sock'),
                    REDIS_HOST=str(location / 'redis-socket/redis.sock'), RECOVERY_GENERATION=generation,
                    LARAVEL_STORAGE_PATH=str(storage), VIEW_COMPILED_PATH=str(storage / 'framework/views'),
                    APP_CONFIG_CACHE=str(storage / 'config.php'), APP_ROUTES_CACHE=str(storage / 'routes.php'),
                    APP_EVENTS_CACHE=str(storage / 'events.php'), APP_SERVICES_CACHE=str(storage / 'services.php'),
                    APP_PACKAGES_CACHE=str(storage / 'packages.php'), RECOVERY_PUBLIC=str(location / 'public'))

    harness = root / 'acceptance.php'
    harness.write_text(r'''<?php
declare(strict_types=1);
require getenv('RECOVERY_REPO').'/vendor/autoload.php';
$app=require getenv('RECOVERY_REPO').'/bootstrap/app.php';
$app->usePublicPath(getenv('RECOVERY_PUBLIC'));
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function(Throwable $error):void{fwrite(STDERR,(string)$error.PHP_EOL);exit(20);});
use App\Models\User;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Instance;
use App\Models\RedemptionCode;
use App\Models\FulfillmentTask;
use App\Models\FinancialTransaction;
use App\Models\NotificationDelivery;
use App\Models\OperationalEvent;
use App\Models\WalletClearance;
use App\Enums\BillingCycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
function demand(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function manifest(array $columns=[]):array{
    $tables=$columns===[]?Schema::getTableListing(DB::getDatabaseName(),false):array_keys($columns);sort($tables);$result=[];
    foreach($tables as $table){$rows=[];foreach(DB::table($table)->get($columns[$table]??['*'])as$row)$rows[]=json_encode((array)$row,JSON_THROW_ON_ERROR);
        sort($rows,SORT_STRING);$result[$table]=['rows'=>count($rows),'sha256'=>hash('sha256',implode("\n",$rows))];}
    return $result;
}
function operation(string $name,array $extra=[]):array{return array_replace(['request_id'=>(string)Str::ulid(),'operation'=>$name,
    'reason'=>'Offline recovery evidence','confirm'=>'1'],$extra);}
function state():array{return json_decode(file_get_contents(getenv('RECOVERY_ROOT').'/checkpoint/state.json'),true,flags:JSON_THROW_ON_ERROR);}
$connection=DB::connection();
demand($connection->getDriverName()==='mysql'&&$connection->getDatabaseName()==='recovery_only'
    &&$connection->getConfig('unix_socket')===getenv('RECOVERY_ROOT').'/'.getenv('RECOVERY_GENERATION').'/mysql-socket/mysqld.sock','Private MySQL only');
Http::preventStrayRequests();Http::fake(fn()=>throw new RuntimeException('External transport forbidden'));
Illuminate\Support\Facades\Mail::fake();Queue::fake();
$mode=$argv[1];
if($mode==='seed'){
    demand(Schema::getTableListing(DB::getDatabaseName(),false)===[],'Seed requires an empty private schema');
    $migrator=app(Illuminate\Database\Migrations\Migrator::class);$migrator->getRepository()->createRepository();
    $migrator->run([getenv('RECOVERY_ROOT').'/legacy-migrations'],['force'=>true]);
    demand(DB::table('migrations')->count()===18&&!Schema::hasColumn('users','receivable_revision'),'Expected W3 legacy schema');
    $owner=User::factory()->create(['credit'=>'20.00','password'=>getenv('RECOVERY_PASSWORD')]);
    $admin=User::factory()->create();
    foreach(['access-manager','wallet-clearance-reviewer','wallet-clearance-operator','wallet-clearance-confirmer']as$role)
        app(App\Actions\AssignAdminRoleAction::class)->execute($admin,$role,'Offline recovery authority');
    $product=Product::factory()->create(['local_prices'=>['onetime'=>['price'=>'10.00','enabled'=>true]]]);
    $code=RedemptionCode::factory()->create(['product_id'=>$product->id,'code'=>'SYNTHETIC-RECOVERY-CARD']);
    // Keep pre-upgrade fixtures raw: long-lived Eloquent guardable-column caches belong to their original schema.
    $legacyEventId=DB::table('notification_events')->insertGetId(['user_id'=>$owner->id,'scope'=>'user','event_type'=>'invoice.paid',
        'event_key'=>'recovery:legacy:event','dedupe_key'=>hash('sha256','recovery:legacy:event'),
        'encrypted_payload'=>Illuminate\Support\Facades\Crypt::encryptString(json_encode(['variables'=>['invoice_no'=>'INV-LEGACY-RECOVERY']],JSON_THROW_ON_ERROR)),
        'created_at'=>now(),'updated_at'=>now()]);
    $columns=[];
    foreach(Schema::getTableListing(DB::getDatabaseName(),false)as$table)if($table!=='migrations')$columns[$table]=Schema::getColumnListing($table);
    $legacy=manifest($columns);
    demand(Artisan::call('migrate',['--force'=>true])===0,'Incremental W4 migration failed');
    demand(DB::table('migrations')->count()===21&&manifest($columns)===$legacy,'Incremental migration changed historical data');
    $legacyEvent=App\Models\NotificationEvent::findOrFail($legacyEventId);
    demand($owner->fresh()->receivable_revision===0&&!$owner->fresh()->receivable_reconciliation_required
        &&$legacyEvent->fresh()->recipient_cursor===0&&$legacyEvent->fresh()->recipient_binding_cursor===0
        &&$legacyEvent->fresh()->recipient_upper_bound===null&&$legacyEvent->fresh()->recipient_binding_upper_bound===null
        &&OperationalEvent::count()===0,'Incremental defaults inferred business facts');
    $input=['request_id'=>(string)Str::ulid()];
    $invoice=app(App\Actions\CreateComboInvoiceAction::class)->execute($owner,$product,BillingCycle::ONETIME,[],true,input:$input);
    $task=FulfillmentTask::where('invoice_id',$invoice->id)->sole();
    app(App\Actions\ExecuteFulfillmentTaskAction::class)->execute($task->id);
    demand($task->fresh()->status===App\Enums\FulfillmentTaskStatus::SUCCESS&&$code->fresh()->status==='sold','Actual local fulfillment failed');
    $request=['request_id'=>(string)Str::ulid(),'amount'=>'1.00','payout_method'=>'bank_transfer','payee_name'=>'Synthetic Owner',
        'payee_account'=>'SIMULATED-NOT-A-REAL-ACCOUNT','bank_name'=>'Offline Bank','current_password'=>getenv('RECOVERY_PASSWORD'),'confirm'=>'1'];
    $clearance=app(App\Actions\RequestWalletClearanceAction::class)->execute($owner,$request);
    $process=app(App\Actions\ProcessWalletClearanceAction::class);
    $process->execute($clearance,$admin,operation('approve'));
    $started=$process->execute($clearance,$admin,operation('start'));
    $process->execute($clearance,$admin,operation('unknown',['intent_key'=>$started->payout_intent_key,'evidence'=>'Synthetic crash; no external transfer executed']));
    $manager=app(App\Services\NotificationManager::class);
    $manager->queue($owner->id,'invoice.paid',['invoice_no'=>'INV-RECOVERY','amount'=>'10.00','currency'=>'CNY'],'recovery:notification:1');
    $delivery=NotificationDelivery::where('notification_event_id',DB::table('notification_events')->where('event_key','recovery:notification:1')->value('id'))->where('channel','email')->sole();
    $delivery->update(['status'=>'running','attempts'=>1,'send_started_at'=>now()->subMinutes(3),
        'locked_at'=>now()->subMinutes(3),'lease_expires_at'=>now()->subMinute(),'locked_by'=>'synthetic-lost-worker']);
    DB::transaction(fn()=>app(App\Actions\RecordOperationalIncidentAction::class)->execute('fulfillment.failed',
        ['task_id'=>$task->id,'status'=>'failed'],'recovery:observed:1'));
    $seal=app(App\Support\InstallationState::class);
    $seal->write('installed.lock',$seal->seal(['format'=>1,'admin_id'=>$admin->id],config('app.key')));
    file_put_contents(storage_path('app/private/recovery-proof.bin'),random_bytes(128));
    Cache::put('must-not-restore-old-lock','source-generation',600);
    demand(Artisan::call('app:deployment-check')===0,'Source deployment check failed: '.Artisan::output());
    $snapshot=['owner'=>$owner->id,'admin'=>$admin->id,'product'=>$product->id,'invoice'=>$invoice->id,'task'=>$task->id,'code'=>$code->id,
        'clearance'=>$clearance->id,'input'=>$input,'request'=>$request,'delivery'=>$delivery->id,
        'migrations'=>DB::table('migrations')->count(),'manifest'=>manifest(),
        'upgrade'=>['from'=>18,'to'=>21,'old_tables_preserved'=>count($legacy),'old_rows_preserved'=>array_sum(array_column($legacy,'rows'))]];
    file_put_contents(getenv('RECOVERY_ROOT').'/checkpoint/state.json',json_encode($snapshot,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo json_encode(['migrations'=>$snapshot['migrations'],'tables'=>count($snapshot['manifest']),
        'incremental_upgrade'=>$snapshot['upgrade'],'actual_local_purchase'=>'Paid/sold/success',
        'wallet_clearance'=>'unknown','notification'=>'running_with_send_intent','external_actions'=>0]);
}elseif($mode==='verify'){
    app()->instance(App\Drivers\Notification\LogMailDriver::class,new class(app(App\Services\SettingService::class)) extends App\Drivers\Notification\LogMailDriver{
        public int $calls=0;
        public function send(string $to,string $subject,string $bodyMarkdown,array $variables=[]):bool{$this->calls++;throw new RuntimeException('Recovery must never invoke a mail transport');}
    });
    $state=state();demand(manifest()===$state['manifest'],'Restored database differs from the checkpoint');
    app(App\Support\InstallationState::class)->assertKey(requireSeal:true);
    demand(Cache::get('must-not-restore-old-lock')===null,'Old Redis state was restored');
    $code=RedemptionCode::findOrFail($state['code']);$instance=Instance::findOrFail(Invoice::findOrFail($state['invoice'])->instance_id);
    demand($code->code==='SYNTHETIC-RECOVERY-CARD'&&$instance->encrypted_credentials['card_key']===$code->code,'Delivered credentials could not be decrypted');
    demand(app(App\Services\DriverManager::class)->fulfillment($instance->driver_type,$instance->upstream_api_id)->deliveryEvidence($instance)['card_id']===$code->id,'Delivered rights evidence invalid');
    $clearance=WalletClearance::findOrFail($state['clearance']);
    demand($clearance->status==='unknown'&&$clearance->payout_details['account']==='SIMULATED-NOT-A-REAL-ACCOUNT'
        &&is_array($clearance->operation_requests)&&$clearance->payout_evidence['classification']==='unknown','Unknown payout evidence not restored');
    $owner=User::findOrFail($state['owner']);
    demand($owner->credit==='9.00'&&$owner->frozen_credit==='0.00'&&$owner->wallet_clearance_frozen==='1.00','Wallet checkpoint changed');
    $before=manifest();Queue::fake();
    $again=app(App\Actions\CreateComboInvoiceAction::class)->execute($owner,Product::findOrFail($state['product']),BillingCycle::ONETIME,[],true,input:$state['input']);
    demand($again->id===$state['invoice'],'Original purchase identity lost');
    app(App\Actions\ExecuteFulfillmentTaskAction::class)->execute($state['task']);
    demand(app(App\Actions\RequestWalletClearanceAction::class)->execute($owner,$state['request'])->id===$state['clearance'],'Original payout identity lost');
    demand(manifest()===$before,'Repeated completed purchase or unknown payout changed the checkpoint');
    Queue::assertNothingPushed();
    // Recover a lost worker's persisted send intent. Even a direct execute must not send it again.
    $dispatch=app(App\Actions\DispatchPendingNotificationsAction::class);$dispatch->recoverOne($state['delivery']);
    app(App\Actions\ExecuteNotificationDeliveryAction::class)->execute($state['delivery']);
    $delivery=NotificationDelivery::findOrFail($state['delivery']);
    demand($delivery->status==='uncertain'&&$delivery->attempts===1&&$delivery->encrypted_payload['variables']['invoice_no']==='INV-RECOVERY','Unknown notification was replayed or lost');
    $fact=OperationalEvent::where('event_key','recovery:observed:1')->sole();
    app(App\Actions\DispatchOperationalIncidentAction::class)->execute($fact->id);
    app(App\Actions\DispatchOperationalIncidentAction::class)->execute($fact->id);
    demand(DB::table('notification_events')->where('event_key','operational-event:'.$fact->id)->count()===1,'Operational fact projection duplicated');
    demand(WalletClearance::findOrFail($state['clearance'])->status==='unknown'
        &&FinancialTransaction::where('reference_key','wallet_clearance:'.$state['clearance'].':paid')->count()===0,'Unknown payout was confirmed during recovery');
    Http::assertNothingSent();Illuminate\Support\Facades\Mail::assertNothingSent();Queue::assertNothingPushed();
    demand(app(App\Drivers\Notification\LogMailDriver::class)->calls===0,'A mail transport was invoked');
    demand(DB::table('jobs')->count()===0,'Unexpected database jobs');
    // Cache-building commands boot another application and can replace global Facade fakes.
    foreach(['config:cache','route:cache','view:cache','event:cache','app:deployment-check']as$command)
        demand(Artisan::call($command)===0,'Restore command failed: '.$command.' '.Artisan::output());
    echo json_encode(['checkpoint_tables_preserved'=>count($before),'checkpoint_rows_preserved'=>array_sum(array_column($before,'rows')),
        'migrations'=>$state['migrations'],'sealed_key'=>'PASS','delivered_card_and_rights'=>'PASS',
        'original_purchase_and_clearance_idempotency'=>'PASS','wallet'=>['credit'=>'9.00','invoice_frozen'=>'0.00','clearance_frozen'=>'1.00'],
        'unknown_payout'=>'remains_unknown_without_paid_ledger','notification_send_intent'=>'uncertain_without_replay',
        'operational_event_projection'=>'one_event','redis_old_state'=>'not_restored','cache_and_deployment_check'=>'PASS','external_actions'=>0]);
}else throw new RuntimeException('Unsupported mode');
''')

    def run(env, mode):
        process = subprocess.run([php, '-d', 'memory_limit=512M', str(harness), mode],
                                 cwd=repo, env=env, capture_output=True, text=True, timeout=90)
        (root / (mode + '.log')).write_text(process.stdout + process.stderr)
        if process.returncode:
            raise RuntimeError('Recovery phase failed: ' + mode + '; inspect private log')
        result = json.loads(process.stdout)
        print(mode + ': ' + json.dumps(result), flush=True)
        return result

    def start(generation, env):
        names = {}
        for kind, image in [('mysql', 'mysql:8.0.35'), ('redis', 'redis:7-alpine')]:
            name = 'laravel13idc-recovery-' + generation + '-' + kind + '-' + suffix
            location = root / generation / (kind + '-socket')
            command = ['docker', 'run', '--detach', '--pull=never', '--name', name, '--network', 'none',
                       '--label', 'laravel13idc.review=architecture-recovery']
            if kind == 'mysql':
                command += ['--tmpfs', '/var/lib/mysql:rw,size=768m', '--mount',
                            'type=bind,source=' + str(location) + ',target=/var/run/mysqld',
                            '--env', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes', image, '--performance-schema=OFF',
                            '--innodb-buffer-pool-size=32M', '--mysqlx=OFF', '--skip-log-bin']
            else:
                command += ['--mount', 'type=bind,source=' + str(location) + ',target=/socket', image,
                            'redis-server', '--port', '0', '--unixsocket', '/socket/redis.sock',
                            '--unixsocketperm', '777', '--save', '', '--appendonly', 'no']
            subprocess.run(command, check=True, capture_output=True, timeout=25)
            created.append(name)
            names[kind] = name
            inspection = json.loads(subprocess.run(['docker', 'inspect', name], check=True, capture_output=True,
                                                   text=True, timeout=10).stdout)[0]
            if inspection['HostConfig']['NetworkMode'] != 'none' or inspection['HostConfig']['PortBindings']:
                raise RuntimeError('Disposable container isolation failed')
        deadline = time.monotonic() + 90
        while True:
            probe = subprocess.run(['docker', 'exec', names['mysql'], 'mysql', '-uroot', '-Nse', 'SELECT VERSION()'],
                                   capture_output=True, text=True, timeout=5)
            comm = subprocess.run(['docker', 'exec', names['mysql'], 'cat', '/proc/1/comm'],
                                  capture_output=True, text=True, timeout=5)
            if probe.returncode == 0 and comm.stdout.strip() == 'mysqld':
                break
            if time.monotonic() >= deadline:
                raise RuntimeError('Offline MySQL failed to start')
            time.sleep(0.5)
        sql = ("CREATE DATABASE recovery_only; CREATE USER 'recovery_fixture'@'localhost' IDENTIFIED BY '" + password
               + "'; GRANT ALL ON recovery_only.* TO 'recovery_fixture'@'localhost';")
        subprocess.run(['docker', 'exec', '-i', names['mysql'], 'mysql', '-uroot'],
                       input=sql.encode(), check=True, capture_output=True, timeout=15)
        redis_probe = subprocess.run(['docker', 'exec', names['redis'], 'redis-cli', '-s', '/socket/redis.sock', 'PING'],
                                     check=True, capture_output=True, text=True, timeout=5)
        if redis_probe.stdout.strip() != 'PONG':
            raise RuntimeError('Offline Redis failed to start')
        return names, probe.stdout.strip()

    try:
        source_env = environment('source')
        names, version = start('source', source_env)
        seeded = run(source_env, 'seed')
        dump = subprocess.run(['docker', 'exec', names['mysql'], 'mysqldump', '-uroot', '--single-transaction',
                               '--routines', '--triggers', '--events', '--hex-blob', '--no-tablespaces', 'recovery_only'],
                              check=True, capture_output=True, timeout=30)
        (backup / 'database.sql').write_bytes(dump.stdout)
        source_storage = pathlib.Path(source_env['LARAVEL_STORAGE_PATH'])
        shutil.copyfile(source_storage / 'installed.lock', backup / 'installed.lock')
        shutil.copytree(source_storage / 'app/private', backup / 'private')
        # Separate key material from the database/files bundle. Neither is published.
        (root / 'key-material.json').write_text(json.dumps({'APP_KEY': key, 'database_password': password}))
        files = {'private_file': digest(backup / 'private/recovery-proof.bin'), 'seal': digest(backup / 'installed.lock'),
                 'database_dump': digest(backup / 'database.sql')}
        for name in names.values():
            subprocess.run(['docker', 'rm', '--force', name], check=True, capture_output=True, timeout=20)
            created.remove(name)
        # The source DB and Redis tmpfs have been destroyed. Rebuild a new generation from the checkpoint.
        shutil.rmtree(source_storage / 'app/private')
        (source_storage / 'installed.lock').unlink()
        restored_env = environment('restored')
        names, restored_version = start('restored', restored_env)
        subprocess.run(['docker', 'exec', '-i', names['mysql'], 'mysql', '-uroot', 'recovery_only'],
                       input=(backup / 'database.sql').read_bytes(), check=True, capture_output=True, timeout=30)
        restored_storage = pathlib.Path(restored_env['LARAVEL_STORAGE_PATH'])
        shutil.copyfile(backup / 'installed.lock', restored_storage / 'installed.lock')
        shutil.copytree(backup / 'private', restored_storage / 'app/private', dirs_exist_ok=True)
        key_material = json.loads((root / 'key-material.json').read_text())
        restored_env['APP_KEY'] = key_material['APP_KEY']
        if digest(restored_storage / 'app/private/recovery-proof.bin') != files['private_file'] or digest(restored_storage / 'installed.lock') != files['seal']:
            raise RuntimeError('Restored private files do not match the checkpoint')
        verified = run(restored_env, 'verify')
        result = {'status': 'PASS', 'source_mysql': version, 'restored_mysql': restored_version,
                  'network': 'none', 'source_destroyed_before_restore': True, 'checkpoint_files_sha256': files,
                  'seed': seeded, 'restore': verified, 'production_preview_touched': False,
                  'limits': ['synthetic stopped checkpoint', 'logical MySQL restore on one host',
                             'no cross-host disaster recovery', 'no real external sends or transfers']}
        (root / 'result.json').write_text(json.dumps(result, indent=2))
        print('PASS: fresh MySQL/Redis restore, matching private files/key/seal, idempotency and no unknown replay.', flush=True)
    finally:
        cleanup = []
        for name in created:
            logs = subprocess.run(['docker', 'logs', name], capture_output=True, text=True, timeout=10)
            (root / (name + '.log')).write_text(logs.stdout + logs.stderr)
            removed = subprocess.run(['docker', 'rm', '--force', name], capture_output=True, timeout=20)
            cleanup.append({'container': name, 'removed': removed.returncode == 0})
        (root / 'cleanup.json').write_text(json.dumps(cleanup))
        for path in root.rglob('*'):
            if path.is_file():
                path.chmod(0o600)
        print('Disposable containers removed; private evidence: ' + str(root), flush=True)


if __name__ == '__main__':
    main()
