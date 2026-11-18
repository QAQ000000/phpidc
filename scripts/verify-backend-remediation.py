"""FIX-007..017 isolated scale/performance and slow-worker scheduling acceptance. No supplier network."""

import base64
import json
import os
import pathlib
import subprocess
import tempfile
import time

repo = pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='laravel13idc-fix007-017-') as temporary:
    root = pathlib.Path(temporary)
    storage = root / 'storage'
    for folder in ['framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private']:
        (storage / folder).mkdir(parents=True)
    (root / 'acceptance.sqlite').touch()
    env = dict(os.environ, APP_ENV='testing', APP_DEBUG='false',
               APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(),
               APP_CONFIG_CACHE=str(root / 'config.php'), APP_ROUTES_CACHE=str(root / 'routes.php'),
               LARAVEL_STORAGE_PATH=str(storage), VIEW_COMPILED_PATH=str(storage / 'framework/views'),
               DB_CONNECTION='sqlite', DB_URL='', DB_DATABASE=str(root / 'acceptance.sqlite'),
               CACHE_STORE='file', QUEUE_CONNECTION='database', SESSION_DRIVER='array', LOG_CHANNEL='single', BCRYPT_ROUNDS='4',
               REMEDIATION_REPO=str(repo), REMEDIATION_ROOT=str(root))
    fixture = root / 'acceptance.php'
    fixture.write_text(r'''<?php
require getenv('REMEDIATION_REPO').'/vendor/autoload.php';
$app = require getenv('REMEDIATION_REPO').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Actions\DispatchInstanceLifecycleAction;
use App\Enums\DriverType;
use App\Enums\InstanceStatus;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\PublicContentController;
use App\Models\CmsArticle;
use App\Models\CmsCategory;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Support\CmsRenderer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
$op=$argv[1];
$root=getenv('REMEDIATION_ROOT');
function checked(bool $value,string $message): void { if(!$value) { throw new RuntimeException($message); } }
Http::preventStrayRequests();
if($op!=='worker') { Http::fake(fn()=>throw new LogicException('Supplier HTTP must only run in the isolated worker.')); }
if($op==='seed') {
    file_put_contents(storage_path('installed.lock'),'isolated performance acceptance');
    $user=User::factory()->create();
    $api=UpstreamApi::factory()->create();
    $product=Product::factory()->create(['driver_type'=>DriverType::ZJMF_API,'upstream_api_id'=>$api->id]);
    for($batch=0;$batch<10;$batch++) {
        $rows=[];
        for($i=0;$i<100;$i++) {
            $n=$batch*100+$i+1;
            $rows[]=['user_id'=>$user->id,'product_id'=>$product->id,'driver_type'=>'zjmf_api','upstream_api_id'=>$api->id,
                'display_name'=>'Isolated '.$n,'upstream_instance_id'=>(string)$n,'downstream_token'=>md5('isolated-'.$n),'status'=>'Active','billing_cycle'=>'monthly',
                'currency'=>'CNY','recurring_amount'=>'1.00','next_due_at'=>($n<=20?now()->addDay():now()->addMonth())->toDateTimeString()];
        }
        DB::table('instances')->insert($rows);
    }
    Invoice::factory()->create(['user_id'=>$user->id,'product_id'=>$product->id,'expires_at'=>now()->subDays(2)]);
    $category=CmsCategory::create(['type'=>'help','slug'=>'scale','name'=>'Scale']);
    for($batch=0;$batch<50;$batch++) {
        $rows=[];
        for($i=0;$i<100;$i++) {
            $n=$batch*100+$i+1;
            $rows[]=['cms_category_id'=>$category->id,'title'=>'Article '.$n,'slug'=>'entry-'.$n,'body'=>str_repeat('LARGE-BODY ',3277),
                'content_format'=>'markdown','is_published'=>1,'published_at'=>now()->subDay()->toDateTimeString()];
        }
        DB::table('cms_articles')->insert($rows);
    }
    echo json_encode(['instances'=>Instance::count(),'articles'=>CmsArticle::count()]);
} elseif($op==='dispatch') {
    $start=hrtime(true);
    $count=app(DispatchInstanceLifecycleAction::class)->execute();
    echo json_encode(['candidates'=>$count,'queued'=>DB::table('jobs')->count(),'dispatch_ms'=>round((hrtime(true)-$start)/1e6,2)]);
} elseif($op==='worker') {
    Http::fake(function($request)use($root){
        checked(DB::transactionLevel()===0,'HTTP held a transaction.');
        $path=parse_url($request->url(),PHP_URL_PATH);
        if($path==='/host/header') {
            file_put_contents($root.'/worker.ready',(string)$request['host_id']);usleep(3000000);
            $deadline=microtime(true)+25;
            while(!is_file($root.'/worker.go')) { checked(microtime(true)<$deadline,'Slow-worker barrier timed out.');usleep(10000); }
        }
        return Http::response(match($path){
            '/zjmf_api_login'=>['status'=>200,'jwt'=>'isolated-jwt'],
            '/host/header'=>['status'=>200,'data'=>['host_data'=>['id'=>(int)$request['host_id'],'domainstatus'=>'Active','productid'=>17,
                'nextduedate'=>now()->addMonth()->toDateTimeString(),'billingcycle'=>'monthly','currency_code'=>'CNY']]],
            default=>throw new LogicException('Unexpected worker endpoint.'),
        });
    });
    $start=hrtime(true);
    Artisan::call('queue:work',['connection'=>'database','--once'=>true,'--queue'=>'lifecycle','--sleep'=>0,'--tries'=>3,'--timeout'=>180]);
    checked(Instance::whereNotNull('last_synced_at')->count()===1,'Slow worker did not sync exactly one service: '.Artisan::output().' '.json_encode(DB::table('failed_jobs')->pluck('exception')));
    echo json_encode(['worker_ms'=>round((hrtime(true)-$start)/1e6,2),'synced'=>1,'failed_jobs'=>DB::table('failed_jobs')->count()]);
} elseif($op==='overlap') {
    $instance=Instance::where('upstream_instance_id',trim(file_get_contents($root.'/worker.ready')))->firstOrFail();
    Illuminate\Support\Facades\Queue::pushOn('lock-probe',new App\Jobs\ProcessInstanceLifecycleJob($instance->id,app(App\Support\MachineOperation::class)->binding($instance)));
    Artisan::call('queue:work',['connection'=>'database','--once'=>true,'--queue'=>'lock-probe','--sleep'=>0,'--tries'=>3,'--timeout'=>180]);
    $job=DB::table('jobs')->where('queue','lock-probe')->sole();
    checked($job->attempts===1 && $job->available_at>time(),'Shared host lock did not defer the duplicate worker.');
    echo json_encode(['overlap_deferred'=>true,'duplicate_http_calls'=>0]);
} elseif($op==='schedule') {
    Illuminate\Support\Carbon::setTestNow(now()->startOfHour());
    $start=hrtime(true);
    Artisan::call('schedule:run',['--no-interaction'=>true]);
    checked(Invoice::sole()->status===InvoiceStatus::EXPIRED,'Expiry task was blocked.');
    echo json_encode(['schedule_ms'=>round((hrtime(true)-$start)/1e6,2),'expired'=>true,'queued'=>DB::table('jobs')->count(),
        'commands'=>Artisan::output()]);
} elseif($op==='index') {
    $queries=0;
    DB::listen(function($q)use(&$queries){
        if(str_starts_with($q->sql,'select')&&str_contains($q->sql,'from "cms_articles"')) {
            $select=explode(' from ',$q->sql)[0];
            checked(!str_contains($select,'body')&&!str_contains($select,'*'),'Index selected article bodies.');$queries++;
        }
    });
    $base=memory_get_usage(true);memory_reset_peak_usage();$start=hrtime(true);
    $sitemap=app(PublicContentController::class)->sitemap()->getContent();
    $xml=simplexml_load_string($sitemap);checked($xml!==false&&count($xml->url)===5007,'Invalid/incomplete sitemap.');unset($xml);
    $llms=app(PublicContentController::class)->llms()->getContent();
    checked(str_contains($llms,'entry-5000.md')&&!str_contains($llms,'LARGE-BODY'),'Invalid llms index.');
    echo json_encode(['articles'=>5000,'article_bytes_each'=>32770,'article_queries'=>$queries,
        'index_ms'=>round((hrtime(true)-$start)/1e6,2),'peak_mb'=>round(memory_get_peak_usage(true)/1048576,2),
        'peak_delta_mb'=>round((memory_get_peak_usage(true)-$base)/1048576,2),'output_bytes'=>strlen($sitemap)+strlen($llms)]);
} elseif($op==='excerpt') {
    $size=(int)$argv[2];
    $renderer=new class extends CmsRenderer {public int $renders=0;public function html(CmsArticle $a):string {$this->renders++;return parent::html($a);}};
    Cache::flush();$articles=[];
    for($n=0;$n<6;$n++) {$articles[]=new CmsArticle(['body'=>$n.' '.str_repeat("A paragraph **bold** with a [link](https://example.test).\n\n",(int)ceil($size/58)), 'content_format'=>'markdown']);}
    memory_reset_peak_usage();$start=hrtime(true);
    foreach($articles as $article){$renderer->excerpt($article);}
    $cold=(hrtime(true)-$start)/1e6;$start=hrtime(true);
    foreach($articles as $article){$renderer->excerpt($article);}
    $hot=(hrtime(true)-$start)/1e6;checked($renderer->renders===6,'Hot cache parsed the body again.');
    echo json_encode(['sample_bytes'=>$size,'articles'=>6,'cold_ms'=>round($cold,2),'hot_ms'=>round($hot,2),'cold_renders'=>6,'hot_renders'=>0,'peak_mb'=>round(memory_get_peak_usage(true)/1048576,2)]);
}
''')

    def run(*args):
        result = subprocess.run(['php', '-d', 'memory_limit=256M', str(fixture), *args], cwd=repo, env=env, capture_output=True, text=True, timeout=90)
        if result.returncode:
            raise RuntimeError(result.stdout + result.stderr)
        try:
            return json.loads(result.stdout)
        except json.JSONDecodeError as exc:
            raise RuntimeError(result.stdout + result.stderr) from exc

    subprocess.run(['php', 'artisan', 'migrate', '--force', '--no-interaction'], cwd=repo, env=env, check=True, capture_output=True, timeout=90)
    print('FIXTURE', json.dumps(run('seed')), flush=True)
    print('DISPATCH', json.dumps(run('dispatch')), flush=True)
    worker = subprocess.Popen(['php', str(fixture), 'worker'], cwd=repo, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    try:
        deadline = time.monotonic() + 15
        while not (root / 'worker.ready').is_file():
            if worker.poll() is not None or time.monotonic() > deadline:
                stdout, stderr = worker.communicate(timeout=5)
                raise RuntimeError('Slow worker did not reach its isolated HTTP barrier: ' + stdout + stderr)
            time.sleep(0.02)
        print('OVERLAP', json.dumps(run('overlap')), flush=True)
        timer = run('schedule')
        assert worker.poll() is None, 'Minute schedule waited for the slow upstream worker.'
        assert all(name in timer['commands'] for name in ['invoices:expire', 'fulfillment:dispatch-stale', 'cards:reap-reservations', 'instances:process-lifecycle', 'upstream:classic-sync', 'affiliate:process']), timer
        del timer['commands']
        print('SCHEDULE_WHILE_UPSTREAM_SLOW', json.dumps(timer), flush=True)
        (root / 'worker.go').write_text('release after minute tasks completed')
        stdout, stderr = worker.communicate(timeout=20)
        assert worker.returncode == 0, stdout + stderr
        print('SLOW_WORKER', stdout.strip(), flush=True)
    finally:
        if worker.poll() is None:
            worker.terminate()
            worker.wait(timeout=10)
    print('INDEX', json.dumps(run('index')), flush=True)
    for size in [10240, 102400, 512000]:
        print('EXCERPT', json.dumps(run('excerpt', str(size))), flush=True)
    print('PASS: disposable SQLite/file-cache/database-queue only; no preview/business database or real supplier touched.', flush=True)
