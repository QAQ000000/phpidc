"""Verify C21 claim budgets and process races on a disposable, offline MySQL 8."""

import argparse
import base64
import json
import os
import pathlib
import subprocess
import tempfile
import time
import uuid


def run_claim_races(repo, root, env):
    (root / 'storage/installed.lock').write_text('isolated C21 claim races')
    env = dict(env, CACHE_STORE='file', QUEUE_CONNECTION='database')
    worker = root / 'claim-race.php'
    worker.write_text(r'''<?php
require getenv('FULFILLMENT_TEST_REPO').'/vendor/autoload.php';
$app = require getenv('FULFILLMENT_TEST_REPO').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Actions\DispatchStalePendingTasksAction;
use App\Actions\ExecuteFulfillmentTaskAction;
use App\Contracts\FulfillmentDriver;
use App\Data\ProvisionResult;
use App\Drivers\SelfHosted\RedemptionCodeDriver;
use App\Models\FulfillmentTask;
use App\Models\RedemptionCode;
use App\Services\DriverManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
Http::preventStrayRequests();
Carbon::setTestNow(Carbon::parse('2026-11-18 12:00:00', config('app.timezone'))->addSeconds((int)($argv[5] ?? 0)));
$root = getenv('FULFILLMENT_TEST_ROOT');
function waitForFile(string $path): void {
    $deadline=microtime(true)+15;
    while (!is_file($path)) {
        if (microtime(true)>$deadline) { throw new RuntimeException('Process barrier timed out.'); }
        usleep(10000);
    }
}
if ($argv[1] === 'fixture') {
    $task=FulfillmentTask::factory()->create(['attempts'=>(int)$argv[2], 'created_at'=>now()->subMinutes(2), 'available_at'=>now()->subSecond()]);
    $task->user->forceFill(['credit'=>'17.00', 'frozen_credit'=>'4.00'])->save();
    RedemptionCode::factory()->create(['product_id'=>$task->instance->product_id]);
    echo json_encode(['id'=>$task->id], JSON_THROW_ON_ERROR);
} elseif ($argv[1] === 'inspect') {
    $task=FulfillmentTask::findOrFail($argv[2]); $instance=$task->instance;
    $jobs=DB::table('jobs')->get()->filter(function($job) use ($task) {
        $payload=json_decode($job->payload, true, flags:JSON_THROW_ON_ERROR);
        if (($payload['displayName'] ?? '') !== App\Jobs\ExecuteFulfillmentTaskJob::class) { return false; }
        return unserialize($payload['data']['command'])->taskId === $task->id;
    })->count();
    $calls=$root.'/calls-'.$task->id;
    echo json_encode(['status'=>$task->status->value, 'attempts'=>$task->attempts, 'worker'=>$task->locked_by,
        'available'=>$task->available_at?->toDateTimeString(), 'instance'=>$instance->status->value,
        'delivered'=>$instance->encrypted_credentials!==null, 'credit'=>$task->user->credit, 'frozen'=>$task->user->frozen_credit,
        'ledger'=>DB::table('financial_transactions')->where('user_id',$task->user_id)->count(),
        'sold'=>RedemptionCode::where('instance_id',$instance->id)->where('status','sold')->count(),
        'audits'=>DB::table('audit_logs')->where('subject_id',$instance->id)->where('action','instance.provision')->count(),
        'calls'=>is_file($calls)?count(file($calls)):0, 'jobs'=>$jobs], JSON_THROW_ON_ERROR);
} elseif ($argv[1] === 'run') {
    $task=FulfillmentTask::findOrFail($argv[2]); $mode=$argv[3];
    if ($argv[4] !== '-') {
        $barrier=$root.'/'.$argv[4];
        file_put_contents($barrier.'.ready.'.getmypid(), 'ready');
        waitForFile($barrier.'.go');
    }
    if ($mode==='sweep') {
        app(DispatchStalePendingTasksAction::class)->execute();
    } else {
        $driver=Mockery::mock(FulfillmentDriver::class);
        $driver->shouldReceive('provision')->andReturnUsing(function($instance,$params) use($root,$task,$mode) {
            if (DB::transactionLevel()!==0) { throw new LogicException('Driver called under a transaction.'); }
            $current=$task->fresh();
            if ($current->status!==App\Enums\FulfillmentTaskStatus::RUNNING || $current->attempts<1 || !$current->lease_expires_at->isFuture()) {
                throw new LogicException('Claim and lease were not committed together.');
            }
            file_put_contents($root.'/calls-'.$task->id, $current->attempts."\n", FILE_APPEND|LOCK_EX);
            if ($mode==='crash') {
                // Kill only this disposable test child after its committed claim.
                posix_kill(getmypid(), 9);
                throw new LogicException('The isolated worker did not terminate.');
            }
            if ($mode==='failure') { return ProvisionResult::fail('Temporary supplier failure.', retryable:true); }
            $result=(new RedemptionCodeDriver)->provision($instance,$params);
            if ($mode==='late') {
                file_put_contents($root.'/inflight-'.$task->id, 'inflight');
                waitForFile($root.'/return-'.$task->id);
            }
            return $result;
        });
        $manager=Mockery::mock(DriverManager::class);
        $manager->shouldReceive('fulfillment')->andReturn($driver);
        app()->instance(DriverManager::class, $manager);
        app(ExecuteFulfillmentTaskAction::class)->execute($task->id);
    }
    echo json_encode(['ok'=>true], JSON_THROW_ON_ERROR);
} else { throw new RuntimeException('Unknown isolated operation.'); }
''')

    def call(*args):
        result = subprocess.run(['php', str(worker), *map(str, args)], cwd=repo, env=env,
                                capture_output=True, text=True, check=True, timeout=25)
        return json.loads(result.stdout)

    def inspect(task_id):
        result = call('inspect', task_id)
        assert result['credit'] == '17.00' and result['frozen'] == '4.00' and result['ledger'] == 0, result
        return result

    def wait_ready(pattern, count, processes):
        deadline = time.monotonic() + 15
        while len(list(root.glob(pattern))) < count:
            if time.monotonic() >= deadline or any(p.poll() is not None for p in processes):
                raise RuntimeError('Workers failed to reach their process barrier.')
            time.sleep(0.01)

    def finish(process):
        output, error = process.communicate(timeout=25)
        if process.returncode != 0:
            raise RuntimeError('Isolated worker failed: ' + error + output)
        assert json.loads(output)['ok'] is True

    def race(task_id, modes, elapsed=0):
        barrier = 'claim-' + uuid.uuid4().hex
        processes = [subprocess.Popen(['php', str(worker), 'run', str(task_id), mode, barrier, str(elapsed)],
                                     cwd=repo, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
                     for mode in modes]
        try:
            wait_ready(barrier + '.ready.*', len(processes), processes)
            (root / (barrier + '.go')).write_text('go')
            for process in processes:
                finish(process)
        finally:
            for process in processes:
                if process.poll() is None:
                    process.kill()
                    process.wait()

    for attempts, modes in [(0, ['success', 'success']), (4, ['success', 'success']),
                            (0, ['sweep', 'success']), (0, ['sweep', 'sweep'])]:
        task_id = call('fixture', attempts)['id']
        race(task_id, modes)
        result = inspect(task_id)
        if modes == ['sweep', 'sweep']:
            assert result['status'] == 'pending' and result['attempts'] == result['calls'] == result['sold'] == 0, result
            assert result['jobs'] == 1 and result['available'] == '2026-11-18 11:59:59', result
            call('run', task_id, 'success', '-', 0)
            result = inspect(task_id)
        assert result['status'] == 'success' and result['attempts'] == attempts + 1, result
        assert result['calls'] == result['sold'] == result['audits'] == 1 and result['delivered'], result
        print('PASS process race: ' + '/'.join(modes) + ' from attempts=' + str(attempts), flush=True)

    task_id = call('fixture', 0)['id']
    elapsed = 0
    for index, delay in enumerate([60, 300, 900, 900, 900], 1):
        race(task_id, ['failure', 'failure'], elapsed)
        result = inspect(task_id)
        assert result['attempts'] == result['calls'] == index and result['sold'] == 0, result
        assert result['status'] == ('failed' if index == 5 else 'pending'), result
        elapsed += delay
    call('run', task_id, 'success', '-', elapsed)
    result = inspect(task_id)
    assert result['attempts'] == result['calls'] == 5 and result['jobs'] == 4, result
    print('PASS process races: five failure claims, four delayed retries, no sixth claim.', flush=True)

    for attempts in [0, 4]:
        task_id = call('fixture', attempts)['id']
        interrupted = subprocess.run(['php', str(worker), 'run', str(task_id), 'crash', '-', '0'],
                                     cwd=repo, env=env, capture_output=True, text=True, timeout=25)
        assert interrupted.returncode == -9, (interrupted.returncode, interrupted.stdout, interrupted.stderr)
        result = inspect(task_id)
        assert result['status'] == 'running' and result['attempts'] == attempts + 1, result
        call('run', task_id, 'sweep', '-', 211)
        result = inspect(task_id)
        assert result['attempts'] == attempts + 1 and result['worker'] is None, result
        if attempts == 0:
            assert result['status'] == 'pending' and result['available'] == '2026-11-18 12:04:31', result
            race(task_id, ['success', 'success'], 271)
            result = inspect(task_id)
            assert result['status'] == 'success' and result['attempts'] == 2 and result['sold'] == 1, result
        else:
            assert result['status'] == 'failed' and result['jobs'] == 0, result
            call('run', task_id, 'success', '-', 1200)
            assert inspect(task_id)['calls'] == 1
        print('PASS interrupted worker recovery from attempts=' + str(attempts), flush=True)

    task_id = call('fixture', 0)['id']
    late = subprocess.Popen(['php', str(worker), 'run', str(task_id), 'late', '-', '0'],
                            cwd=repo, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    try:
        wait_ready('inflight-' + str(task_id), 1, [late])
        call('run', task_id, 'sweep', '-', 211)
        call('run', task_id, 'success', '-', 271)
        (root / ('return-' + str(task_id))).write_text('return')
        finish(late)
        result = inspect(task_id)
        assert result['status'] == 'success' and result['attempts'] == result['calls'] == 2, result
        assert result['sold'] == result['audits'] == 1 and result['delivered'], result
    finally:
        if late.poll() is None:
            late.kill()
            late.wait()
    print('PASS late old worker cannot overwrite a new successful lease or redeliver.', flush=True)


parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--image', default='mysql:8.0.35', help='Locally available MySQL 8 image; never pulled')
args = parser.parse_args()
repo = pathlib.Path(__file__).resolve().parents[1]
subprocess.run(['docker', 'image', 'inspect', args.image], capture_output=True, check=True, timeout=10)
container = 'laravel13idc-fix003-' + uuid.uuid4().hex[:12]

with tempfile.TemporaryDirectory(prefix='laravel13idc-fix003-mysql-') as temporary:
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
               DB_SOCKET=str(socket_dir / 'mysqld.sock'), DB_DATABASE='fulfillment_isolated',
               DB_USERNAME='root', DB_PASSWORD='', CACHE_STORE='array', QUEUE_CONNECTION='sync', SESSION_DRIVER='array', LOG_CHANNEL='single', BCRYPT_ROUNDS='4',
               FULFILLMENT_TEST_REPO=str(repo), FULFILLMENT_TEST_ROOT=str(root))
    probe = root / 'probe.php'
    probe.write_text("""<?php
$pdo = new PDO('mysql:unix_socket='.getenv('DB_SOCKET').';dbname=fulfillment_isolated', 'root', '');
$version = $pdo->query('SELECT VERSION()')->fetchColumn();
if (!str_starts_with($version, '8.')) { throw new RuntimeException('MySQL 8 is required.'); }
echo $version;
""")
    try:
        subprocess.run(['docker', 'run', '--detach', '--pull=never', '--name', container,
                        '--label', 'laravel13idc.remediation=FIX-003', '--network', 'none',
                        '--tmpfs', '/var/lib/mysql:rw,size=768m',
                        '--mount', 'type=bind,source=' + str(socket_dir) + ',target=/var/run/mysqld',
                        '--env', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes', '--env', 'MYSQL_DATABASE=fulfillment_isolated',
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
        subprocess.run(['vendor/bin/pest', 'tests/Feature/FulfillmentClaimBudgetTest.php', 'tests/Feature/FulfillmentRecoveryTest.php', 'tests/Feature/ZjmfPurchaseTest.php', 'tests/Feature/Sub2ApiRemoteSalesTest.php', 'tests/Feature/RefundLifecycleTest.php', 'tests/Feature/RefundServiceGuardTest.php', '--compact'],
                       cwd=repo, env=env, check=True, timeout=180)
        # Scope the supplemental classic run to operations. The unrelated catalog test
        # strictly compares PHP key order; MySQL JSON normalizes object key order.
        # Its failed exploratory run is recorded in the FIX-003 acceptance document;
        # no old assertion or production serializer is changed here.
        subprocess.run(['vendor/bin/pest', 'tests/Feature/ClassicExpansionTest.php',
                        '--filter=renewal|upgrade|termination|suspend|swap|bind two|operation|delivery|notification', '--compact'],
                       cwd=repo, env=env, check=True, timeout=180)
        run_claim_races(repo, root, env)
        print('PASS: isolated MySQL 8 claim budgets, lease recovery and concurrent worker/sweeper fencing; business databases untouched.', flush=True)
    finally:
        subprocess.run(['docker', 'rm', '--force', container], capture_output=True, timeout=20)
