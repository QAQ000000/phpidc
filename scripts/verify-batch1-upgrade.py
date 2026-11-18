"""Rehearse batch 1 upgrades in a disposable offline MySQL and optional preview SQLite copy.

Run with PHP 8.4 and an already available mysql:8.0.35 Docker image. No database,
socket, password or external endpoint can be supplied. --preview-pid reads the
existing preview environment and backs up its SQLite database read-only; every
Artisan/action/request then targets private copies. Evidence stays outside Git.
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
import urllib.request
import uuid


def sha256(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--preview-pid', type=int, help='Read-only source preview PID; never migrated in place')
    args = parser.parse_args()
    repo = pathlib.Path(__file__).resolve().parents[1]
    php = '/www/server/php/84/bin/php'
    image = 'mysql:8.0.35'
    migrations = sorted((repo / 'database/migrations').glob('*.php'))
    legacy = [path for path in migrations if path.name < '2026_11_18_000004']
    upgrade = [path for path in migrations if path.name >= '2026_11_18_000004']
    expected = ['000004', '000005', '000006', '000007', '000008']
    if [path.name[11:17] for path in upgrade] != expected:
        raise RuntimeError('Expected only approved batch 1 migrations 000004–000008.')
    subprocess.run(['docker', 'image', 'inspect', image], check=True, capture_output=True, timeout=10)
    root = pathlib.Path(tempfile.mkdtemp(prefix='laravel13idc-batch1-upgrade-'))
    root.chmod(0o700)
    print('Private evidence: ' + str(root), flush=True)
    socket_dir = root / 'socket'
    socket_dir.mkdir(mode=0o777)
    socket_dir.chmod(0o777)
    for folder, files in [('legacy-migrations', legacy), ('upgrade-migrations', upgrade)]:
        (root / folder).mkdir()
        for path in files:
            shutil.copyfile(path, root / folder / path.name)
    container = 'laravel13idc-batch1-' + uuid.uuid4().hex[:12]
    clean_env = {key: value for key, value in os.environ.items()
                 if not key.startswith(('APP_', 'DB_', 'REDIS_', 'LARAVEL_', 'VIEW_', 'QUEUE_',
                                        'CACHE_', 'SESSION_', 'MAIL_', 'LOG_', 'BROADCAST_'))}
    base_env = dict(clean_env, APP_ENV='testing', APP_DEBUG='false', APP_URL='https://offline.example.test',
                    BCRYPT_ROUNDS='4', CACHE_STORE='array', SESSION_DRIVER='array',
                    QUEUE_CONNECTION='database', MAIL_MAILER='array', LOG_CHANNEL='single',
                    DB_URL='', DB_CONNECTION='mysql', DB_HOST='localhost', DB_PORT='3306',
                    DB_SOCKET=str(socket_dir / 'mysqld.sock'), DB_USERNAME='root', DB_PASSWORD='',
                    UPGRADE_REPO=str(repo), UPGRADE_ROOT=str(root),
                    UPGRADE_PASSWORD='Offline-' + os.urandom(16).hex() + '!')

    def environment(name, **extra):
        location = root / name
        for folder in ['framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private', 'public']:
            (location / folder).mkdir(parents=True, exist_ok=True)
        for asset in ['js/filament/filament/app.js', 'css/filament/filament/app.css', 'js/classic-config.js']:
            target = location / 'public' / asset
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(repo / 'public' / asset, target)
        result = dict(base_env, APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(),
                    DB_DATABASE='batch1_upgrade_only', LARAVEL_STORAGE_PATH=str(location),
                    VIEW_COMPILED_PATH=str(location / 'framework/views'), UPGRADE_CASE=name,
                    APP_CONFIG_CACHE=str(location / 'config.php'), APP_ROUTES_CACHE=str(location / 'routes.php'),
                    APP_EVENTS_CACHE=str(location / 'events.php'), APP_SERVICES_CACHE=str(location / 'services.php'),
                    APP_PACKAGES_CACHE=str(location / 'packages.php'))
        result.update(extra)
        return result

    harness = root / 'acceptance.php'
    harness.write_text(r'''<?php
declare(strict_types=1);
require getenv('UPGRADE_REPO').'/vendor/autoload.php';
$app = require getenv('UPGRADE_REPO').'/bootstrap/app.php';
$app->usePublicPath(storage_path('public'));
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function(Throwable $error):void { fwrite(STDERR,(string)$error.PHP_EOL); exit(20); });
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Database\Migrations\Migrator;
function demand(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function currentTables():array { return Schema::getTableListing(DB::getDriverName()==='mysql'?DB::getDatabaseName():'main',false); }
$connection=DB::connection();
if ($connection->getDriverName()==='mysql') {
    demand(in_array($connection->getDatabaseName(), ['batch1_upgrade_only','batch1_fresh_only'],true)
        && $connection->getConfig('unix_socket')===getenv('UPGRADE_ROOT').'/socket/mysqld.sock', 'Private MySQL only');
} else {
    demand($connection->getDriverName()==='sqlite'
        && $connection->getDatabaseName()===getenv('UPGRADE_ROOT').'/preview-copy/database.sqlite', 'Private SQLite copy only');
}
Http::preventStrayRequests();
Http::fake(fn () => throw new RuntimeException('External HTTP is forbidden in upgrade acceptance'));
Illuminate\Support\Facades\Mail::fake();
$mode=$argv[1];
$case=storage_path();
function manifest(?array $old=null): array {
    $result=[];
    $tables=$old===null ? currentTables() : array_keys($old);
    sort($tables);
    foreach($tables as $table) {
        if ($table==='migrations') continue;
        $columns=$old[$table]['columns']??Schema::getColumnListing($table);
        $rows=[];
        foreach(DB::table($table)->get($columns) as $row) $rows[]=json_encode((array)$row,JSON_THROW_ON_ERROR);
        sort($rows,SORT_STRING);
        $result[$table]=['columns'=>$columns,'count'=>count($rows),'sha256'=>hash('sha256',implode("\n",$rows))];
    }
    return $result;
}
function migratePaths(array $paths): void {
    $m=app(Migrator::class);
    if (!$m->repositoryExists()) $m->getRepository()->createRepository();
    $m->run($paths,['force'=>true]);
}
function signedCallback(App\Models\Invoice $invoice,string $trade,string $amount):string {
    $key=hash('sha256',getenv('UPGRADE_PASSWORD').':offline-epay');
    $payload=['pid'=>'offline-merchant','type'=>'wxpay','out_trade_no'=>$invoice->invoice_no,
        'trade_no'=>$trade,'money'=>$amount,'trade_status'=>'TRADE_SUCCESS'];
    ksort($payload,SORT_STRING);$pairs=[];
    foreach($payload as $name=>$value) $pairs[]=$name.'='.$value;
    $payload['sign']=md5(implode('&',$pairs).$key);
    $adapter=new App\Drivers\Payment\EpayGateway('epay_wechat','wxpay','https://offline.example.test','offline-merchant',$key);
    return app(App\Actions\HandlePaymentCallbackAction::class)->execute(
        $adapter->verifyCallback(Illuminate\Http\Request::create('/api/payments/epay_wechat/notify','POST',$payload)));
}
if($mode==='legacy') {
    demand(currentTables()===[], 'Legacy fixture requires its new empty database');
    migratePaths([getenv('UPGRADE_ROOT').'/legacy-migrations']);
    $user=User::factory()->create(['email'=>'upgrade-owner@example.test','password'=>getenv('UPGRADE_PASSWORD'),
        'credit'=>'30.00','frozen_credit'=>'2.00','affiliate_balance'=>'7.50']);
    $admin=User::factory()->create(['email'=>'upgrade-admin@example.test']);
    app(App\Actions\AssignAdminRoleAction::class)->execute($admin,'access-manager','Synthetic upgrade baseline');
    $product=App\Models\Product::factory()->create(['slug'=>'batch1-upgrade-local','local_prices'=>['onetime'=>['price'=>'10.00','enabled'=>true]]]);
    $invoice=App\Models\Invoice::factory()->paid()->create(['user_id'=>$user->id,'product_id'=>$product->id,
        'total_amount'=>'2.00','gateway_amount'=>'2.00']);
    App\Models\FinancialTransaction::create(['user_id'=>$user->id,'invoice_id'=>$invoice->id,'type'=>App\Enums\TransactionType::PAYMENT,
        'amount'=>'2.00','balance_after'=>'30.00','frozen_after'=>'2.00','reference_key'=>'upgrade:historical:payment','meta'=>['currency'=>'CNY']]);
    App\Models\RedemptionCode::factory()->create(['product_id'=>$product->id,'code'=>'SYNTHETIC-UPGRADE-CARD']);
    $state=app(App\Support\InstallationState::class);
    $state->write('installed.lock',$state->seal(['format'=>1,'admin_id'=>$admin->id],config('app.key')));
    demand(Illuminate\Support\Facades\Hash::check(getenv('UPGRADE_PASSWORD'),$user->password),'Legacy password verification failed');
    echo json_encode(['legacy_migrations'=>DB::table('migrations')->count(),'tables'=>count(currentTables()),'historical_password_verified'=>true]);
} elseif($mode==='checkpoint') {
    demand(!Schema::hasColumn('users','affiliate_frozen_balance')&&!Schema::hasTable('notification_events'),'Source is not the required old schema');
    $before=manifest();
    file_put_contents($case.'/before.json',json_encode($before,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    file_put_contents($case.'/migration-before.json',json_encode(DB::table('migrations')->orderBy('id')->get(),JSON_THROW_ON_ERROR));
    $state=app(App\Support\InstallationState::class); $state->assertKey(requireSeal:true);
    $code=DB::table('redemption_codes')->whereNotNull('code')->first();
    if($code!==null) demand(Crypt::decryptString($code->code)!=='','Historical card cannot decrypt');
    echo json_encode(['tables'=>count($before),'rows'=>array_sum(array_column($before,'count')),'sealed_key_valid'=>true,'historical_card_decrypted'=>$code!==null]);
} elseif($mode==='upgrade') {
    migratePaths([getenv('UPGRADE_ROOT').'/upgrade-migrations']);
    echo json_encode(['migrations'=>DB::table('migrations')->count()]);
} elseif($mode==='first-migration') {
    migratePaths([glob(getenv('UPGRADE_ROOT').'/upgrade-migrations/*000004*')[0]]);
    echo json_encode(['topup_request_id_added'=>Schema::hasColumn('invoices','topup_request_id')]);
} elseif($mode==='fault') {
    $migration=glob(getenv('UPGRADE_ROOT').'/upgrade-migrations/*000005*')[0];
    DB::listen(function(Illuminate\Database\Events\QueryExecuted $query):void {
        if(str_contains(strtolower($query->sql),'alter table `users`')&&str_contains($query->sql,'affiliate_frozen_balance'))
            throw new RuntimeException('INJECTED_AFTER_FIRST_COMMITTED_DDL');
    });
    migratePaths([$migration]);
    throw new RuntimeException('Fault injection did not run');
} elseif($mode==='fault-state') {
    demand(Schema::hasColumn('users','affiliate_frozen_balance'),'First DDL did not persist');
    demand(!Schema::hasColumn('affiliate_withdrawals','payout_method'),'Second DDL unexpectedly ran');
    demand(!DB::table('migrations')->where('migration','like','%000005%')->exists(),'Incomplete migration was recorded');
    echo json_encode(['first_ddl_persisted'=>true,'second_ddl_absent'=>true,'migration_unrecorded'=>true]);
} elseif($mode==='verify') {
    $before=json_decode(file_get_contents($case.'/before.json'),true,flags:JSON_THROW_ON_ERROR);
    demand(manifest($before)===$before,'Historical columns or row contents changed during migration');
    $new=['affiliate_receivable_settlements','affiliate_receivable_reversals','notification_events','notification_deliveries'];
    $expected=array_merge(array_keys($before),['migrations'],$new);sort($expected);
    $actual=currentTables();sort($actual);
    demand($actual===$expected,'Unexpected table addition/deletion');
    demand(Schema::hasColumn('invoices','topup_request_id')&&Schema::hasColumn('users','affiliate_frozen_balance')
        &&Schema::hasColumn('users','wallet_clearance_frozen')&&Schema::hasColumn('notification_logs','notification_delivery_id'),'Missing approved columns');
    demand(!DB::table('users')->where('affiliate_frozen_balance','!=','0.00')->exists()
        &&!DB::table('users')->where('wallet_clearance_frozen','!=','0.00')->exists(),'New frozen defaults changed old balances');
    demand(DB::table('migrations')->count()===14,'Expected all fourteen migrations');
    foreach(['invoices'=>['invoices_user_id_topup_request_id_unique'],'notification_deliveries'=>['notification_delivery_recipient_unique'],
        'affiliate_receivable_settlements'=>['receivable_active_receipt_unique']] as $table=>$names) {
        foreach($names as $name) demand(Schema::hasIndex($table,$name),'Missing index '.$name);
    }
    if(DB::getDriverName()==='sqlite') {
        demand(DB::select('PRAGMA foreign_key_check')===[],'SQLite foreign key check failed');
        demand(DB::select('PRAGMA quick_check')[0]->quick_check==='ok','SQLite quick check failed');
    }
    else foreach($actual as $table) demand(DB::select('CHECK TABLE `'.$table.'`')[0]->Msg_text==='OK','MySQL check failed: '.$table);
    if(DB::getDriverName()==='mysql') {
        $columns=DB::select("SELECT COLUMN_NAME,COLUMN_TYPE,COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME IN ('credit','frozen_credit','affiliate_frozen_balance','wallet_clearance_frozen') ORDER BY COLUMN_NAME");
        demand(count($columns)===4,'Missing MySQL wallet metadata');
        foreach($columns as $column) demand($column->COLUMN_TYPE==='decimal(12,2) unsigned','Unexpected wallet DECIMAL type');
        file_put_contents($case.'/mysql-wallet-columns.json',json_encode($columns,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    }
    app(App\Support\InstallationState::class)->assertKey(requireSeal:true);
    $code=DB::table('redemption_codes')->whereNotNull('code')->first();
    if($code!==null) demand(Crypt::decryptString($code->code)!=='','Historical card cannot decrypt after upgrade');
    $credentials=DB::table('instances')->whereNotNull('encrypted_credentials')->first();
    if($credentials!==null) demand(Crypt::decryptString($credentials->encrypted_credentials)!=='','Historical instance credentials cannot decrypt');
    $exit=Artisan::call('app:deployment-check');
    $deployment=trim(Artisan::output());
    $previewBlock=$exit!==0&&getenv('UPGRADE_CASE')==='preview-copy'
        &&$deployment==='初始权限管理员不可用，请从可信 CLI 核查。';
    demand($exit===0||$previewBlock,'Deployment check failed: '.$deployment);
    file_put_contents($case.'/original-seal-deployment-check.json',json_encode(['exit'=>$exit,'output'=>$deployment],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    file_put_contents($case.'/after.json',json_encode(manifest(),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo json_encode(['old_tables_preserved'=>count($before),'old_rows_preserved'=>array_sum(array_column($before,'count')),
        'new_tables'=>$new,'all_migrations'=>14,'new_frozen_defaults_zero'=>true,
        'historical_card_decrypted'=>$code!==null,'historical_instance_credentials_decrypted'=>$credentials!==null,
        'integrity'=>'PASS','original_seal_deployment_check'=>$previewBlock?'BLOCKED_PREEXISTING_ADMIN_CONFIGURATION':'PASS']);
} elseif($mode==='preview-bootstrap') {
    demand(getenv('UPGRADE_CASE')==='preview-copy'&&DB::getDriverName()==='sqlite','Only the private preview copy may receive a test administrator');
    demand(is_file($case.'/after.json'),'Historical preservation must pass before adding a test administrator');
    $state=app(App\Support\InstallationState::class);$metadata=$state->read('installed.lock');
    demand(!isset($metadata['admin_id']),'Expected legacy preview seal without an initial admin reference');
    $actor=User::factory()->create(['email'=>'copied-upgrade-admin@example.test','password'=>getenv('UPGRADE_PASSWORD')]);
    app(App\Actions\AssignAdminRoleAction::class)->execute($actor,'access-manager','Private copy only: deployment readiness verification');
    $state->write('installed.lock',$state->seal(array_replace($metadata,['admin_id'=>$actor->id]),config('app.key')));
    demand(Artisan::call('app:deployment-check')===0,'Private-copy deployment check failed: '.Artisan::output());
    User::factory()->create(['email'=>'upgrade-owner@example.test','password'=>getenv('UPGRADE_PASSWORD'),
        'credit'=>'30.00','frozen_credit'=>'2.00']);
    $product=App\Models\Product::factory()->create(['slug'=>'batch1-upgrade-local','local_prices'=>['onetime'=>['price'=>'10.00','enabled'=>true]]]);
    App\Models\RedemptionCode::factory()->create(['product_id'=>$product->id,'code'=>'SYNTHETIC-COPIED-UPGRADE-CARD']);
    echo json_encode(['scope'=>'private SQLite copy only','independent_access_manager_created'=>true,
        'same_app_key'=>true,'private_seal_updated'=>true,'copy_deployment_check'=>'PASS','original_seal_unchanged'=>true]);
} elseif($mode==='page') {
    $user=User::query()->whereNull('anonymized_at')->firstOrFail();
    Illuminate\Support\Facades\Auth::guard('web')->setUser($user);
    $kernel=app(Illuminate\Contracts\Http\Kernel::class);
    $request=Illuminate\Http\Request::create('/console/affiliate','GET');
    $response=$kernel->handle($request);
    $kernel->terminate($request,$response);
    $expected=(int)$argv[2];
    demand($response->getStatusCode()===$expected,'Affiliate page unexpected status '.$response->getStatusCode());
    if($expected===500) {
        $error=file_get_contents(storage_path('logs/laravel.log'));
        demand(str_contains($error,'affiliate_receivable_settlements')
            &&(str_contains($error,'no such table')||str_contains($error,"doesn't exist")),'Pre-upgrade 500 did not prove the missing receivable table');
    }
    echo json_encode(['path'=>'/console/affiliate','status'=>$response->getStatusCode(),'setup'=>'authenticated in-process kernel; no password or network used']);
} elseif($mode==='business') {
    $owner=User::where('email','upgrade-owner@example.test')->sole();
    $product=App\Models\Product::where('slug','batch1-upgrade-local')->sole();
    $request=['request_id'=>(string)Illuminate\Support\Str::ulid()];
    $action=app(App\Actions\CreateComboInvoiceAction::class);
    $invoice=$action->execute($owner,$product,App\Enums\BillingCycle::ONETIME,[],true,input:$request);
    demand($invoice->status===App\Enums\InvoiceStatus::PAID,'Wallet purchase did not settle');
    demand($action->execute($owner,$product,App\Enums\BillingCycle::ONETIME,[],true,input:$request)->id===$invoice->id,'Purchase replay duplicated invoice');
    $task=App\Models\FulfillmentTask::where('invoice_id',$invoice->id)->sole();
    demand(App\Models\NotificationEvent::where('event_key','invoice:'.$invoice->id.':paid')->count()===1,'Paid event is not unique');
    app(App\Actions\ExecuteFulfillmentTaskAction::class)->execute($task->id);
    demand($task->fresh()->status===App\Enums\FulfillmentTaskStatus::SUCCESS,'Local delivery did not finish');
    demand($owner->fresh()->credit==='20.00'&&$owner->fresh()->frozen_credit==='2.00','Wallet settlement changed historical frozen funds');
    demand(App\Models\RedemptionCode::where('product_id',$product->id)->firstOrFail()->status==='sold','Delivered card was not sold');
    $free=App\Models\Product::factory()->create(['local_prices'=>['onetime'=>['price'=>'0.00','enabled'=>true]]]);
    $zero=$action->execute($owner,$free,App\Enums\BillingCycle::ONETIME,[],false,input:['request_id'=>(string)Illuminate\Support\Str::ulid()]);
    demand($zero->status===App\Enums\InvoiceStatus::PAID&&$zero->gateway_amount==='0.00','Zero purchase failed');
    demand(App\Models\FulfillmentTask::where('invoice_id',$zero->id)->count()===1
        &&App\Models\NotificationEvent::where('event_key','invoice:'.$zero->id.':paid')->count()===1,'Zero Paid/task/event chain incomplete');
    Http::assertNothingSent();
    echo json_encode(['wallet_purchase'=>'Paid','idempotent_replay'=>true,'local_delivery'=>'success',
        'old_frozen_preserved'=>'2.00','zero_purchase'=>'Paid','paid_task_event_chain'=>'PASS','outgoing_http'=>0,'notification_worker_started'=>false]);
} elseif($mode==='fresh') {
    demand(currentTables()===[],'Fresh installation requires its new empty database');
    $state=app(App\Support\InstallationState::class);$token=bin2hex(random_bytes(24));
    $state->write('installation.token',['hash'=>hash('sha256',$token),'expires_at'=>now()->addHour()->timestamp]);
    $id=app(App\Actions\InstallApplicationAction::class)->execute(['install_token'=>$token,'admin_name'=>'Offline upgrade installer',
        'admin_email'=>'fresh-upgrade@example.test','admin_password'=>getenv('UPGRADE_PASSWORD'),
        'admin_password_confirmation'=>getenv('UPGRADE_PASSWORD'),'reason'=>'Synthetic empty MySQL install']);
    demand($id>0&&DB::table('migrations')->count()===14,'Fresh installer did not apply all approved migrations');
    demand(Artisan::call('app:deployment-check')===0,'Fresh deployment check failed: '.Artisan::output());
    echo json_encode(['fresh_installation'=>'PASS','all_migrations'=>14,'sealed_key_valid'=>true]);
} elseif($mode==='capacity') {
    demand(DB::getDriverName()==='mysql','DECIMAL acceptance requires MySQL');
    $owner=User::factory()->create(['credit'=>'9999999999.99']);
    $invoice=App\Models\Invoice::factory()->create(['user_id'=>$owner->id,'gateway'=>'epay_wechat']);
    foreach([1,2] as $attempt) demand(signedCallback($invoice,'CAPACITY-OVERPAY','10.01')==='success','Overpayment callback not ACKed');
    $callback=App\Models\PaymentCallback::where('invoice_id',$invoice->id)->sole();
    demand($callback->outcome===App\Enums\PaymentCallbackOutcome::REJECTED_INVALID_STATE,'Overpayment not rejected');
    demand(DB::table('audit_logs')->where('action','payment.callback.attention')->where('subject_id',$callback->id)->where('reason','wallet_capacity')->count()===1,'Capacity attention duplicated/missing');
    demand(App\Models\NotificationEvent::where('event_key','callback:'.$callback->id.':attention')->count()===1,'Durable attention duplicated/missing');
    demand($invoice->fresh()->status===App\Enums\InvoiceStatus::UNPAID&&$owner->fresh()->credit==='9999999999.99'
        &&App\Models\FinancialTransaction::where('invoice_id',$invoice->id)->count()===0
        &&App\Models\FulfillmentTask::where('invoice_id',$invoice->id)->count()===0,'Rejected receipt partially settled');
    $netOwner=User::factory()->create(['credit'=>'9999999998.99','frozen_credit'=>'1.00']);
    $net=App\Models\Invoice::factory()->create(['user_id'=>$netOwner->id,'gateway'=>'epay_wechat',
        'total_amount'=>'11.00','credit_applied'=>'1.00','gateway_amount'=>'10.00']);
    App\Models\FinancialTransaction::create(['user_id'=>$netOwner->id,'invoice_id'=>$net->id,'type'=>App\Enums\TransactionType::CREDIT_FREEZE,
        'amount'=>'1.00','balance_after'=>$netOwner->credit,'frozen_after'=>'1.00','reference_key'=>'freeze:'.$net->id]);
    demand(signedCallback($net,'CAPACITY-NET','10.50')==='success','Net callback failed');
    demand($net->fresh()->status===App\Enums\InvoiceStatus::PAID&&$netOwner->fresh()->credit==='9999999999.49'
        &&$netOwner->fresh()->frozen_credit==='0.00','Full net-state calculation failed');
    demand(App\Models\FulfillmentTask::where('invoice_id',$net->id)->count()===1
        &&App\Models\NotificationEvent::where('event_key','invoice:'.$net->id.':paid')->count()===1,'Net Paid chain missing');
    $blockedOwner=User::factory()->create(['credit'=>'9999999999.99','frozen_credit'=>'1.00']);
    $normalOwner=User::factory()->create(['credit'=>'0.00','frozen_credit'=>'1.00']);
    $due=[];
    foreach([$blockedOwner,$normalOwner] as $user) {
        $bill=App\Models\Invoice::factory()->create(['user_id'=>$user->id,'total_amount'=>'1.00','credit_applied'=>'1.00',
            'gateway_amount'=>'0.00','expires_at'=>now()->subMinute()]);
        App\Models\FinancialTransaction::create(['user_id'=>$user->id,'invoice_id'=>$bill->id,'type'=>App\Enums\TransactionType::CREDIT_FREEZE,
            'amount'=>'1.00','balance_after'=>$user->credit,'frozen_after'=>'1.00','reference_key'=>'freeze:'.$bill->id]);
        $due[]=$bill;
    }
    foreach([1,2] as $attempt) app(App\Actions\ExpireUnpaidInvoicesAction::class)->execute();
    demand($due[0]->fresh()->status===App\Enums\InvoiceStatus::UNPAID&&$blockedOwner->fresh()->credit==='9999999999.99'
        &&$blockedOwner->fresh()->frozen_credit==='1.00','Overflow release changed blocked bill/funds');
    demand(!App\Models\FinancialTransaction::where('reference_key','unfreeze:'.$due[0]->id)->exists()
        &&DB::table('audit_logs')->where('action','invoice.expiry.wallet_capacity')->where('subject_id',$due[0]->id)->count()===1,'Overflow expiry evidence incorrect');
    demand($due[1]->fresh()->status===App\Enums\InvoiceStatus::EXPIRED&&$normalOwner->fresh()->credit==='1.00'
        &&$normalOwner->fresh()->frozen_credit==='0.00','Overflow bill starved later normal expiry');
    $raceOwner=User::factory()->create(['credit'=>'9999999999.98']);$race=[];
    foreach([0,1] as $slot) $race[]=App\Models\Invoice::factory()->expired()->create(['user_id'=>$raceOwner->id,'gateway'=>'epay_wechat'])->id;
    file_put_contents($case.'/capacity-race.json',json_encode(['user'=>$raceOwner->id,'invoices'=>$race],JSON_THROW_ON_ERROR));
    Http::assertNothingSent();
    echo json_encode(['mysql_decimal_overflow_guard'=>'PASS','duplicate_callbacks'=>'2 ACK / 1 callback / 1 attention',
        'net_credit'=>'9999999999.49','net_frozen'=>'0.00','overflow_expiry'=>'Unpaid; balances unchanged; one audit',
        'later_expiry'=>'Expired; credit 1.00','race_fixture_ready'=>true]);
} elseif($mode==='capacity-race') {
    $slot=(int)$argv[2];$data=json_decode(file_get_contents($case.'/capacity-race.json'),true,flags:JSON_THROW_ON_ERROR);
    file_put_contents($case.'/race-'.$slot.'.ready','ready');$deadline=microtime(true)+15;
    while(!is_file($case.'/race.go')) { demand(microtime(true)<$deadline,'Race barrier timed out');usleep(10000); }
    $lockWait=0.0;$held=false;
    DB::listen(function(Illuminate\Database\Events\QueryExecuted $query)use(&$lockWait,&$held):void {
        if(str_contains($query->sql,'`users`')&&str_contains(strtolower($query->sql),'for update')) {
            $lockWait=max($lockWait,$query->time);
            if(!$held) {$held=true;usleep(250000);}
        }
    });
    $invoice=App\Models\Invoice::findOrFail($data['invoices'][$slot]);
    demand(signedCallback($invoice,'CAPACITY-RACE-'.$slot,'0.01')==='success','Concurrent receipt not ACKed');
    echo json_encode(['slot'=>$slot,'pid'=>getmypid(),'lock_wait_ms'=>$lockWait,'outcome'=>App\Models\PaymentCallback::where('invoice_id',$invoice->id)->sole()->outcome->value]);
} elseif($mode==='capacity-race-verify') {
    $data=json_decode(file_get_contents($case.'/capacity-race.json'),true,flags:JSON_THROW_ON_ERROR);
    $callbacks=App\Models\PaymentCallback::whereIn('invoice_id',$data['invoices'])->get();
    demand($callbacks->count()===2&&$callbacks->where('outcome',App\Enums\PaymentCallbackOutcome::PROCESSED_LATE_CREDIT)->count()===1
        &&$callbacks->where('outcome',App\Enums\PaymentCallbackOutcome::REJECTED_INVALID_STATE)->count()===1,'Concurrent capacity not serialized');
    demand(User::findOrFail($data['user'])->credit==='9999999999.99','Concurrent final credit incorrect');
    demand(App\Models\FinancialTransaction::whereIn('invoice_id',$data['invoices'])->count()===1,'Concurrent credit ledger duplicated');
    $rejected=$callbacks->firstWhere('outcome',App\Enums\PaymentCallbackOutcome::REJECTED_INVALID_STATE);
    demand(DB::table('audit_logs')->where('action','payment.callback.attention')->where('subject_id',$rejected->id)->where('reason','wallet_capacity')->count()===1,'Concurrent rejected receipt lost attention');
    echo json_encode(['independent_processes'=>2,'credited'=>1,'capacity_attention'=>1,'final_credit'=>'9999999999.99','SQL_1264'=>false]);
} else throw new RuntimeException('Unsupported acceptance mode');
echo PHP_EOL;
''')

    def run(env, mode, name, *arguments, expect=0):
        command = [php, '-d', 'memory_limit=512M', str(harness), mode, *arguments]
        result = subprocess.run(command, cwd=repo, env=env, capture_output=True, text=True, timeout=120)
        output = result.stdout + result.stderr
        (root / name).write_text(output)
        if result.returncode != expect:
            raise RuntimeError(f'{mode}: expected exit {expect}, got {result.returncode}; see {root / name}')
        if expect == 0:
            print(mode + ': ' + result.stdout.strip(), flush=True)
        return output

    def sql(command):
        return subprocess.run(['docker', 'exec', '-i', container, 'mysql', '-uroot'],
                              input=command.encode(), capture_output=True, check=True, timeout=30)

    original = None
    try:
        subprocess.run(['docker', 'run', '--detach', '--pull=never', '--name', container,
                        '--label', 'laravel13idc.review=batch1-upgrade', '--network', 'none',
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
                raise RuntimeError('Disposable MySQL did not become ready')
            time.sleep(0.5)
        (root / 'mysql-version.txt').write_text(probe.stdout)
        inspection = json.loads(subprocess.run(['docker', 'inspect', container], check=True, capture_output=True,
                                               text=True, timeout=10).stdout)[0]
        demand_network = inspection['HostConfig']['NetworkMode'] == 'none' and not inspection['HostConfig']['PortBindings']
        if not demand_network:
            raise RuntimeError('MySQL isolation was not effective')
        fixture_password = base_env['UPGRADE_PASSWORD']
        sql('CREATE DATABASE batch1_upgrade_only; CREATE DATABASE batch1_fresh_only;'
            "CREATE USER 'batch1_upgrade_fixture'@'localhost' IDENTIFIED BY '" + fixture_password + "';"
            "GRANT ALL ON batch1_upgrade_only.* TO 'batch1_upgrade_fixture'@'localhost';"
            "CREATE USER 'batch1_fresh_fixture'@'localhost' IDENTIFIED BY '" + fixture_password + "';"
            "GRANT ALL ON batch1_fresh_only.* TO 'batch1_fresh_fixture'@'localhost';")
        mysql_env = environment('mysql-upgrade', DB_USERNAME='batch1_upgrade_fixture', DB_PASSWORD=fixture_password)
        run(mysql_env, 'legacy', 'mysql-legacy.log')
        run(mysql_env, 'checkpoint', 'mysql-checkpoint.log')
        run(mysql_env, 'page', 'mysql-page-before.log', '500')
        checkpoint = subprocess.run(['docker', 'exec', container, 'mysqldump', '-uroot', '--single-transaction',
                                     '--routines', '--triggers', '--events', '--hex-blob', '--no-tablespaces',
                                     'batch1_upgrade_only'], capture_output=True, check=True, timeout=30).stdout
        (root / 'mysql-old-checkpoint.sql').write_bytes(checkpoint)
        run(mysql_env, 'first-migration', 'mysql-first-migration.log')
        fault_output = run(mysql_env, 'fault', 'mysql-injected-ddl-failure.log', expect=20)
        if 'INJECTED_AFTER_FIRST_COMMITTED_DDL' not in fault_output:
            raise RuntimeError('Expected DDL injection marker absent')
        run(mysql_env, 'fault-state', 'mysql-partial-ddl-state.log')
        retry_output = run(mysql_env, 'upgrade', 'mysql-blind-retry-rejected.log', expect=20)
        if 'Duplicate column' not in retry_output:
            raise RuntimeError('Blind retry did not demonstrate the expected partial-DDL conflict')
        sql('DROP DATABASE batch1_upgrade_only; CREATE DATABASE batch1_upgrade_only;')
        subprocess.run(['docker', 'exec', '-i', container, 'mysql', '-uroot', 'batch1_upgrade_only'],
                       input=checkpoint, check=True, capture_output=True, timeout=30)
        run(mysql_env, 'upgrade', 'mysql-forward-replay.log')
        run(mysql_env, 'verify', 'mysql-preservation.log')
        run(mysql_env, 'page', 'mysql-page-after.log', '200')
        run(mysql_env, 'business', 'mysql-business-chain.log')
        run(mysql_env, 'capacity', 'mysql-capacity.log')
        workers = [subprocess.Popen([php, '-d', 'memory_limit=512M', str(harness), 'capacity-race', str(slot)],
                                    cwd=repo, env=mysql_env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
                   for slot in range(2)]
        try:
            deadline = time.monotonic() + 15
            while not all((root / 'mysql-upgrade' / f'race-{slot}.ready').exists() for slot in range(2)):
                if time.monotonic() >= deadline or any(worker.poll() is not None for worker in workers):
                    raise RuntimeError('Concurrent capacity workers failed before barrier')
                time.sleep(0.01)
            (root / 'mysql-upgrade/race.go').touch()
            for slot, worker in enumerate(workers):
                output, error = worker.communicate(timeout=30)
                (root / f'mysql-capacity-race-{slot}.log').write_text(output + error)
                if worker.returncode:
                    raise RuntimeError('Concurrent capacity worker failed; see private race logs')
        finally:
            for worker in workers:
                if worker.poll() is None:
                    worker.terminate()
                    worker.communicate(timeout=5)
        run(mysql_env, 'capacity-race-verify', 'mysql-capacity-race-result.log')
        run(environment('mysql-fresh', DB_DATABASE='batch1_fresh_only', DB_USERNAME='batch1_fresh_fixture',
                        DB_PASSWORD=fixture_password), 'fresh', 'mysql-fresh-install.log')

        if args.preview_pid:
            process = pathlib.Path('/proc') / str(args.preview_pid)
            preview_env = dict(part.split('=', 1) for part in (process / 'environ').read_bytes().decode().split('\0') if '=' in part)
            if preview_env.get('DB_CONNECTION') != 'sqlite' or (process / 'cwd').resolve() != repo / 'public':
                raise RuntimeError('Source PID is not this repository SQLite preview')
            source = pathlib.Path(preview_env['DB_DATABASE']).resolve()
            source_lock = pathlib.Path(preview_env['LARAVEL_STORAGE_PATH']) / 'installed.lock'
            original = {'pid': args.preview_pid, 'database': str(source), 'database_sha256_before': sha256(source),
                        'installed_lock_sha256_before': sha256(source_lock), 'project_env_sha256_before': sha256(repo / '.env')}
            with sqlite3.connect(source.as_uri() + '?mode=ro', uri=True) as read_only:
                original['migrations_before'] = [row[0] for row in read_only.execute('SELECT migration FROM migrations ORDER BY id')]
            copy_env = environment('preview-copy', DB_CONNECTION='sqlite', DB_DATABASE=str(root / 'preview-copy/database.sqlite'),
                                   DB_SOCKET='', APP_KEY=preview_env['APP_KEY'])
            with sqlite3.connect(source.as_uri() + '?mode=ro', uri=True) as read_only:
                with sqlite3.connect(copy_env['DB_DATABASE']) as target:
                    read_only.backup(target)
            shutil.copyfile(source_lock, root / 'preview-copy/installed.lock')
            run(copy_env, 'checkpoint', 'preview-checkpoint.log')
            run(copy_env, 'page', 'preview-page-before.log', '500')
            run(copy_env, 'upgrade', 'preview-copy-upgrade.log')
            run(copy_env, 'verify', 'preview-copy-preservation.log')
            run(copy_env, 'preview-bootstrap', 'preview-copy-readiness-with-test-admin.log')
            run(copy_env, 'page', 'preview-page-after.log', '200')
            run(copy_env, 'business', 'preview-copy-business-chain.log')
            original.update(database_sha256_after=sha256(source), installed_lock_sha256_after=sha256(source_lock),
                            project_env_sha256_after=sha256(repo / '.env'), pid_alive=process.exists())
            with sqlite3.connect(source.as_uri() + '?mode=ro', uri=True) as read_only:
                original['migrations_after'] = [row[0] for row in read_only.execute('SELECT migration FROM migrations ORDER BY id')]
            original['unchanged'] = all(original[key + '_before'] == original[key + '_after']
                                        for key in ['database_sha256', 'installed_lock_sha256', 'project_env_sha256']) and original['migrations_before'] == original['migrations_after']
            command = (process / 'cmdline').read_bytes().decode().split('\0')
            listen = command[command.index('-S') + 1]
            if not listen.startswith('127.0.0.1:') or not listen.split(':')[1].isdigit():
                raise RuntimeError('Only the known loopback preview HTTP listener may be checked')
            opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
            with opener.open('http://' + listen + '/login', timeout=10) as response:
                original['login_http_status'] = response.status
            original['initial_admin_readiness'] = 'BLOCKED_PREEXISTING_SEAL_WITHOUT_ADMIN_ID'
            if not original['unchanged'] or not original['pid_alive']:
                raise RuntimeError('Original preview changed during rehearsal; inspect independently before claiming preservation')
        result = {'status': 'PASS', 'php': php, 'mysql_version': probe.stdout.strip(), 'mysql_network': 'none',
                  'migration_files': {path.name: sha256(path) for path in migrations},
                  'partial_ddl': 'observed committed DDL/unrecorded migration; blind retry rejected; restored old checkpoint and replayed',
                  'synthetic_mysql': True, 'actual_preview_copy': original, 'original_preview_migrated': False,
                  'real_payment_notification_supplier_actions': 0}
        (root / 'result.json').write_text(json.dumps(result, indent=2))
        print('PASS: isolated incremental upgrade, partial-DDL recovery, historical preservation and Paid/task/event chain.', flush=True)
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
