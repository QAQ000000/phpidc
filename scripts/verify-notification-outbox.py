"""Exercise outbox workers against a disposable, offline MySQL; never use .env databases."""

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
root = pathlib.Path(tempfile.mkdtemp(prefix='laravel13idc-notification-outbox-'))
socket_dir = root / 'socket'
socket_dir.mkdir(mode=0o777)
socket_dir.chmod(0o777)
storage = root / 'storage'
for folder in ['framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private']:
    (storage / folder).mkdir(parents=True)
env = dict(os.environ, APP_ENV='testing', APP_DEBUG='false',
           APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(),
           APP_CONFIG_CACHE=str(root / 'config.php'), APP_ROUTES_CACHE=str(root / 'routes.php'),
           LARAVEL_STORAGE_PATH=str(storage), VIEW_COMPILED_PATH=str(storage / 'framework/views'),
           DB_CONNECTION='mysql', DB_URL='', DB_HOST='localhost', DB_PORT='3306',
           DB_SOCKET=str(socket_dir / 'mysqld.sock'), DB_DATABASE='notification_outbox_only',
           DB_USERNAME='root', DB_PASSWORD='', CACHE_STORE='array', SESSION_DRIVER='array',
           QUEUE_CONNECTION='database', LOG_CHANNEL='single', BCRYPT_ROUNDS='4',
           OUTBOX_REPO=str(repo), OUTBOX_ROOT=str(root))
container = 'laravel13idc-outbox-' + uuid.uuid4().hex[:12]
print('Evidence directory: ' + str(root), flush=True)

(root / 'bootstrap.php').write_text(r'''<?php
declare(strict_types=1);
require getenv('OUTBOX_REPO').'/vendor/autoload.php';
$app = require getenv('OUTBOX_REPO').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'notification_outbox_only'
    || config('database.connections.mysql.unix_socket') !== getenv('OUTBOX_ROOT').'/socket/mysqld.sock') {
    throw new RuntimeException('Only the dedicated offline outbox database is authorized.');
}
function requireOutbox(bool $condition, string $message): void {
    if (! $condition) { throw new RuntimeException($message); }
}
function barrier(string $name): void {
    $deadline = microtime(true) + 20;
    while (! file_exists(getenv('OUTBOX_ROOT').'/'.$name)) {
        if (microtime(true) > $deadline) { throw new RuntimeException('Outbox barrier timed out: '.$name); }
        usleep(10000);
    }
}
''')
(root / 'probe.php').write_text(r'''<?php
$pdo = new PDO('mysql:unix_socket='.getenv('DB_SOCKET').';dbname=notification_outbox_only', 'root', '');
echo $pdo->query('SELECT VERSION()')->fetchColumn();
''')
(root / 'setup.php').write_text(r'''<?php
require __DIR__.'/bootstrap.php';
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Services\NotificationManager;
use App\Support\InstallationState;
use Illuminate\Support\Facades\DB;
$installation = app(InstallationState::class);
$installation->write('installed.lock',$installation->seal(['format'=>1,'admin_id'=>1],config('app.key')));
DB::table('system_settings')->updateOrInsert(['group'=>'telegram','key'=>'bot_token'], ['value'=>'OFFLINE-FAKE-TOKEN','is_encrypted'=>false]);
$fixtures = [];
foreach (['claim', 'closure_first', 'inflight', 'crash', 'queue', 'queue_renewal'] as $kind) {
    $user = User::factory()->create(['email'=>$kind.'@example.test','password'=>'Isolated-outbox-only']);
    UserNotificationBinding::create(['user_id'=>$user->id,'channel'=>'telegram','target'=>'offline-'.$kind,'verified_at'=>now()]);
    app(NotificationManager::class)->queue($user->id, 'invoice.paid', ['invoice_no'=>'OUTBOX-'.$kind,'amount'=>'10.00','currency'=>'CNY'], 'invoice:'.$kind.':paid');
    $delivery = NotificationDelivery::where('user_id',$user->id)->where('channel','telegram')->sole();
    if ($kind==='queue_renewal') {
        $payload = $delivery->encrypted_payload;
        $payload['invoice_id'] = 125;
        $delivery->update(['job_kind'=>'renewal','encrypted_payload'=>$payload]);
    }
    $fixtures[$kind] = ['user'=>$user->id,'delivery'=>$delivery->id];
}
$payloads = DB::table('jobs')->pluck('payload');
foreach ($payloads as $payload) {
    requireOutbox(!str_contains($payload,'offline-') && !str_contains($payload,'@example.test')
        && !str_contains($payload,'OFFLINE-FAKE-TOKEN') && !str_contains($payload,'OUTBOX-'), 'New queue payload leaked private snapshot');
}
DB::table('jobs')->delete();
file_put_contents(__DIR__.'/fixtures.json',json_encode($fixtures,JSON_THROW_ON_ERROR));
echo json_encode(['fixtures'=>$fixtures,'serialized_jobs_checked'=>$payloads->count()],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
''')
(root / 'worker.php').write_text(r'''<?php
require __DIR__.'/bootstrap.php';
use App\Actions\CloseCustomerAccountAction;
use App\Actions\DispatchNotificationDeliveryAction;
use App\Actions\ExecuteNotificationDeliveryAction;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Services\NotificationManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
[$script,$mode,$kind,$slot] = $argv;
$fixtures = json_decode(file_get_contents(__DIR__.'/fixtures.json'),true,flags:JSON_THROW_ON_ERROR);
$fixture = $fixtures[$kind] ?? null;
Http::preventStrayRequests();
Http::fake(function () use ($kind,$fixture) {
    requireOutbox(DB::transactionLevel() === 0, 'Transport held database transaction');
    $delivery = NotificationDelivery::findOrFail($fixture['delivery']);
    requireOutbox($delivery->status==='running' && $delivery->send_started_at!==null, 'Transport has no committed sending intent');
    file_put_contents(__DIR__.'/transport.jsonl',json_encode(['kind'=>$kind,'pid'=>getmypid(),'intent'=>true]).PHP_EOL,FILE_APPEND|LOCK_EX);
    if (in_array($kind,['claim','inflight'],true)) {
        file_put_contents(__DIR__.'/'.$kind.'.intent','ready');
        barrier($kind.'.release');
    }
    return Http::response(['ok'=>true]);
});
file_put_contents(__DIR__.'/'.$kind.'-'.$slot.'.ready','ready');
if ($mode==='record') {
    barrier($kind.'.start');
    DB::transaction(function (): void {
        app(NotificationManager::class)->queueOperations('payment.callback.attention',['amount'=>'10.00','currency'=>'CNY','status'=>'unknown'],'callback:concurrent:attention');
        usleep(100000);
    });
} elseif ($mode==='close_first') {
    DB::transaction(function () use ($fixture,$kind): void {
        $user = User::whereKey($fixture['user'])->lockForUpdate()->firstOrFail();
        file_put_contents(__DIR__.'/'.$kind.'.locked','ready');
        barrier($kind.'.release');
        app(CloseCustomerAccountAction::class)->execute($user,'Isolated-outbox-only',true);
    });
} elseif ($mode==='close') {
    app(CloseCustomerAccountAction::class)->execute(User::findOrFail($fixture['user']),'Isolated-outbox-only',true);
} elseif ($mode==='queue') {
    app(DispatchNotificationDeliveryAction::class)->execute($fixture['delivery']);
    Artisan::call('queue:work',['connection'=>'database','--once'=>true,'--sleep'=>0,'--tries'=>3,'--timeout'=>60]);
    echo Artisan::output();
} else {
    if ($kind==='crash') {
        DB::listen(function (QueryExecuted $query): void {
            if (str_contains($query->sql,'notification_logs') && str_starts_with(strtolower($query->sql),'insert')) {
                throw new RuntimeException('Injected accepted-result transaction failure');
            }
        });
    }
    try {
        app(ExecuteNotificationDeliveryAction::class)->execute($fixture['delivery']);
        requireOutbox($kind!=='crash','Accepted result fault was not exercised');
    } catch (RuntimeException $error) {
        if ($kind!=='crash' || $error->getMessage()!=='Injected accepted-result transaction failure') { throw $error; }
        echo "accepted_result_rollback\n";
    }
}
echo json_encode(['mode'=>$mode,'kind'=>$kind,'slot'=>$slot,'done'=>true],JSON_THROW_ON_ERROR).PHP_EOL;
''')
(root / 'final.php').write_text(r'''<?php
require __DIR__.'/bootstrap.php';
use App\Actions\DispatchPendingNotificationsAction;
use App\Actions\ExecuteNotificationDeliveryAction;
use App\Models\NotificationDelivery;
use App\Models\NotificationEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
$fixtures = json_decode(file_get_contents(__DIR__.'/fixtures.json'),true,flags:JSON_THROW_ON_ERROR);
$evidence = [];
$counts = [];
foreach (file(__DIR__.'/transport.jsonl',FILE_IGNORE_NEW_LINES) as $line) {
    $entry = json_decode($line,true,flags:JSON_THROW_ON_ERROR);
    $counts[$entry['kind']] = ($counts[$entry['kind']]??0)+1;
}
foreach (['claim'=>'sent','closure_first'=>'skipped','inflight'=>'uncertain','crash'=>'running','queue'=>'sent','queue_renewal'=>'sent'] as $kind=>$expected) {
    $delivery = NotificationDelivery::findOrFail($fixtures[$kind]['delivery']);
    requireOutbox($delivery->status===$expected,$kind.' unexpected status '.$delivery->status);
    requireOutbox(($counts[$kind]??0)===($kind==='closure_first'?0:1),$kind.' transport duplicated');
    if (in_array($kind,['closure_first','inflight'],true)) {
        requireOutbox($delivery->encrypted_payload===null,'Closed snapshot not erased');
        requireOutbox(NotificationEvent::findOrFail($delivery->notification_event_id)->encrypted_payload===null,'Closed event not erased');
        requireOutbox(User::withTrashed()->findOrFail($delivery->user_id)->anonymized_at!==null,'Closure did not complete');
        requireOutbox(!DB::table('notification_logs')->where('user_id',$delivery->user_id)->where('target','!=','[anonymized]')->exists(),'Closed target was reintroduced');
    }
    $evidence[$kind] = ['state'=>$delivery->status,'attempts'=>$delivery->attempts,'transports'=>$counts[$kind]??0,'payload_cleared'=>$delivery->encrypted_payload===null];
}
Http::preventStrayRequests();
Http::fake(fn () => throw new RuntimeException('An uncertain delivery attempted external replay'));
$crashed = NotificationDelivery::findOrFail($fixtures['crash']['delivery']);
$crashed->update(['lease_expires_at'=>now()->subSecond()]);
app(DispatchPendingNotificationsAction::class)->execute();
app(ExecuteNotificationDeliveryAction::class)->execute($crashed->id);
requireOutbox($crashed->fresh()->status==='uncertain','Accepted crash was not retained as uncertain');
requireOutbox(DB::table('notification_logs')->where('notification_delivery_id',$crashed->id)->count()===1,'Crash recovery did not record exactly one result');
Http::assertNothingSent();
$evidence['crash']['after_recovery']='uncertain';
$evidence['operations_event_count']=NotificationEvent::where('event_key','callback:concurrent:attention')->count();
requireOutbox($evidence['operations_event_count']===1,'Concurrent source event was duplicated');
requireOutbox(DB::table('jobs')->whereRaw("payload LIKE '%private-notification%'")->count()===0,'PII leaked into wakeup');
file_put_contents(__DIR__.'/result.json',json_encode($evidence,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo json_encode($evidence,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
''')


def run(command, name, timeout=120):
    result = subprocess.run(command, env=env, cwd=repo, capture_output=True, text=True, timeout=timeout)
    (root / name).write_text(result.stdout + result.stderr)
    print(result.stdout + result.stderr, end='', flush=True)
    if result.returncode:
        raise RuntimeError('Failed; evidence: ' + str(root / name))


def worker(mode, kind, slot):
    return subprocess.Popen([php, str(root / 'worker.php'), mode, kind, slot], env=env, cwd=repo,
                            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)


def finish(process, name):
    output, error = process.communicate(timeout=25)
    (root / name).write_text(output + error)
    if process.returncode:
        raise RuntimeError('Worker failed; evidence: ' + str(root / name))


def await_file(name, processes):
    deadline = time.monotonic() + 20
    while not (root / name).exists():
        if time.monotonic() > deadline or any(p.poll() is not None for p in processes):
            for process in processes:
                if process.poll() is not None:
                    output, error = process.communicate()
                    (root / (name + '.failed.log')).write_text(output + error)
                    print(output + error, flush=True)
            raise RuntimeError('Worker failed before barrier ' + name)
        time.sleep(0.01)


try:
    subprocess.run(['docker', 'run', '--detach', '--pull=never', '--name', container,
                    '--label', 'laravel13idc.review=notification-outbox', '--network', 'none',
                    '--tmpfs', '/var/lib/mysql:rw,size=768m',
                    '--mount', 'type=bind,source=' + str(socket_dir) + ',target=/var/run/mysqld',
                    '--env', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes', '--env', 'MYSQL_DATABASE=notification_outbox_only',
                    image, '--performance-schema=OFF', '--innodb-buffer-pool-size=32M', '--mysqlx=OFF', '--skip-log-bin'],
                   check=True, capture_output=True, timeout=20)
    deadline = time.monotonic() + 90
    while True:
        ready = subprocess.run([php, str(root / 'probe.php')], env=env, capture_output=True, text=True, timeout=5)
        stable = subprocess.run(['docker', 'exec', container, 'cat', '/proc/1/comm'], capture_output=True, text=True, timeout=5)
        if ready.returncode == 0 and stable.stdout.strip() == 'mysqld':
            break
        if time.monotonic() >= deadline:
            raise RuntimeError('Dedicated offline MySQL was not ready')
        time.sleep(0.5)
    run([php, 'vendor/bin/pest', '--compact', '--colors=never', 'tests/Feature/NotificationOutboxTest.php'], 'pest.log')
    run([php, 'artisan', 'migrate:fresh', '--force', '--no-interaction'], 'migration.log')
    run([php, str(root / 'setup.php')], 'setup.log')

    a, b = worker('record', 'operations', 'a'), worker('record', 'operations', 'b')
    await_file('operations-a.ready', [a, b])
    await_file('operations-b.ready', [a, b])
    (root / 'operations.start').touch()
    finish(a, 'operations-a.log')
    finish(b, 'operations-b.log')

    a = worker('send', 'claim', 'a')
    await_file('claim.intent', [a])
    b = worker('send', 'claim', 'b')
    finish(b, 'claim-b.log')
    (root / 'claim.release').touch()
    finish(a, 'claim-a.log')

    closer = worker('close_first', 'closure_first', 'closer')
    await_file('closure_first.locked', [closer])
    sender = worker('send', 'closure_first', 'sender')
    await_file('closure_first-sender.ready', [closer, sender])
    (root / 'closure_first.release').touch()
    finish(closer, 'closure-first-closer.log')
    finish(sender, 'closure-first-sender.log')

    sender = worker('send', 'inflight', 'sender')
    await_file('inflight.intent', [sender])
    closer = worker('close', 'inflight', 'closer')
    finish(closer, 'inflight-closer.log')
    (root / 'inflight.release').touch()
    finish(sender, 'inflight-sender.log')

    finish(worker('send', 'crash', 'sender'), 'crash.log')
    finish(worker('queue', 'queue', 'worker'), 'queue-worker.log')
    finish(worker('queue', 'queue_renewal', 'worker'), 'queue-renewal-worker.log')
    run([php, str(root / 'final.php')], 'final.log')
    print('PASS: MySQL event uniqueness, fenced claims, closure races, accepted-result crash recovery, real database queue worker.', flush=True)
finally:
    logs = subprocess.run(['docker', 'logs', container], capture_output=True, text=True, timeout=10)
    (root / 'mysql-container.log').write_text(logs.stdout + logs.stderr)
    subprocess.run(['docker', 'rm', '--force', container], capture_output=True, timeout=20)
    print('Dedicated container removed; retained evidence: ' + str(root), flush=True)
