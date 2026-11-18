"""Verify FIX-006 machine recovery races in a disposable offline MySQL 8 database."""

import argparse
import base64
import json
import os
import pathlib
import subprocess
import tempfile
import time
import uuid


def run_machine_races(repo, root, env):
    (root / 'storage/installed.lock').write_text('isolated FIX-006 races')
    worker = root / 'machine-race.php'
    worker.write_text(r'''<?php
require getenv('MACHINE_TEST_REPO').'/vendor/autoload.php';
$app = require getenv('MACHINE_TEST_REPO').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Actions\AssignAdminRoleAction;
use App\Actions\ExecuteCustomerPowerCommandAction;
use App\Actions\HotSwapInstanceDriverAction;
use App\Actions\ReconcileMachineOperationAction;
use App\Enums\DriverType;
use App\Enums\InstanceStatus;
use App\Enums\ProvisionCommand;
use App\Models\Instance;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Support\MachineOperation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
$root=getenv('MACHINE_TEST_ROOT');
$data=json_decode($argv[2]??'{}',true,flags:JSON_THROW_ON_ERROR);
$op=$argv[1];
function awaitMachineFile(string $file): void {
    $deadline=microtime(true)+20;
    while(!is_file($file)) {
        if(microtime(true)>$deadline) { throw new RuntimeException('Machine process barrier timed out.'); }
        usleep(10000);
    }
}
Http::preventStrayRequests();
Http::fake(function($request)use($root,$op,$data){
    if(DB::transactionLevel()!==0){throw new LogicException('Machine network call inside transaction.');}
    $path=parse_url($request->url(),PHP_URL_PATH);
    if($path==='/host/header' && isset($data['wait']) && $op==='resolve') {
        file_put_contents($root.'/'.$data['wait'].'.ready.'.getmypid(),'ready');
        awaitMachineFile($root.'/'.$data['wait'].'.go');
    }
    if($path==='/provision/default' && ($request['func']??null)==='on' && isset($data['command_wait'])) {
        file_put_contents($root.'/'.$data['command_wait'].'.ready.'.getmypid(),'ready');
        awaitMachineFile($root.'/'.$data['command_wait'].'.go');
    }
    if(isset($data['instance'])) { file_put_contents($root.'/calls-'.$data['instance'],json_encode(['path'=>$path,'func'=>$request['func']??null])."\n",FILE_APPEND|LOCK_EX); }
    return Http::response(match($path){
        '/zjmf_api_login'=>['status'=>200,'jwt'=>'isolated-machine-jwt'],
        '/host/header'=>['status'=>200,'data'=>['host_data'=>['id'=>(int)$request['host_id'],'domainstatus'=>'Active','productid'=>17,'billingcycle'=>'monthly','currency_code'=>'CNY'],
            'module_power_status'=>true,'module_button'=>['control'=>[['type'=>'default','func'=>'on']]]]],
        '/provision/default'=>['status'=>200,'data'=>['status'=>'on']],
        '/host/setdownstream'=>['status'=>200],
        '/cart/get_product_config'=>['status'=>200,'data'=>['products'=>['pay_type'=>[]]]],
        default=>throw new RuntimeException('Unexpected machine race endpoint '.$path),
    });
});
if($op==='fixture') {
    $owner=User::factory()->create(['credit'=>'12.34']);
    $actor=User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($actor,'upstream-lifecycle','Isolated recovery authority');
    app(AssignAdminRoleAction::class)->execute($actor,'upstream-migrator','Isolated migration authority');
    $account=UpstreamApi::factory()->create();
    $target=UpstreamApi::factory()->create();
    $product=Product::factory()->create(['driver_type'=>DriverType::ZJMF_API,'upstream_api_id'=>$account->id]);
    $instance=Instance::factory()->create(['user_id'=>$owner->id,'product_id'=>$product->id,'driver_type'=>DriverType::ZJMF_API,'upstream_api_id'=>$account->id,
        'upstream_instance_id'=>'4253','status'=>InstanceStatus::ACTIVE,'active_config'=>[]]);
    $operation=['request_id'=>(string)Str::ulid(),'command'=>'on','status'=>'unknown','requested_at'=>now()->subMinutes(6)->toIso8601String(),
        'encrypted_binding'=>Crypt::encryptString(json_encode(app(MachineOperation::class)->binding($instance),JSON_THROW_ON_ERROR))];
    $instance->update(['active_config'=>['cloud_operation'=>$operation]]);
    DB::table('audit_logs')->insert(['actor_type'=>'user','actor_id'=>$owner->id,'subject_type'=>Instance::class,'subject_id'=>$instance->id,
        'action'=>'instance.machine.request.'.$operation['request_id'],'reason'=>'Original isolated command','created_at'=>now()->subMinutes(6),'updated_at'=>now()->subMinutes(6)]);
    $data=['instance'=>$instance->id,'actor'=>$actor->id,'owner'=>$owner->id,'target'=>$target->id];
} else { $instance=Instance::findOrFail($data['instance']); $actor=User::findOrFail($data['actor']); $owner=User::findOrFail($data['owner']); }
if(isset($data['future'])) { Carbon::setTestNow(now()->addMinutes(6)); }
try {
    if($op==='fixture'||$op==='proof') {
        $review=app(ReconcileMachineOperationAction::class)->inspect($instance,$actor,$instance->active_config['cloud_operation']['request_id'],'Inspect supplier original task');
        $input=['resolution_id'=>(string)Str::ulid(),'operation_request_id'=>$instance->active_config['cloud_operation']['request_id'],'proof'=>$review['proof'],
            'decision'=>'release','reason'=>'Independent supplier ticket task verification','confirm'=>1,'source'=>'supplier_ticket','conclusion'=>'completed',
            'evidence_ref'=>'TASK-4253','evidence'=>'Supplier confirmed the original task is terminal and no queued or running operations remain.',
            'verified_host_id'=>'4253','ended_at'=>now()->subSecond()->toDateTimeString(),'idle_confirm'=>1];
        echo json_encode(array_replace($data,['input'=>$input]),JSON_THROW_ON_ERROR);
    } elseif($op==='state') {
        echo json_encode(['operation'=>$instance->active_config['cloud_operation']??null,'host'=>$instance->upstream_instance_id,
            'blocked'=>app(MachineOperation::class)->blocked($instance),'wallet'=>$owner->credit,
            'audits'=>DB::table('audit_logs')->where('subject_type',Instance::class)->where('subject_id',$instance->id)->where('action','like','instance.machine.reconcile.%')->count(),
            'results'=>DB::table('audit_logs')->where('subject_type',Instance::class)->where('subject_id',$instance->id)->where('action','like','instance.machine.result.%')->get(['after'])->all(),
            'archived'=>DB::table('audit_logs')->where('subject_type',Instance::class)->where('subject_id',$instance->id)->where('action','instance.machine.archived')->count(),
            'invoices'=>DB::table('invoices')->where('user_id',$owner->id)->count(),'ledger'=>DB::table('financial_transactions')->where('user_id',$owner->id)->count()],JSON_THROW_ON_ERROR);
    } elseif($op==='resolve') {
        if(isset($data['prewait'])) { file_put_contents($root.'/'.$data['prewait'].'.ready.'.getmypid(),'ready'); awaitMachineFile($root.'/'.$data['prewait'].'.go'); }
        echo json_encode(app(ReconcileMachineOperationAction::class)->execute($instance,$actor,$data['input']),JSON_THROW_ON_ERROR);
    } elseif($op==='command') {
        if(isset($data['reset'])) { $instance->update(['active_config'=>[]]); }
        $result=app(ExecuteCustomerPowerCommandAction::class)->execute($owner,$instance,ProvisionCommand::POWER_ON,
            ['request_id'=>$data['command_id'],'reason'=>'Separate new customer command','confirm'=>1]);
        echo json_encode(['success'=>$result->success],JSON_THROW_ON_ERROR);
    } elseif($op==='hotswap') {
        app(HotSwapInstanceDriverAction::class)->execute($instance,$data['target'],'199','17','keep_orphan','Verified replacement binding',$actor,true);
        echo json_encode(['swapped'=>true],JSON_THROW_ON_ERROR);
    } else { throw new RuntimeException('Unknown machine race operation.'); }
} catch(ValidationException $exception) { echo json_encode(['validation'=>$exception->errors()],JSON_THROW_ON_ERROR); }
''')

    def call(op, data):
        result = subprocess.run(['php', str(worker), op, json.dumps(data)], cwd=repo, env=env,
                                capture_output=True, text=True, timeout=40)
        if result.returncode:
            raise RuntimeError(result.stderr)
        return json.loads(result.stdout)

    def start(op, data):
        return subprocess.Popen(['php', str(worker), op, json.dumps(data)], cwd=repo, env=env,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)

    def finish(process):
        out, err = process.communicate(timeout=40)
        if process.returncode:
            raise RuntimeError(err)
        return json.loads(out)

    def ready(barrier, count):
        deadline = time.monotonic() + 20
        while len(list(root.glob(barrier + '.ready.*'))) < count:
            if time.monotonic() > deadline:
                raise RuntimeError('Machine race barrier timed out.')
            time.sleep(0.02)

    def go(barrier):
        (root / (barrier + '.go')).touch()

    def state(data):
        result = call('state', data)
        assert result['wallet'] == '12.34' and result['invoices'] == 0 and result['ledger'] == 0, result
        return result

    for same_key in [True, False]:
        data = call('fixture', {})
        barrier = 'same-key' if same_key else 'different-key'
        second = json.loads(json.dumps(data))
        if not same_key:
            # Get another genuine ULID without changing the original proof/intent.
            second['input']['resolution_id'] = call('proof', data)['input']['resolution_id']
        first_process = start('resolve', dict(data, wait=barrier))
        second_process = start('resolve', dict(second, wait=barrier))
        ready(barrier, 2)
        go(barrier)
        results = [finish(first_process), finish(second_process)]
        current = state(data)
        assert current['audits'] == 1 and not current['blocked'] and current['operation']['status'] == 'unknown', current
        if same_key:
            assert results[0] == results[1] and results[0]['released'], results
            assert call('resolve', data) == results[0]
        else:
            assert sum('validation' in result for result in results) == 1, results
        print('PASS: two processes with ' + ('one resolution key replay one committed result.' if same_key else 'different resolution keys commit only one release.'), flush=True)

    data = call('fixture', {})
    blocked = call('command', dict(data, command_id=data['input']['operation_request_id']))
    assert 'validation' in blocked
    process = start('resolve', dict(data, wait='new-command'))
    ready('new-command', 1)
    # It is unsafe to admit new commands while the operator is still reading.
    command_id = call('proof', data)['input']['resolution_id']
    assert 'validation' in call('command', dict(data, command_id=command_id))
    go('new-command')
    assert finish(process)['released']
    assert 'validation' in call('command', dict(data, command_id=data['input']['operation_request_id']))
    assert call('command', dict(data, command_id=command_id)) == {'success': True}
    current = state(data)
    assert current['operation']['status'] == 'accepted' and current['audits'] == 1
    print('PASS: unresolved intent blocks competing commands; release allows only a new request, never original replay.', flush=True)

    for winner in ['hotswap', 'resolve']:
        data = call('fixture', {})
        barrier = 'swap-' + winner
        process = start('resolve', dict(data, wait=barrier))
        ready(barrier, 1)
        if winner == 'hotswap':
            assert call('hotswap', data) == {'swapped': True}
            go(barrier)
            assert 'validation' in finish(process)
            current = state(data)
            assert current['host'] == '199' and current['operation'] is None and current['archived'] == 1 and current['audits'] == 0
        else:
            go(barrier)
            assert finish(process)['released']
            assert call('hotswap', data) == {'swapped': True}
            current = state(data)
            assert current['host'] == '199' and current['operation'] is None and current['archived'] == 1 and current['audits'] == 1
        print('PASS: ' + winner + ' commits first without applying old reconciliation to the replacement host.', flush=True)

    data = call('fixture', {})
    command_id = call('proof', data)['input']['resolution_id']
    process = start('command', dict(data, reset=True, command_id=command_id, command_wait='late-result'))
    ready('late-result', 1)
    current = state(data)
    assert current['operation']['status'] == 'requested'
    proof = call('proof', dict(data, future=True))
    proof['input']['conclusion'] = 'not_executed'
    # Simulated timeout exceeds the protection period; all HTTP remains fake and offline.
    assert call('resolve', dict(data, input=proof['input'], future=True))['released']
    go('late-result')
    assert 'validation' in finish(process)
    current = state(data)
    assert current['operation']['status'] == 'requested' and not current['blocked'] and current['audits'] == 1
    assert json.loads(current['results'][0]['after'])['binding_matches'] is False
    print('PASS: late accepted response is audited but cannot overwrite the committed manual release.', flush=True)


parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--image', default='mysql:8.0.35', help='Locally available MySQL 8 image; never pulled')
args = parser.parse_args()
repo = pathlib.Path(__file__).resolve().parents[1]
subprocess.run(['docker', 'image', 'inspect', args.image], capture_output=True, check=True, timeout=10)
container = 'laravel13idc-fix006-' + uuid.uuid4().hex[:12]

with tempfile.TemporaryDirectory(prefix='laravel13idc-fix006-mysql-') as temporary:
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
               DB_SOCKET=str(socket_dir / 'mysqld.sock'), DB_DATABASE='machine_isolated',
               DB_USERNAME='root', DB_PASSWORD='', CACHE_STORE='array', QUEUE_CONNECTION='sync', SESSION_DRIVER='array', LOG_CHANNEL='single', BCRYPT_ROUNDS='4',
               MACHINE_TEST_REPO=str(repo), MACHINE_TEST_ROOT=str(root))
    probe = root / 'probe.php'
    probe.write_text("""<?php
$pdo = new PDO('mysql:unix_socket='.getenv('DB_SOCKET').';dbname=machine_isolated', 'root', '');
$version = $pdo->query('SELECT VERSION()')->fetchColumn();
if (!str_starts_with($version, '8.')) { throw new RuntimeException('MySQL 8 is required.'); }
echo $version;
""")
    try:
        subprocess.run(['docker', 'run', '--detach', '--pull=never', '--name', container,
                        '--label', 'laravel13idc.remediation=FIX-006', '--network', 'none',
                        '--tmpfs', '/var/lib/mysql:rw,size=768m',
                        '--mount', 'type=bind,source=' + str(socket_dir) + ',target=/var/run/mysqld',
                        '--env', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes', '--env', 'MYSQL_DATABASE=machine_isolated',
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
        subprocess.run(['php', 'artisan', 'migrate', '--force', '--no-interaction'], cwd=repo, env=env, capture_output=True, check=True, timeout=90)
        subprocess.run(['vendor/bin/pest', 'tests/Feature/MachineOperationReconciliationTest.php', 'tests/Feature/ClassicMachineControlTest.php', 'tests/Feature/SecretRotationTest.php', '--compact'],
                       cwd=repo, env=env, check=True, timeout=180)
        run_machine_races(repo, root, env)
        print('PASS: isolated MySQL 8 machine reconciliation and multiprocess races; business databases untouched.', flush=True)
    finally:
        subprocess.run(['docker', 'rm', '--force', container], capture_output=True, timeout=20)
