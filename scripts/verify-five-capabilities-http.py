"""Run five-capability HTTP acceptance on private SQLite; --keep-server retains the offline browser fixture."""

import argparse
import base64
import datetime
import hashlib
import http.cookiejar
import json
import os
import pathlib
import re
import signal
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--keep-server', action='store_true', help='Keep the successful isolated fixture for browser review')
args = parser.parse_args()
repo = pathlib.Path(__file__).resolve().parents[1]
php = '/www/server/php/84/bin/php'
root = pathlib.Path(tempfile.mkdtemp(prefix='laravel13idc-five-http-'))
db = root / 'acceptance.sqlite'
db.touch()
for folder in ['public', 'framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private']:
    (root / folder).mkdir(parents=True)
(root / 'installed.lock').write_text('isolated five-capability HTTP acceptance')
with socket.socket() as listener:
    listener.bind(('127.0.0.1', 0))
    port = listener.getsockname()[1]
base = 'http://127.0.0.1:' + str(port)
password = 'Isolated-' + os.urandom(12).hex() + '!'
token = os.urandom(24).hex()
env = dict(os.environ, FIVE_HTTP_REPO=str(repo), FIVE_HTTP_ROOT=str(root), FIVE_HTTP_TOKEN=token, FIVE_HTTP_PASSWORD=password,
           APP_ENV='local', APP_DEBUG='false', APP_URL=base, APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(),
           APP_CONFIG_CACHE=str(root / 'config.php'), APP_ROUTES_CACHE=str(root / 'routes.php'),
           DB_CONNECTION='sqlite', DB_URL='', DB_DATABASE=str(db), DB_QUEUE_CONNECTION='sqlite', DB_QUEUE_TABLE='jobs',
           LARAVEL_STORAGE_PATH=str(root), VIEW_COMPILED_PATH=str(root / 'framework/views'), CACHE_STORE='file',
           SESSION_DRIVER='file', SESSION_SECURE_COOKIE='false', QUEUE_CONNECTION='database', MAIL_MAILER='array',
           LOG_CHANNEL='single', BCRYPT_ROUNDS='4')
(root / 'test-env.json').write_text(json.dumps(env))
(root / 'test-env.json').chmod(0o600)
bootstrap = r'''require getenv('FIVE_HTTP_REPO').'/vendor/autoload.php';
$app=require getenv('FIVE_HTTP_REPO').'/bootstrap/app.php';
$app->usePublicPath(getenv('FIVE_HTTP_ROOT').'/public');
'''
(root / 'console.php').write_text('<?php\n' + bootstrap + 'exit($app->handleCommand(new Symfony\\Component\\Console\\Input\\ArgvInput));\n')
(root / 'fixture.php').write_text('<?php\n' + bootstrap + r'''
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config('database.default')!=='sqlite'||config('database.connections.sqlite.database')!==getenv('FIVE_HTTP_ROOT').'/acceptance.sqlite')throw new RuntimeException('Private HTTP database only');
use App\Models\User;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
$roles=['customer'=>[], 'reviewer'=>['external-payout-reviewer'], 'executor'=>['external-payout-operator'],
    'confirmer'=>['external-payout-confirmer'], 'receivable'=>['finance-receivable'], 'refund'=>['finance-operator'],
    'settings'=>['finance-wallet-settings'], 'closure'=>[], 'browser-close'=>[]];
$users=[];
foreach($roles as $name=>$assigned) {
    $user=User::factory()->create(['name'=>'Isolated '.ucfirst($name),'email'=>$name.'@example.test','password'=>getenv('FIVE_HTTP_PASSWORD'),
        'credit'=>'0.00','frozen_credit'=>'0.00','affiliate_balance'=>$name==='customer'?'50.00':'0.00','affiliate_frozen_balance'=>'0.00']);
    $users[$name]=$user;
    foreach($assigned as $role)app(App\Actions\AssignAdminRoleAction::class)->execute($user,$role,'Isolated HTTP acceptance role');
}
foreach(['epay_api_url'=>'https://pay.example.test','epay_pid'=>'five-http-merchant','epay_key'=>'five-http-payment-key'] as $key=>$value) {
    DB::table('system_settings')->insert(['group'=>'payment','key'=>$key,'value'=>$key==='epay_key'?Crypt::encryptString($value):$value,'is_encrypted'=>$key==='epay_key']);
}
DB::table('system_settings')->insert(['group'=>'telegram','key'=>'bot_token','value'=>'OFFLINE-HTTP-ONLY-TOKEN','is_encrypted'=>false]);
DB::table('system_settings')->insert(['group'=>'mail','key'=>'transport','value'=>'log','is_encrypted'=>false]);
App\Models\UserNotificationBinding::create(['user_id'=>$users['customer']->id,'channel'=>'telegram','target'=>'offline-http-chat','verified_at'=>now()]);
$debtor=User::factory()->create(['credit'=>'0.00','affiliate_balance'=>'0.00']);
$buyer=User::factory()->create(['credit'=>'0.00']);
$instance=App\Models\Instance::factory()->create(['user_id'=>$buyer->id,'status'=>App\Enums\InstanceStatus::CANCELLED]);
$invoice=Invoice::factory()->paid()->create(['user_id'=>$buyer->id,'instance_id'=>$instance->id]);
$commission=App\Models\AffiliateCommission::factory()->create(['invoice_id'=>$invoice->id,'buyer_id'=>$buyer->id,'referrer_id'=>$debtor->id,'amount'=>'10.00','status'=>'available']);
app(App\Actions\RefundInvoiceAction::class)->execute($invoice,'external_record',reason:'Isolated fixture original refunded commission',actor:$users['refund']);
$clawback=App\Models\FinancialTransaction::where('reference_key','clawback:'.$commission->id)->sole();
$late=Invoice::factory()->expired()->create(['user_id'=>$users['closure']->id,'product_id'=>null,'type'=>App\Enums\InvoiceType::WALLET_TOPUP,
    'gateway'=>'epay_wechat','total_amount'=>'2.00','gateway_amount'=>'2.00']);
$installation=app(App\Support\InstallationState::class);
$installation->write('installed.lock',$installation->seal(['format'=>1,'admin_id'=>$users['refund']->id],config('app.key')));
$result=['users'=>array_map(fn($user)=>$user->id,$users),'clawback'=>$clawback->id,'debtor'=>$debtor->id,'late_invoice'=>$late->id,'late_invoice_no'=>$late->invoice_no];
file_put_contents(getenv('FIVE_HTTP_ROOT').'/fixtures.json',json_encode($result,JSON_THROW_ON_ERROR));
echo 'Private fixtures created'.PHP_EOL;
''')
(root / 'router.php').write_text('<?php\n' + bootstrap + r'''
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$static=realpath(getenv('FIVE_HTTP_ROOT').'/public'.$path);
if($path!=='/'&&$static!==false&&str_starts_with($static,getenv('FIVE_HTTP_ROOT').'/public/')&&is_file($static))return false;
$request=Illuminate\Http\Request::capture();
$app->instance('request',$request);
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
if(config('database.default')!=='sqlite'||config('database.connections.sqlite.database')!==getenv('FIVE_HTTP_ROOT').'/acceptance.sqlite')throw new RuntimeException('Private HTTP database only');
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Http::fake(function($outgoing)use($request) {
    $url=$outgoing->url();
    $kind=str_starts_with($url,'https://pay.example.test/')?'epay':(str_starts_with($url,'https://api.telegram.org/')?'telegram':'blocked');
    file_put_contents(getenv('FIVE_HTTP_ROOT').'/transport-calls.jsonl',json_encode(['kind'=>$kind,'mode'=>$request->header('X-Offline-Transport','success')]).PHP_EOL,FILE_APPEND|LOCK_EX);
    if($kind==='epay')return Illuminate\Support\Facades\Http::response(['code'=>1,'payurl'=>getenv('APP_URL').'/offline-payment-example']);
    if($kind==='telegram') {
        if($request->header('X-Offline-Transport')==='uncertain')throw new Illuminate\Http\Client\ConnectionException('Offline acceptance simulated ambiguous timeout');
        return Illuminate\Support\Facades\Http::response(['ok'=>true,'result'=>['message_id'=>1]]);
    }
    throw new RuntimeException('Offline acceptance blocks all unsupported outgoing HTTP');
});
if(str_starts_with($path,'/__acceptance/')) {
    if(!hash_equals(getenv('FIVE_HTTP_TOKEN'),(string)$request->header('X-Acceptance-Token'))) {http_response_code(403);exit;}
    header('Content-Type: application/json');
    if($path==='/__acceptance/outbox-execute')app(App\Actions\ExecuteNotificationDeliveryAction::class)->execute($request->integer('id'));
    elseif($path==='/__acceptance/outbox-sweep')app(App\Actions\DispatchPendingNotificationsAction::class)->execute();
    else {http_response_code(404);exit;}
    echo json_encode(['ok'=>true]);exit;
}
$app->handleRequest($request);
''')


def cli(*arguments):
    result = subprocess.run([php, str(root / 'console.php'), *arguments], cwd=repo, env=env, capture_output=True, text=True, timeout=60)
    if result.returncode:
        raise RuntimeError(result.stdout + result.stderr)
    return result.stdout


def rows(sql, parameters=()):
    with sqlite3.connect(db) as connection:
        connection.row_factory = sqlite3.Row
        return [dict(row) for row in connection.execute(sql, parameters).fetchall()]


def ulid():
    alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'
    value = (int(time.time() * 1000) << 80) | int.from_bytes(os.urandom(10), 'big')
    result = ''
    for _ in range(26):
        result = alphabet[value & 31] + result
        value >>= 5
    return result


def client():
    return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))


def request(opener, path, body=None, status=200, accept='text/html', headers=None):
    data = None if body is None else urllib.parse.urlencode(body).encode()
    req = urllib.request.Request(base + path, data=data, headers={'Accept': accept, **(headers or {})})
    try:
        response = opener.open(req, timeout=20)
    except urllib.error.HTTPError as error:
        response = error
    content = response.read().decode()
    if response.code != status:
        (root / 'unexpected-response.html').write_text(content)
        raise AssertionError(path + ': expected ' + str(status) + ', got ' + str(response.code) + '; see unexpected-response.html')
    return content, response.headers


def csrf(html):
    match = re.search(r'name="_token"\s+value="([^"]+)"', html) or re.search(r'name="csrf-token"\s+content="([^"]+)"', html)
    assert match, 'Missing real CSRF token'
    return match.group(1)


def login(name):
    opener = client()
    form, _ = request(opener, '/login')
    request(opener, '/login', {'_token': csrf(form), 'email': name + '@example.test', 'password': password})
    return opener


def post(opener, path, body, status=200, accept='text/html'):
    page, _ = request(opener, '/console/wallet')
    return request(opener, path, {'_token': csrf(page), **body}, status, accept)


def callback(invoice_no, trade, amount):
    payload = {'pid': 'five-http-merchant', 'out_trade_no': invoice_no, 'trade_no': trade, 'money': amount, 'trade_status': 'TRADE_SUCCESS'}
    canonical = '&'.join(key + '=' + payload[key] for key in sorted(payload)) + 'five-http-payment-key'
    payload.update(sign=hashlib.md5(canonical.encode()).hexdigest(), sign_type='MD5')
    result, _ = request(client(), '/api/payments/epay_wechat/notify?' + urllib.parse.urlencode(payload))
    assert result == 'success', result


server = None
passed = False
checks = []
try:
    cli('migrate', '--force', '--no-interaction')
    cli('filament:assets')
    setup = subprocess.run([php, str(root / 'fixture.php')], cwd=repo, env=env, capture_output=True, text=True, timeout=30)
    (root / 'setup.log').write_text(setup.stdout + setup.stderr)
    assert setup.returncode == 0, 'Fixture setup failed; see ' + str(root / 'setup.log')
    fixtures = json.loads((root / 'fixtures.json').read_text())
    log = open(root / 'http.log', 'w+')
    server = subprocess.Popen([php, '-d', 'variables_order=EGPCS', '-S', '127.0.0.1:' + str(port), '-t', str(root / 'public'), str(root / 'router.php')],
                              cwd=repo, env=env, stdout=log, stderr=log, start_new_session=True)
    (root / 'server.json').write_text(json.dumps({'pid': server.pid, 'url': base, 'root': str(root)}))
    deadline = time.monotonic() + 10
    while True:
        try:
            request(client(), '/up')
            break
        except (OSError, urllib.error.URLError):
            if time.monotonic() > deadline:
                raise RuntimeError('Private HTTP server did not start')
            time.sleep(.1)
    browsers = {name: login(name) for name in ['customer', 'reviewer', 'executor', 'confirmer', 'receivable', 'refund', 'settings', 'closure']}
    customer = browsers['customer']
    request(customer, '/console/wallet/topups', {'amount': '10.00', 'gateway': 'epay_wechat', 'request_id': ulid()}, 419, 'application/json')
    post(customer, '/admin/finance/wallet-topup-settings', {'enabled': '1', 'reason': 'Forbidden ordinary account setting', 'confirm': '1'}, 403, 'application/json')
    post(browsers['settings'], '/admin/finance/wallet-topup-settings', {'enabled': '1', 'reason': 'Isolated HTTP enablement', 'confirm': '1'})
    content, _ = post(customer, '/console/wallet/topups', {'request_id': ulid(), 'amount': '10.00', 'gateway': 'epay_wechat'}, 201, 'application/json')
    topup = json.loads(content)
    post(customer, '/console/invoices/' + str(topup['invoice_id']) + '/pay', {}, 200, 'application/json')
    attempt = rows('select out_trade_no from payment_attempts where invoice_id=?', (topup['invoice_id'],))[0]
    callback(attempt['out_trade_no'], 'HTTP-TOPUP-ONE', '10.00')
    callback(attempt['out_trade_no'], 'HTTP-TOPUP-ONE', '10.00')
    assert rows('select credit from users where id=?', (fixtures['users']['customer'],))[0]['credit'] == 10
    assert len(rows('select id from financial_transactions where reference_key=?', ('topup:' + str(topup['invoice_id']),))) == 1
    post(browsers['refund'], '/admin/finance/invoices/' + str(topup['invoice_id']) + '/refund',
         {'mode': 'refund_wallet', 'keep_service': '1', 'reason': 'Forbidden topup refund', 'confirm': '1'}, 422, 'application/json')
    page, _ = request(customer, '/console/invoices/' + str(topup['invoice_id']))
    assert '充值已到账' in page and '交付信息等待确认' not in page
    checks.append('wallet creation, signed Epay callback, duplicate receipt, refund isolation, CSRF and settings authorization')

    post(customer, '/console/affiliate/external', {'request_id': ulid(), 'amount': '10.00', 'payout_method': 'alipay',
        'payee_name': 'Offline acceptance only', 'payee_account': 'NOT-A-REAL-ACCOUNT'})
    withdrawal = rows('select * from affiliate_withdrawals where user_id=? and destination=?', (fixtures['users']['customer'], 'external'))[0]
    payout_path = '/admin/external-payouts/' + str(withdrawal['id'])
    post(customer, payout_path, {'request_id': ulid(), 'operation': 'approve', 'reason': 'Forbidden customer approval', 'confirm': '1'}, 403, 'application/json')
    post(browsers['reviewer'], payout_path, {'request_id': ulid(), 'operation': 'approve', 'reason': 'Offline approve fixture', 'confirm': '1'})
    balance = rows('select affiliate_balance,affiliate_frozen_balance from users where id=?', (fixtures['users']['customer'],))[0]
    assert balance == {'affiliate_balance': 40, 'affiliate_frozen_balance': 10}, balance
    post(browsers['executor'], payout_path, {'request_id': ulid(), 'operation': 'start', 'reason': 'Offline execution intent only', 'confirm': '1'})
    intent = rows('select payout_intent_key from affiliate_withdrawals where id=?', (withdrawal['id'],))[0]['payout_intent_key']
    post(browsers['confirmer'], payout_path, {'request_id': ulid(), 'operation': 'unknown', 'intent_key': intent,
        'evidence': 'SIMULATED UNKNOWN: no real transfer attempted', 'reason': 'Offline unknown fixture', 'confirm': '1'})
    assert rows('select affiliate_frozen_balance from users where id=?', (fixtures['users']['customer'],))[0]['affiliate_frozen_balance'] == 10
    paid = {'request_id': ulid(), 'operation': 'paid', 'intent_key': intent, 'evidence': 'SIMULATED ACCEPTANCE RECEIPT, NOT REAL MONEY',
        'confirmed_amount': '10.00', 'currency': 'CNY', 'external_reference': 'HTTP-OFFLINE-PAID-ONE', 'reason': 'Offline proof fixture', 'confirm': '1'}
    post(browsers['confirmer'], payout_path, paid)
    post(browsers['confirmer'], payout_path, paid)
    assert rows('select status from affiliate_withdrawals where id=?', (withdrawal['id'],))[0]['status'] == 'paid'
    assert rows('select affiliate_frozen_balance from users where id=?', (fixtures['users']['customer'],))[0]['affiliate_frozen_balance'] == 0
    assert len(rows('select id from financial_transactions where reference_key=?', ('payout:' + str(withdrawal['id']),))) == 1
    checks.append('external payout request, scoped roles, reserve, execution intent, unknown and uniquely confirmed simulated payment')

    debt_path = '/admin/finance/receivables?clawback=' + str(fixtures['clawback'])
    debt_page, _ = request(browsers['receivable'], debt_path)
    version = re.search(r'name="version" value="([a-f0-9]{64})"', debt_page).group(1)
    record = {'request_id': ulid(), 'version': version, 'amount': '4.00', 'source': 'external_received', 'currency': 'CNY',
        'external_channel': 'bank', 'external_reference': 'HTTP-OFFLINE-RECEIPT', 'received_at': (datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(minutes=1)).isoformat(),
        'reason': 'Offline partial receipt fixture', 'confirm': '1'}
    post(customer, '/admin/finance/receivables/' + str(fixtures['clawback']) + '/settlements', record, 403, 'application/json')
    post(browsers['receivable'], '/admin/finance/receivables/' + str(fixtures['clawback']) + '/settlements', record)
    settlement = rows('select id from affiliate_receivable_settlements where request_id=?', (record['request_id'],))[0]['id']
    debt_page, _ = request(browsers['receivable'], debt_path)
    version = re.search(r'name="version" value="([a-f0-9]{64})"', debt_page).group(1)
    post(browsers['receivable'], '/admin/finance/receivable-settlements/' + str(settlement) + '/reverse',
         {'request_id': ulid(), 'version': version, 'correction_reference': 'HTTP-OFFLINE-CORRECTION', 'reason': 'Offline correction fixture', 'confirm': '1'})
    assert len(rows('select id from affiliate_receivable_reversals where settlement_id=?', (settlement,))) == 1
    assert rows('select credit,affiliate_balance from users where id=?', (fixtures['debtor'],))[0] == {'credit': 0, 'affiliate_balance': 0}
    checks.append('receivable partial registration and append-only reversal with zero wallet movement and financial authorization')

    closer = browsers['closure']
    close_page, _ = request(closer, '/console/account/close')
    request(closer, '/console/account/close', {'_token': csrf(close_page), 'current_password': password, 'confirm': '1'})
    closed = rows('select deleted_at,anonymized_at,email from users where id=?', (fixtures['users']['closure'],))[0]
    assert closed['deleted_at'] and closed['anonymized_at'] and closed['email'] != 'closure@example.test'
    registration = client()
    register_page, _ = request(registration, '/register')
    request(registration, '/register', {'_token': csrf(register_page), 'name': 'Re-registered isolated account', 'email': 'closure@example.test',
        'password': password, 'password_confirmation': password})
    new_owner = rows('select id,credit from users where email=?', ('closure@example.test',))[0]
    assert new_owner['id'] != fixtures['users']['closure'] and new_owner['credit'] == 0
    callback(fixtures['late_invoice_no'], 'HTTP-CLOSED-LATE', '2.00')
    assert rows('select credit from users where id=?', (fixtures['users']['closure'],))[0]['credit'] == 2
    assert rows('select credit from users where id=?', (new_owner['id'],))[0]['credit'] == 0
    checks.append('password-confirmed closure, released email re-registration, historical late credit retained on original identity')

    deliveries = rows('select id from notification_deliveries where user_id=? and channel=? order by id', (fixtures['users']['customer'], 'telegram'))
    assert len(deliveries) >= 2, deliveries
    private = {'X-Acceptance-Token': token}
    success_id, uncertain_id = deliveries[0]['id'], deliveries[1]['id']
    request(client(), '/__acceptance/outbox-execute?id=' + str(success_id), headers=private)
    assert rows('select status,attempts from notification_deliveries where id=?', (success_id,))[0] == {'status': 'sent', 'attempts': 1}
    request(client(), '/__acceptance/outbox-execute?id=' + str(uncertain_id), headers=private | {'X-Offline-Transport': 'uncertain'})
    before = (root / 'transport-calls.jsonl').read_text().count('\n')
    request(client(), '/__acceptance/outbox-sweep', headers=private)
    request(client(), '/__acceptance/outbox-execute?id=' + str(uncertain_id), headers=private)
    after = (root / 'transport-calls.jsonl').read_text().count('\n')
    assert rows('select status,attempts from notification_deliveries where id=?', (uncertain_id,))[0] == {'status': 'uncertain', 'attempts': 1}
    assert before == after, 'Uncertain delivery was automatically resent'
    assert len(rows('select id from jobs')) > 0, 'Durable wakeups must remain queued without a worker'
    checks.append('durable database queue, explicit offline delivery, uncertain timeout with no automatic resend')
    assert not rows('select id from instances where user_id=?', (fixtures['users']['customer'],))
    assert not rows('select id from instances where user_id=?', (fixtures['users']['closure'],))
    transports = [json.loads(line) for line in (root / 'transport-calls.jsonl').read_text().splitlines()]
    assert all(entry['kind'] in ['epay', 'telegram'] for entry in transports), transports
    cli('cache:clear')
    accounts = [{'email': name + '@example.test', 'password': password, 'purpose': name} for name in ['customer', 'reviewer', 'executor', 'confirmer', 'receivable', 'refund', 'settings', 'browser-close']]
    (root / 'accounts.json').write_text(json.dumps(accounts, indent=2))
    (root / 'accounts.json').chmod(0o600)
    (root / 'evidence.json').write_text(json.dumps({'url': base, 'checks': checks, 'offline_transports': transports,
        'csrf_enforced': True, 'queue_worker_started': False, 'topup_invoice': topup['invoice_id'], 'withdrawal': withdrawal['id'],
        'clawback': fixtures['clawback'], 'outbox_sent': success_id, 'outbox_uncertain': uncertain_id}, indent=2))
    passed = True
    print('PASS: ' + '; '.join(checks), flush=True)
    print('Evidence directory: ' + str(root), flush=True)
    if args.keep_server:
        print('Browser URL: ' + base + '\nIsolated accounts: ' + str(root / 'accounts.json'), flush=True)
finally:
    if server is not None and not (passed and args.keep_server):
        os.killpg(server.pid, signal.SIGTERM)
        server.wait(timeout=10)
        (root / 'server-stopped.txt').write_text('Stopped owned PHP server PID ' + str(server.pid))
    elif server is not None:
        (root / 'server-retained.txt').write_text('Explicit --keep-server: owned PHP server PID ' + str(server.pid))
