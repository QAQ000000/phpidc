"""Exercise N06 on synthetic 10000-root / 100000-evidence history in offline MySQL/Redis.

Requires cached mysql:8.0.35 and redis:7-alpine images. Accepts no database,
credentials, socket or external endpoint. Containers have network=none and no
host ports; HTTP, mail and queue transport are faked. Evidence stays private in
/tmp. Tests actual withdrawal/approval Actions and a concurrent real reversal.
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
    root = pathlib.Path(tempfile.mkdtemp(prefix='laravel13idc-receivable-capacity-'))
    print('Private evidence: ' + str(root), flush=True)
    for folder in ['mysql-socket', 'redis-socket']:
        (root / folder).mkdir(mode=0o777)
        (root / folder).chmod(0o777)
    storage = root / 'storage'
    for folder in ['framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private']:
        (storage / folder).mkdir(parents=True, exist_ok=True)
    suffix = uuid.uuid4().hex[:12]
    containers = {'mysql': 'laravel13idc-capacity-mysql-' + suffix, 'redis': 'laravel13idc-capacity-redis-' + suffix}
    password = 'Offline-' + os.urandom(16).hex() + '!'
    clean = {key: value for key, value in os.environ.items() if not key.startswith(
        ('APP_', 'DB_', 'REDIS_', 'LARAVEL_', 'VIEW_', 'QUEUE_', 'CACHE_', 'SESSION_', 'MAIL_', 'LOG_', 'BROADCAST_'))}
    env = dict(clean, APP_ENV='testing', APP_DEBUG='false', APP_URL='https://offline.example.test',
               APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(), BCRYPT_ROUNDS='4',
               CACHE_STORE='redis', SESSION_DRIVER='array', QUEUE_CONNECTION='database', MAIL_MAILER='array', LOG_CHANNEL='single',
               DB_URL='', DB_CONNECTION='mysql', DB_HOST='localhost', DB_PORT='3306',
               DB_SOCKET=str(root / 'mysql-socket/mysqld.sock'), DB_DATABASE='receivable_capacity_only',
               DB_USERNAME='capacity_fixture', DB_PASSWORD=password, REDIS_URL='', REDIS_HOST=str(root / 'redis-socket/redis.sock'),
               REDIS_PORT='0', REDIS_PASSWORD='', REDIS_DB='0', REDIS_CACHE_DB='1', REDIS_PREFIX='offline-capacity:',
               LARAVEL_STORAGE_PATH=str(storage), VIEW_COMPILED_PATH=str(storage / 'framework/views'),
               APP_CONFIG_CACHE=str(storage / 'config.php'), APP_ROUTES_CACHE=str(storage / 'routes.php'),
               APP_EVENTS_CACHE=str(storage / 'events.php'), APP_SERVICES_CACHE=str(storage / 'services.php'),
               APP_PACKAGES_CACHE=str(storage / 'packages.php'), CAPACITY_REPO=str(repo), CAPACITY_ROOT=str(root))
    harness = root / 'acceptance.php'
    harness.write_text(r'''<?php
declare(strict_types=1);
require getenv('CAPACITY_REPO').'/vendor/autoload.php';
$app=require getenv('CAPACITY_REPO').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function(Throwable $error):void{fwrite(STDERR,(string)$error.PHP_EOL);exit(20);});
use App\Models\User;
use App\Models\Invoice;
use App\Models\FinancialTransaction;
use App\Models\AffiliateReceivableSettlement;
use App\Models\AffiliateWithdrawal;
use App\Services\ReceivableCheckRequests;
use App\Services\ReceivableSnapshot;
use App\Jobs\RunReceivableCheckJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
function demand(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function output(array $data):void{
    $values=$GLOBALS['capacity_lock_ms']??[];sort($values);$count=count($values);
    $data['financial_lock_ms']=['samples'=>$count,'p95'=>$count?$values[(int)ceil($count*0.95)-1]:null,
        'p99'=>$count?$values[(int)ceil($count*0.99)-1]:null,'max'=>$count?max($values):null];
    echo json_encode($data,JSON_THROW_ON_ERROR).PHP_EOL;
}
function state():array{return json_decode(file_get_contents(getenv('CAPACITY_ROOT').'/state.json'),true,flags:JSON_THROW_ON_ERROR);}
function save(array $data):void{file_put_contents(getenv('CAPACITY_ROOT').'/state.json',json_encode($data,JSON_THROW_ON_ERROR));}
function ulid(int $id):string{return str_pad((string)$id,26,'0',STR_PAD_LEFT);}
function batch(string $table,array $rows):void{foreach(array_chunk($rows,500)as$chunk)DB::table($table)->insert($chunk);}
function job(array $state):array{
    $started=hrtime(true);$queries=0;$sqlms=0.0;
    DB::listen(function($query)use(&$queries,&$sqlms):void{$queries++;$sqlms+=$query->time;});
    $job=new RunReceivableCheckJob($state['user_id'],$state['action'],$state['request_id'],$state['intent_hmac']);
    $job->handle(app(ReceivableCheckRequests::class),app(ReceivableSnapshot::class));
    (new Illuminate\Bus\UniqueLock(Illuminate\Support\Facades\Cache::store('redis')))->release($job);
    $result=app(ReceivableCheckRequests::class)->get($state['user_id'],$state['action'],$state['request_id'],$state['intent_hmac']);
    demand(($result['status']??null)==='ready','Long check did not produce a ready result');
    $elapsed=(hrtime(true)-$started)/1000000;
    demand($elapsed<90000,'Long check exceeded 90 second budget');
    return ['elapsed_ms'=>$elapsed,'sql_count'=>$queries,'sql_ms'=>$sqlms,'peak_bytes'=>memory_get_peak_usage(true),
        'revision'=>$result['scan']['revision'],'digest'=>$result['scan']['digest'],'totals'=>$result['scan']['totals'],
        'valid'=>$result['scan']['valid'],'has_outstanding'=>$result['scan']['has_outstanding']];
}
function continueOperation(Closure $operation):array{
    $checks=[];
    for($attempt=0;$attempt<4;$attempt++){
        try{return ['value'=>$operation(),'checks'=>$checks];}
        catch(Illuminate\Validation\ValidationException $error){
            demand(isset($error->errors()['receivable_check']),'Unexpected validation: '.json_encode($error->errors()));
            $jobs=Queue::pushed(RunReceivableCheckJob::class);demand($jobs->isNotEmpty(),'No receivable-check job was queued');
            $pending=$jobs->last();$requests=app(ReceivableCheckRequests::class);
            $queued=$requests->get($pending->userId,$pending->action,$pending->requestId,$pending->intentHmac);
            demand(($queued['status']??null)==='queued','Original request did not preserve queued state');
            $checks[]=job($queued);
        }
    }throw new RuntimeException('Original operation did not finish after complete checks');
}
$c=DB::connection();
demand($c->getDriverName()==='mysql'&&$c->getDatabaseName()==='receivable_capacity_only'
    &&$c->getConfig('unix_socket')===getenv('CAPACITY_ROOT').'/mysql-socket/mysqld.sock','Private MySQL only');
demand(config('database.redis.cache.host')===getenv('CAPACITY_ROOT').'/redis-socket/redis.sock','Private Redis only');
Http::preventStrayRequests();Http::fake(fn()=>throw new RuntimeException('External HTTP forbidden'));
Illuminate\Support\Facades\Mail::fake();Queue::fake();
config(['receivables.cache_store'=>'redis']);
// Snapshot must remain correct even if this deployment normally chooses READ COMMITTED.
$c->statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
$GLOBALS['capacity_lock_ms']=[];$GLOBALS['capacity_lock_started']=null;
DB::listen(function($query):void{
    if($GLOBALS['capacity_lock_started']===null&&str_contains($query->sql,'`users`')&&str_contains(strtolower($query->sql),'for update'))
        $GLOBALS['capacity_lock_started']=hrtime(true);
});
$finishLock=function($event):void{
    if($event->connection->transactionLevel()===0&&$GLOBALS['capacity_lock_started']!==null){
        $GLOBALS['capacity_lock_ms'][]=(hrtime(true)-$GLOBALS['capacity_lock_started'])/1000000;$GLOBALS['capacity_lock_started']=null;
    }
};
Illuminate\Support\Facades\Event::listen(Illuminate\Database\Events\TransactionCommitted::class,$finishLock);
Illuminate\Support\Facades\Event::listen(Illuminate\Database\Events\TransactionRolledBack::class,$finishLock);
$mode=$argv[1];
if($mode==='seed'){
    demand(Illuminate\Support\Facades\Schema::getTableListing(DB::getDatabaseName(),false)===[],'Fixture database must be empty');
    $m=app(Illuminate\Database\Migrations\Migrator::class);$m->getRepository()->createRepository();$m->run([getenv('CAPACITY_REPO').'/database/migrations'],['force'=>true]);
    $owner=User::factory()->create(['credit'=>'17.00','affiliate_balance'=>'100.00']);
    $actor=User::factory()->create();foreach(['finance-operator','finance-receivable','finance-payout']as$role)
        app(App\Actions\AssignAdminRoleAction::class)->execute($actor,$role,'Synthetic capacity acceptance');
    $buyer=User::factory()->create();$product=App\Models\Product::factory()->create();
    $invoiceTemplate=Invoice::factory()->paid()->make(['user_id'=>$buyer->id,'product_id'=>$product->id,'status'=>App\Enums\InvoiceStatus::REFUNDED])->getAttributes();
    $now=now()->startOfSecond()->toDateTimeString();$started=hrtime(true);
    for($base=0;$base<10000;$base+=100){
        $invoices=[];$commissions=[];$roots=[];$ledgers=[];$settlements=[];$reversals=[];
        for($offset=0;$offset<100;$offset++){
            $i=$base+$offset+1;$invoice=array_replace($invoiceTemplate,['id'=>$i,'invoice_no'=>'OFFLINE-CAPACITY-'.$i,'created_at'=>$now,'updated_at'=>$now]);
            $invoices[]=$invoice;$commissions[]=['id'=>$i,'referrer_id'=>$owner->id,'buyer_id'=>$buyer->id,'invoice_id'=>$i,
                'rate_snapshot'=>'20.00','amount'=>'2.00','status'=>'clawed_back','available_at'=>$now,'created_at'=>$now,'updated_at'=>$now];
            $roots[]=['id'=>$i,'user_id'=>$owner->id,'invoice_id'=>$i,'type'=>'affiliate_clawback','amount'=>'2.00','balance_after'=>'17.00',
                'frozen_after'=>'0.00','reference_key'=>'clawback:'.$i,'meta'=>json_encode(['commission_id'=>$i,'currency'=>'CNY','previous_status'=>'available',
                    'receivable_amount'=>'2.00','recovered_amount'=>'0.00'],JSON_THROW_ON_ERROR),'created_at'=>$now,'updated_at'=>$now];
            for($j=0;$j<6;$j++){
                $sid=($i-1)*6+$j+1;$lid=10000+($i-1)*10+$j+1;$request=ulid(1000000+$sid);$ref='OFFLINE-RECEIPT-'.$sid;
                $settlements[]=['id'=>$sid,'user_id'=>$owner->id,'clawback_transaction_id'=>$i,'invoice_id'=>$i,'amount'=>'1.00','currency'=>'CNY',
                    'source'=>'external_received','external_channel'=>'bank','external_reference'=>$ref,
                    'active_receipt_key'=>$j<4?null:App\Services\AffiliateReceivableBalance::receiptKey('bank',$ref),
                    'request_id'=>$request,'ledger_transaction_id'=>$lid,'processed_by'=>$actor->id,'reason'=>'Synthetic completed collection',
                    'received_at'=>$now,'reversed_at'=>$j<4?$now:null,'created_at'=>$now,'updated_at'=>$now];
                $ledgers[]=['id'=>$lid,'user_id'=>$owner->id,'invoice_id'=>$i,'type'=>'affiliate_receivable_settle','amount'=>'1.00',
                    'balance_after'=>'17.00','frozen_after'=>'0.00','reference_key'=>'receivable_settle:'.$request,
                    'meta'=>json_encode(['currency'=>'CNY','clawback_transaction_id'=>$i,'request_id'=>$request,'source'=>'external_received','no_wallet_movement'=>true],JSON_THROW_ON_ERROR),
                    'created_at'=>$now,'updated_at'=>$now];
                if($j<4){
                    $rid=($i-1)*4+$j+1;$rlid=10000+($i-1)*10+7+$j;$rrequest=ulid(2000000+$rid);
                    $reversals[]=['id'=>$rid,'settlement_id'=>$sid,'user_id'=>$owner->id,'clawback_transaction_id'=>$i,'invoice_id'=>$i,'amount'=>'1.00',
                        'currency'=>'CNY','request_id'=>$rrequest,'ledger_transaction_id'=>$rlid,'processed_by'=>$actor->id,
                        'reason'=>'Synthetic correction','correction_reference'=>'OFFLINE-CORRECTION-'.$rid,'created_at'=>$now,'updated_at'=>$now];
                    $ledgers[]=['id'=>$rlid,'user_id'=>$owner->id,'invoice_id'=>$i,'type'=>'affiliate_receivable_reverse','amount'=>'1.00',
                        'balance_after'=>'17.00','frozen_after'=>'0.00','reference_key'=>'receivable_reverse:'.$rrequest,
                        'meta'=>json_encode(['currency'=>'CNY','clawback_transaction_id'=>$i,'settlement_id'=>$sid,'request_id'=>$rrequest,'no_wallet_movement'=>true],JSON_THROW_ON_ERROR),
                        'created_at'=>$now,'updated_at'=>$now];
                }
            }
        }
        DB::transaction(function()use($invoices,$commissions,$roots,$ledgers,$settlements,$reversals):void{
            batch('invoices',$invoices);batch('affiliate_commissions',$commissions);batch('financial_transactions',$roots);
            batch('financial_transactions',$ledgers);batch('affiliate_receivable_settlements',$settlements);batch('affiliate_receivable_reversals',$reversals);
        });
    }
    DB::transaction(function()use($owner):void{$locked=User::whereKey($owner->id)->lockForUpdate()->firstOrFail();app(App\Support\ReceivableRevision::class)->bump($locked);});
    save(['owner'=>$owner->id,'actor'=>$actor->id,'request'=>(string)Str::ulid(),'race_request'=>(string)Str::ulid()]);
    output(['roots'=>DB::table('financial_transactions')->where('type','affiliate_clawback')->count(),
        'settlements'=>DB::table('affiliate_receivable_settlements')->count(),'reversals'=>DB::table('affiliate_receivable_reversals')->count(),
        'evidence_ledgers'=>DB::table('financial_transactions')->whereIn('type',['affiliate_receivable_settle','affiliate_receivable_reverse'])->count(),
        'seed_ms'=>(hrtime(true)-$started)/1000000,'migrations'=>DB::table('migrations')->count()]);
}elseif($mode==='pagination'){
    $page=new class extends App\Filament\Pages\FinanceReceivables{public function pageData():array{return $this->getViewData();}};
    $samples=[];$queries=0;DB::listen(function()use(&$queries):void{$queries++;});
    for($i=0;$i<20;$i++){$start=hrtime(true);$data=$page->pageData();$samples[]=(hrtime(true)-$start)/1000000;
        demand($data['roots']->count()===20&&$data['projections']->count()===20,'Actual receivable page data was incomplete');}
    sort($samples);$p95=$samples[18];$p99=$samples[19];demand($p95<=500,'Receivable page data exceeded 500ms P95');
    output(['layer'=>'actual FinanceReceivables getViewData; HTTP/render excluded','samples'=>20,'rows_per_page'=>20,
        'p95_ms'=>$p95,'p99_ms'=>$p99,'sql_count'=>$queries,'peak_bytes'=>memory_get_peak_usage(true)]);
}else if($mode==='complete'){
    $data=state();$owner=User::findOrFail($data['owner']);
    $request=continueOperation(fn()=>app(App\Actions\RequestAffiliateWithdrawalAction::class)->execute($owner,'1.00',$data['request']));
    $withdrawal=$request['value'];$data['withdrawal']=$withdrawal->id;save($data);
    $approve=continueOperation(function()use($withdrawal,$data):bool{
        app(App\Actions\ProcessAffiliateWithdrawalAction::class)->execute($withdrawal,User::findOrFail($data['actor']),true,'Offline capacity actual money action');return true;});
    $owner->refresh();demand($owner->credit==='18.00'&&$owner->affiliate_balance==='99.00','Actual approved money action did not apply');
    demand(FinancialTransaction::where('reference_key','payout:'.$withdrawal->id)->count()===1,'Payout ledger was duplicated or missing');
    app(App\Actions\ProcessAffiliateWithdrawalAction::class)->execute($withdrawal,User::findOrFail($data['actor']),true,'Offline capacity actual money action');
    demand(FinancialTransaction::where('reference_key','payout:'.$withdrawal->id)->count()===1,'Idempotent approval duplicated money');
    Http::assertNothingSent();output(['request_checks'=>$request['checks'],'approval_checks'=>$approve['checks'],'withdrawal_status'=>$withdrawal->fresh()->status,
        'wallet_credit'=>$owner->credit,'affiliate_balance'=>$owner->affiliate_balance,'unique_payout_ledgers'=>1,'real_external_actions'=>0]);
}elseif($mode==='race-prepare'){
    $data=state();$requests=app(ReceivableCheckRequests::class);
    try{app(App\Actions\RequestAffiliateWithdrawalAction::class)->execute(User::findOrFail($data['owner']),'1.00',$data['race_request']);
        throw new RuntimeException('Capacity fixture unexpectedly completed within foreground budget');}
    catch(Illuminate\Validation\ValidationException $error){demand(isset($error->errors()['receivable_check']),'Race preparation must queue the original Action');}
    $pending=Queue::pushed(RunReceivableCheckJob::class)->last();demand($pending!==null,'Race preparation queued no check');
    $check=job($requests->get($pending->userId,$pending->action,$pending->requestId,$pending->intentHmac));
    demand($check['valid']&&!$check['has_outstanding'],'Race requires a genuine clear result');output($check);
}elseif($mode==='race-reader'){
    $data=state();$barrier=false;$started=hrtime(true);
    DB::connection()->beforeExecuting(function(string $sql)use(&$barrier):void{
        if(!$barrier&&str_contains($sql,'`users`')&&str_contains(strtolower($sql),'for update')){
            $barrier=true;file_put_contents(getenv('CAPACITY_ROOT').'/reader.ready',(string)getmypid());$deadline=microtime(true)+100;
            while(!is_file(getenv('CAPACITY_ROOT').'/writer.done')){demand(microtime(true)<$deadline,'Writer barrier timed out');usleep(10000);}
        }
    });
    $outcome='unexpected_release';try{app(App\Actions\RequestAffiliateWithdrawalAction::class)->execute(User::findOrFail($data['owner']),'1.00',$data['race_request']);}
    catch(Illuminate\Validation\ValidationException $error){if(!$barrier)throw $error;$outcome=isset($error->errors()['receivable_check'])?'recheck_queued':'debt_blocked';}
    demand($barrier&&$outcome!=='unexpected_release','A stale clear permit released funds');
    demand(!AffiliateWithdrawal::where('request_key',$data['owner'].':'.$data['race_request'])->exists(),'Stale permit created withdrawal');
    output(['pid'=>getmypid(),'actual_owner_lock_barrier'=>$barrier,'result'=>$outcome,'elapsed_ms'=>(hrtime(true)-$started)/1000000,'stale_release'=>false]);
}elseif($mode==='race-writer'){
    $data=state();$entry=AffiliateReceivableSettlement::findOrFail(6);$root=FinancialTransaction::findOrFail($entry->clawback_transaction_id);
    $version=app(App\Services\AffiliateReceivableBalance::class)->project($root)['version'];
    $before=(string)User::findOrFail($data['owner'])->receivable_revision;
    $input=['request_id'=>(string)Str::ulid(),'reason'=>'Offline concurrent real correction','correction_reference'=>'OFFLINE-RACE-CORRECTION','confirm'=>'1','version'=>$version];
    $result=continueOperation(fn()=>app(App\Actions\ReverseAffiliateReceivableSettlementAction::class)->execute($entry,User::findOrFail($data['actor']),$input));
    $after=(string)User::findOrFail($data['owner'])->receivable_revision;demand($before!==$after,'Real reversal failed to bump evidence revision');
    $data['race_root']=$root->id;save($data);file_put_contents(getenv('CAPACITY_ROOT').'/writer.done','done');
    output(['pid'=>getmypid(),'real_reversal'=>$result['value']->id,'revision_before'=>$before,'revision_after'=>$after,'checks'=>$result['checks']]);
}elseif($mode==='race-recover'){
    $data=state();$owner=User::findOrFail($data['owner']);$requests=app(ReceivableCheckRequests::class);
    $state=$requests->get($owner->id,'affiliate-wallet-request',$data['race_request']);
    if(($state['status']??null)==='queued'){$blocked=job($state);demand($blocked['valid']&&$blocked['has_outstanding'],'Changed debt disappeared');}
    else $blocked=null;
    $root=FinancialTransaction::findOrFail($data['race_root']);$version=app(App\Services\AffiliateReceivableBalance::class)->project($root)['version'];
    $input=['amount'=>'1.00','source'=>'external_received','request_id'=>(string)Str::ulid(),'external_channel'=>'bank',
        'external_reference'=>'OFFLINE-RACE-RECOLLECTED','received_at'=>now()->subMinute()->toDateTimeString(),
        'reason'=>'Offline actual recollection registration','confirm'=>'1','version'=>$version];
    $collection=continueOperation(fn()=>app(App\Actions\RecordAffiliateReceivableSettlementAction::class)->execute($root,User::findOrFail($data['actor']),$input));
    $retry=continueOperation(fn()=>app(App\Actions\RequestAffiliateWithdrawalAction::class)->execute($owner,'1.00',$data['race_request']));
    demand($retry['value']->status==='pending','Original stable request did not recover');
    $approval=continueOperation(function()use($retry,$data):bool{app(App\Actions\ProcessAffiliateWithdrawalAction::class)->execute($retry['value'],User::findOrFail($data['actor']),true,'Offline race recovered money action');return true;});
    $owner->refresh();demand($owner->credit==='19.00'&&$owner->affiliate_balance==='98.00','Recomputed original operation did not complete');
    demand(FinancialTransaction::where('type',App\Enums\TransactionType::AFFILIATE_PAYOUT)->count()===2,'Payout count incorrect after race recovery');
    output(['blocked_snapshot'=>$blocked,'collection_checks'=>$collection['checks'],'fresh_request_checks'=>$retry['checks'],'approval_checks'=>$approval['checks'],
        'original_request_completed'=>true,'wallet_credit'=>$owner->credit,'affiliate_balance'=>$owner->affiliate_balance,'payout_ledgers'=>2]);
}else throw new RuntimeException('Unsupported mode');
''')

    def run(mode, timeout=180):
        process = subprocess.run([php, '-d', 'memory_limit=512M', str(harness), mode], cwd=repo, env=env,
                                 capture_output=True, text=True, timeout=timeout)
        (root / (mode + '.log')).write_text(process.stdout + process.stderr)
        if process.returncode:
            raise RuntimeError(mode + ' failed; see ' + str(root / (mode + '.log')))
        result = json.loads(process.stdout)
        print(mode + ': ' + json.dumps(result), flush=True)
        return result

    def sql(statement):
        subprocess.run(['docker', 'exec', '-i', containers['mysql'], 'mysql', '-uroot'], input=statement.encode(),
                       check=True, capture_output=True, timeout=30)

    created = []
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
        deadline = time.monotonic() + 90
        while True:
            probe = subprocess.run(['docker', 'exec', containers['mysql'], 'mysql', '-uroot', '-Nse', 'SELECT VERSION()'], capture_output=True, text=True, timeout=5)
            redis = subprocess.run(['docker', 'exec', containers['redis'], 'redis-cli', '-s', '/offline-socket/redis.sock', 'ping'], capture_output=True, text=True, timeout=5)
            mysql_process = subprocess.run(['docker', 'exec', containers['mysql'], 'cat', '/proc/1/comm'], capture_output=True, text=True, timeout=5)
            if probe.returncode == 0 and mysql_process.stdout.strip() == 'mysqld' and redis.stdout.strip() == 'PONG':
                break
            if time.monotonic() >= deadline:
                raise RuntimeError('Private database did not become ready')
            time.sleep(0.5)
        for container in created:
            inspection = json.loads(subprocess.run(['docker', 'inspect', container], check=True, capture_output=True, text=True, timeout=10).stdout)[0]
            if inspection['HostConfig']['NetworkMode'] != 'none' or inspection['HostConfig']['PortBindings']:
                raise RuntimeError('Offline isolation failed')
        sql("CREATE DATABASE receivable_capacity_only; CREATE USER 'capacity_fixture'@'localhost' IDENTIFIED BY '" + password
            + "'; GRANT ALL ON receivable_capacity_only.* TO 'capacity_fixture'@'localhost';")
        seeded = run('seed')
        demand_counts = seeded['roots'] == 10000 and seeded['settlements'] + seeded['reversals'] == 100000
        if not demand_counts:
            raise RuntimeError('Fixture did not meet required capacity')
        pagination = run('pagination')
        complete = run('complete')
        prepared = run('race-prepare')
        reader = subprocess.Popen([php, '-d', 'memory_limit=512M', str(harness), 'race-reader'], cwd=repo, env=env,
                                  stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        try:
            deadline = time.monotonic() + 20
            while not (root / 'reader.ready').exists():
                if reader.poll() is not None or time.monotonic() >= deadline:
                    if reader.poll() is None:
                        reader.terminate()
                    stdout, stderr = reader.communicate(timeout=5)
                    (root / 'race-reader.log').write_text(stdout + stderr)
                    raise RuntimeError('Reader did not reach the actual owner lock; see ' + str(root / 'race-reader.log'))
                time.sleep(0.01)
            writer = run('race-writer')
            stdout, stderr = reader.communicate(timeout=120)
            (root / 'race-reader.log').write_text(stdout + stderr)
            if reader.returncode:
                raise RuntimeError('Reader race failed; see race-reader.log')
            race = json.loads(stdout)
        finally:
            if reader.poll() is None:
                reader.terminate()
                reader.communicate(timeout=5)
        recovered = run('race-recover', 300)
        result = {'status': 'PASS', 'mysql_version': probe.stdout.strip(), 'redis': '7-alpine', 'network': 'none',
                  'mysql_session_default': 'READ COMMITTED', 'snapshot': 'explicit REPEATABLE READ',
                  'hardware': {'logical_cpus': os.cpu_count(), 'meminfo': pathlib.Path('/proc/meminfo').read_text().splitlines()[:3]},
                  'capacity': seeded, 'pagination_data': pagination, 'real_money_action': complete, 'race_original_check': prepared,
                  'concurrent_real_writer': writer, 'stale_permit_reader': race, 'original_request_recovery': recovered,
                  'real_external_actions': 0}
        (root / 'result.json').write_text(json.dumps(result, indent=2))
        print('PASS: complete capacity verification, real money action, stale-permit rejection and original-request recovery.', flush=True)
    finally:
        cleanup = []
        for container in created:
            logs = subprocess.run(['docker', 'logs', container], capture_output=True, text=True, timeout=10)
            (root / (container + '.log')).write_text(logs.stdout + logs.stderr)
            removed = subprocess.run(['docker', 'rm', '--force', container], capture_output=True, timeout=20)
            cleanup.append({'container': container, 'removed': removed.returncode == 0})
        (root / 'cleanup.json').write_text(json.dumps(cleanup))
        for path in root.rglob('*'):
            if path.is_file():
                path.chmod(0o600)
        print('Disposable containers removed; private evidence retained: ' + str(root), flush=True)


if __name__ == '__main__':
    main()
