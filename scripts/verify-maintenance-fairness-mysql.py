"""Verify bounded maintenance infrastructure on disposable offline MySQL/Redis.

Runs the actual maintenance Job, queue worker, Redis state and fair batch with a
synthetic MaintenanceBatchWork implementation. Business Actions are deliberately
not executed. No endpoint/credentials are accepted; cached Docker images only.
"""

import base64
import json
import os
import pathlib
import subprocess
import tempfile
import time
import uuid


def main():
    os.umask(0o077)
    repo = pathlib.Path(__file__).resolve().parents[1]
    php = '/www/server/php/84/bin/php'
    for image in ['mysql:8.0.35', 'redis:7-alpine']:
        subprocess.run(['docker', 'image', 'inspect', image], check=True, capture_output=True, timeout=10)
    root = pathlib.Path(tempfile.mkdtemp(prefix='laravel13idc-maintenance-fairness-'))
    print('Private evidence: ' + str(root), flush=True)
    for folder in ['mysql-socket', 'redis-socket']:
        (root / folder).mkdir(mode=0o777)
        (root / folder).chmod(0o777)
    storage = root / 'storage'
    for folder in ['framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private']:
        (storage / folder).mkdir(parents=True, exist_ok=True)
    suffix = uuid.uuid4().hex[:12]
    containers = {kind: 'laravel13idc-maintenance-' + kind + '-' + suffix for kind in ['mysql', 'redis']}
    password = 'Offline-' + os.urandom(16).hex() + '!'
    clean = {key: value for key, value in os.environ.items() if not key.startswith(
        ('APP_', 'DB_', 'REDIS_', 'LARAVEL_', 'VIEW_', 'QUEUE_', 'CACHE_', 'SESSION_', 'MAIL_', 'LOG_', 'BROADCAST_', 'MAINTENANCE_'))}
    env = dict(clean, APP_ENV='testing', APP_DEBUG='false', APP_URL='https://offline.example.test',
               APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(), BCRYPT_ROUNDS='4',
               CACHE_STORE='redis', MAINTENANCE_CACHE_STORE='redis', SESSION_DRIVER='array', QUEUE_CONNECTION='redis',
               MAIL_MAILER='array', LOG_CHANNEL='single', DB_URL='', DB_CONNECTION='mysql', DB_HOST='localhost', DB_PORT='3306',
               DB_SOCKET=str(root / 'mysql-socket/mysqld.sock'), DB_DATABASE='maintenance_fairness_only',
               DB_USERNAME='maintenance_fixture', DB_PASSWORD=password, REDIS_URL='',
               REDIS_HOST=str(root / 'redis-socket/redis.sock'), REDIS_PORT='0', REDIS_PASSWORD='',
               REDIS_DB='0', REDIS_CACHE_DB='1', REDIS_PREFIX='offline-maintenance:',
               LARAVEL_STORAGE_PATH=str(storage), VIEW_COMPILED_PATH=str(storage / 'framework/views'),
               APP_CONFIG_CACHE=str(storage / 'config.php'), APP_ROUTES_CACHE=str(storage / 'routes.php'),
               APP_EVENTS_CACHE=str(storage / 'events.php'), APP_SERVICES_CACHE=str(storage / 'services.php'),
               APP_PACKAGES_CACHE=str(storage / 'packages.php'), MAINTENANCE_REPO=str(repo), MAINTENANCE_ROOT=str(root))
    harness = root / 'acceptance.php'
    harness.write_text(r'''<?php
declare(strict_types=1);
require getenv('MAINTENANCE_REPO').'/vendor/autoload.php';
$app=require getenv('MAINTENANCE_REPO').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Contracts\MaintenanceBatchWork;
use App\Jobs\RunMaintenanceBatchJob;
use App\Support\FairMaintenanceBatch;
use App\Support\MaintenanceState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
function demand(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
function emit(array $data):void{echo json_encode($data,JSON_THROW_ON_ERROR).PHP_EOL;}
function fixtureMode():string{return trim(file_get_contents(getenv('MAINTENANCE_ROOT').'/mode'));}
class MaintenanceCandidate extends Illuminate\Database\Eloquent\Model{
    protected $table='maintenance_acceptance_candidates';protected $guarded=[];public $timestamps=false;
}
$db=DB::connection();
demand($db->getDriverName()==='mysql'&&$db->getDatabaseName()==='maintenance_fairness_only'
    &&$db->getConfig('unix_socket')===getenv('MAINTENANCE_ROOT').'/mysql-socket/mysqld.sock','Private MySQL only');
demand(config('database.redis.cache.host')===getenv('MAINTENANCE_ROOT').'/redis-socket/redis.sock','Private Redis only');
demand((int)config('queue.connections.redis.retry_after')===240,'Production retry_after must stay 240');
Http::preventStrayRequests();Http::fake(fn()=>throw new RuntimeException('No external HTTP'));Mail::fake();
$app->bind(MaintenanceBatchWork::class,fn()=>new class implements MaintenanceBatchWork{
    public function execute(string $kind):array{
        $mode=fixtureMode();$started=microtime(true);$wait=(int)DB::selectOne('SELECT @@SESSION.innodb_lock_wait_timeout AS seconds')->seconds;
        demand($wait===2,'Actual Job did not set 2 second lock wait');
        $summary=app(FairMaintenanceBatch::class)->execute($kind,MaintenanceCandidate::where('kind',$kind)->where('due',true),'due_at',
            function(int $id)use($kind,$mode):void{
                DB::table('maintenance_acceptance_attempts')->insert(['kind'=>$kind,'candidate_id'=>$id,'at'=>microtime(true),'mode'=>$mode]);
                if($mode==='interrupt'){
                    file_put_contents(getenv('MAINTENANCE_ROOT').'/interrupt.ready',(string)$id);
                    while(true)usleep(100000);
                }
                DB::transaction(function()use($id,$kind,$mode):void{
                    $row=DB::table('maintenance_acceptance_candidates')->where('id',$id)->lockForUpdate()->first();
                    if($mode==='poison'&&$row->position<=100)throw new DomainException('Synthetic poison');
                    DB::table('maintenance_acceptance_successes')->insert(['kind'=>$kind,'candidate_id'=>$id,'at'=>microtime(true),'mode'=>$mode]);
                });
            });
        $elapsed=microtime(true)-$started;
        demand($summary['units']<=100,'Exceeded 100 units');
        demand($elapsed<8,'Soft five-second batch did not stop after current 2-second lock wait');
        DB::table('maintenance_acceptance_batches')->insert(['kind'=>$kind,'mode'=>$mode,'started'=>$started,'elapsed'=>$elapsed,
            'units'=>$summary['units'],'attempted'=>$summary['attempted'],'failed'=>$summary['failed'],'summary'=>json_encode($summary)]);
        return $summary;
    }
});
$mode=$argv[1];
if($mode==='seed'){
    demand(Schema::getTableListing(DB::getDatabaseName(),false)===[],'Empty isolated database required');
    $m=app(Illuminate\Database\Migrations\Migrator::class);$m->getRepository()->createRepository();
    $m->run([getenv('MAINTENANCE_REPO').'/database/migrations'],['force'=>true]);
    Schema::create('maintenance_acceptance_candidates',function($t):void{$t->id();$t->string('kind');$t->integer('position');$t->boolean('due');$t->timestamp('due_at');$t->index(['kind','due','id']);});
    foreach(['attempts','successes']as$table)Schema::create('maintenance_acceptance_'.$table,function($t):void{$t->id();$t->string('kind');$t->bigInteger('candidate_id');$t->double('at');$t->string('mode');});
    Schema::create('maintenance_acceptance_batches',function($t):void{$t->id();$t->string('kind');$t->string('mode');$t->double('started');$t->double('elapsed');$t->integer('units');$t->integer('attempted');$t->integer('failed');$t->text('summary');});
    $rows=[];foreach(config('maintenance.kinds')as$kind)for($p=1;$p<=160;$p++)$rows[]=['kind'=>$kind,'position'=>$p,'due'=>$p!==1,'due_at'=>now()->subHour()];
    foreach(array_chunk($rows,300)as$chunk)DB::table('maintenance_acceptance_candidates')->insert($chunk);
    emit(['families'=>count(config('maintenance.kinds')),'candidates'=>count($rows),'retry_after'=>config('queue.connections.redis.retry_after')]);
}elseif($mode==='dispatch'){
    foreach(config('maintenance.kinds')as$kind)Artisan::call('maintenance:dispatch',['kind'=>$kind]);emit(['queued'=>8]);
}elseif($mode==='worker'){
    exit(Artisan::call('queue:work',['connection'=>'redis','--queue'=>'maintenance','--sleep'=>1,'--tries'=>1,'--timeout'=>25,'--stop-when-empty'=>true,'--max-time'=>115]));
}elseif(in_array($mode,['reset','reset-all'],true)){
    Redis::connection('cache')->command($mode==='reset-all'?'flushall':'flushdb');
    DB::table('maintenance_acceptance_candidates')->update(['due'=>true]);
    emit(['reset'=>true]);
}elseif($mode==='hold'){
    DB::beginTransaction();DB::table('maintenance_acceptance_candidates')->where('position','<=',100)->lockForUpdate()->get();
    file_put_contents(getenv('MAINTENANCE_ROOT').'/locks.ready','ready');$deadline=microtime(true)+115;
    while(!file_exists(getenv('MAINTENANCE_ROOT').'/locks.release')&&microtime(true)<$deadline)usleep(100000);
    DB::rollBack();emit(['released'=>true]);
}elseif($mode==='old-due'){
    DB::table('maintenance_acceptance_candidates')->where('position',1)->update(['due'=>true]);
    foreach(config('maintenance.kinds')as$kind)app(MaintenanceState::class)->advance($kind,999999);
    emit(['eligible_old_ids'=>8]);
}elseif($mode==='drop-cursor'){
    foreach(config('maintenance.kinds')as$kind)app(MaintenanceState::class)->store()->forget('maintenance:cursor:'.$kind);
    emit(['cursor_keys_removed'=>8]);
}elseif($mode==='interrupt-dispatch'){
    RunMaintenanceBatchJob::dispatch('invoices');emit(['queued'=>1]);
}elseif($mode==='summary'){
    $rows=DB::table('maintenance_acceptance_batches')->get()->map(fn($r)=>(array)$r)->all();
    $completed=[];foreach(config('maintenance.kinds')as$kind)$completed[$kind]=app(MaintenanceState::class)->snapshot($kind);
    emit(['batches'=>$rows,'heartbeat'=>$completed,'attempts'=>DB::table('maintenance_acceptance_attempts')->count(),
        'successes'=>DB::table('maintenance_acceptance_successes')->count(),'failed_jobs'=>DB::table('failed_jobs')->count(),
        'cursors'=>array_combine(config('maintenance.kinds'),array_map(fn($kind)=>app(MaintenanceState::class)->cursor($kind),config('maintenance.kinds'))),
        'old_eligible_seen'=>DB::table('maintenance_acceptance_successes as s')->join('maintenance_acceptance_candidates as c','c.id','=','s.candidate_id')->where('s.mode','old-due')->where('c.position',1)->distinct()->count('s.kind')]);
}else throw new RuntimeException('Unsupported mode');
''')

    def run(mode, timeout=140):
        process = subprocess.run([php, str(harness), mode], cwd=repo, env=env, capture_output=True, text=True, timeout=timeout)
        (root / (mode + '-' + uuid.uuid4().hex[:6] + '.log')).write_text(process.stdout + process.stderr)
        if process.returncode:
            raise RuntimeError(mode + ' failed; inspect private logs in ' + str(root))
        return json.loads(process.stdout) if process.stdout.strip() else None

    def workers(label):
        started = time.monotonic()
        processes = [subprocess.Popen([php, str(harness), 'worker'], cwd=repo, env=env,
                                      stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) for _ in range(2)]
        try:
            for i, process in enumerate(processes):
                stdout, stderr = process.communicate(timeout=max(1, 120-(time.monotonic()-started)))
                (root / (label + '-worker-' + str(i) + '.log')).write_text(stdout + stderr)
                if process.returncode:
                    raise RuntimeError('Actual maintenance worker failed: ' + label)
            elapsed = time.monotonic()-started
            if elapsed >= 120:
                raise RuntimeError('Eight family opportunity exceeded 120 seconds')
            print(label + ': two real workers finished in ' + str(round(elapsed, 3)) + 's', flush=True)
            return elapsed
        finally:
            for process in processes:
                if process.poll() is None:
                    process.kill()
                    process.communicate(timeout=5)

    def sql(statement):
        subprocess.run(['docker', 'exec', '-i', containers['mysql'], 'mysql', '-uroot'], input=statement.encode(),
                       check=True, capture_output=True, timeout=30)

    created = []
    holder = None
    interrupted = None
    try:
        subprocess.run(['docker', 'run', '--detach', '--pull=never', '--name', containers['mysql'], '--network', 'none',
                        '--tmpfs', '/var/lib/mysql:rw,size=1536m', '--mount', 'type=bind,source=' + str(root / 'mysql-socket') + ',target=/var/run/mysqld',
                        '--env', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes', 'mysql:8.0.35', '--performance-schema=OFF',
                        '--innodb-buffer-pool-size=128M', '--mysqlx=OFF', '--skip-log-bin'], check=True, capture_output=True, timeout=20)
        created.append(containers['mysql'])
        subprocess.run(['docker', 'run', '--detach', '--pull=never', '--name', containers['redis'], '--network', 'none',
                        '--mount', 'type=bind,source=' + str(root / 'redis-socket') + ',target=/offline-socket', 'redis:7-alpine',
                        'redis-server', '--port', '0', '--unixsocket', '/offline-socket/redis.sock', '--unixsocketperm', '777',
                        '--save', '', '--appendonly', 'no'], check=True, capture_output=True, timeout=20)
        created.append(containers['redis'])
        deadline = time.monotonic()+90
        while True:
            probe = subprocess.run(['docker', 'exec', containers['mysql'], 'mysql', '-uroot', '-Nse', 'SELECT VERSION()'], capture_output=True, text=True, timeout=5)
            redis = subprocess.run(['docker', 'exec', containers['redis'], 'redis-cli', '-s', '/offline-socket/redis.sock', 'ping'], capture_output=True, text=True, timeout=5)
            mysql_process = subprocess.run(['docker', 'exec', containers['mysql'], 'cat', '/proc/1/comm'], capture_output=True, text=True, timeout=5)
            if probe.returncode == 0 and mysql_process.stdout.strip() == 'mysqld' and redis.stdout.strip() == 'PONG':
                break
            if time.monotonic() >= deadline:
                raise RuntimeError('Offline database startup timeout')
            time.sleep(0.5)
        for container in created:
            inspect = json.loads(subprocess.run(['docker', 'inspect', container], check=True, capture_output=True, text=True, timeout=10).stdout)[0]
            if inspect['HostConfig']['NetworkMode'] != 'none' or inspect['HostConfig']['PortBindings']:
                raise RuntimeError('Offline isolation failed')
        sql("CREATE DATABASE maintenance_fairness_only; CREATE USER 'maintenance_fixture'@'localhost' IDENTIFIED BY '" + password
            + "'; GRANT ALL ON maintenance_fairness_only.* TO 'maintenance_fixture'@'localhost';")
        seed = run('seed')
        durations = {}
        (root / 'mode').write_text('poison')
        run('dispatch'); durations['poison_first'] = workers('poison-first')
        run('dispatch'); durations['poison_rotation'] = workers('poison-rotation')
        poison = run('summary')
        poison_rows = [r for r in poison['batches'] if r['mode'] == 'poison']
        if len(poison_rows) != 16 or not all(r['failed'] >= 99 for r in poison_rows[:8]):
            raise RuntimeError('Poisoned leading IDs did not produce expected bounded failures')
        if not all(h.get('succeeded', 0) >= 50 for h in poison['heartbeat'].values()):
            raise RuntimeError('Rotation did not pass the poisoned prefix')
        (root / 'mode').write_text('old-due'); run('old-due'); run('dispatch'); durations['old_due'] = workers('old-due')
        old = run('summary')
        if old['old_eligible_seen'] != 8:
            raise RuntimeError('Old IDs becoming eligible were missed')
        run('drop-cursor'); run('dispatch'); durations['cursor_loss'] = workers('cursor-loss')
        run('reset'); (root / 'mode').write_text('locked')
        holder = subprocess.Popen([php, str(harness), 'hold'], cwd=repo, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        deadline = time.monotonic()+10
        while not (root / 'locks.ready').exists():
            if holder.poll() is not None or time.monotonic()>deadline:
                raise RuntimeError('Real InnoDB row locks were not acquired')
            time.sleep(0.05)
        run('dispatch'); durations['locked_first_round'] = workers('locked')
        locked = run('summary')
        locked_rows = [r for r in locked['batches'] if r['mode'] == 'locked']
        if len(locked_rows)!=8 or not all(3<=r['attempted']<=4 and 5<=r['elapsed']<8 and r['failed']==r['attempted'] for r in locked_rows):
            raise RuntimeError('Real lock-wait behavior did not respect bounded progress')
        (root / 'locks.release').write_text('release'); holder.communicate(timeout=5); holder=None
        run('reset'); (root / 'mode').write_text('interrupt'); run('interrupt-dispatch')
        interrupted = subprocess.Popen([php, str(harness), 'worker'], cwd=repo, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        deadline=time.monotonic()+10
        while not (root / 'interrupt.ready').exists():
            if interrupted.poll() is not None or time.monotonic()>deadline:
                raise RuntimeError('Worker did not reach interrupt checkpoint')
            time.sleep(0.05)
        interrupted.kill(); interrupted.communicate(timeout=5); interrupted=None
        checkpoint=run('summary')
        interrupted_id=int((root / 'interrupt.ready').read_text())
        if checkpoint['cursors']['invoices']!=interrupted_id:
            raise RuntimeError('Interrupt did not retain attempted rotation cursor')
        # A separate complete Redis-loss recovery round also removes the interrupted
        # queue reservation and atomic locks (Redis lock_connection uses DB 0).
        # Cursor-only loss was already exercised separately above. Original lease
        # redelivery after 240 seconds is outside this acceptance run.
        run('reset-all'); (root / 'mode').write_text('recovery'); run('dispatch'); durations['redis_loss_after_kill']=workers('redis-loss-after-kill')
        final=run('summary')
        if final['failed_jobs']!=0 or not all(h.get('attempted',0)==100 for h in final['heartbeat'].values()):
            raise RuntimeError('Cache-loss recovery did not rescan authoritative candidates')
        result={'status':'PASS','mysql_version':probe.stdout.strip(),'redis':'7-alpine','network':'none',
                'scope':'actual Job/Redis/two workers/FairMaintenanceBatch, synthetic nonbusiness consumer',
                'seed':seed,'durations_seconds':durations,'poison':poison,'old_due':old,'row_locks':locked,
                'killed_worker_checkpoint':checkpoint,'killed_candidate_id':interrupted_id,'cache_loss_recovery':final,
                'real_business_actions':0,'original_preview_workers_started':0,
                'limits':{'units':100,'soft_seconds':5,'observed_row_lock_batch_ceiling_seconds':8,'timeout':25,'retry_after':240},
                'interrupted_original_reservation':'removed by explicit isolated Redis-loss test; 240 second redelivery was not tested'}
        (root / 'result.json').write_text(json.dumps(result,indent=2))
        print('PASS: poison prefix, later-due old IDs, lost cursors, real row locks and killed-worker cache-loss recovery.',flush=True)
    finally:
        for process in [holder,interrupted]:
            if process is not None and process.poll() is None:
                process.kill();process.communicate(timeout=5)
        cleanup=[]
        for container in created:
            logs=subprocess.run(['docker','logs',container],capture_output=True,text=True,timeout=10)
            (root / (container+'.log')).write_text(logs.stdout+logs.stderr)
            removed=subprocess.run(['docker','rm','--force',container],capture_output=True,timeout=20)
            cleanup.append({'container':container,'removed':removed.returncode==0})
        (root / 'cleanup.json').write_text(json.dumps(cleanup))
        for path in root.rglob('*'):
            if path.is_file():path.chmod(0o600)
        print('Disposable containers removed; private evidence retained: '+str(root),flush=True)


if __name__=='__main__':
    main()
