"""Isolated HTTP acceptance: local fake epay/ZJMF, temporary SQLite/storage/key."""
import argparse
import base64
import hashlib
import http.cookiejar
import http.server
import json
import os
import pathlib
import re
import signal
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--preview', action='store_true', help='Keep the isolated server for browser review until Enter')
parser.add_argument('--redis-server', help='Optional Redis server binary; starts an isolated temporary instance')
args = parser.parse_args()
repo = pathlib.Path(__file__).resolve().parents[1]


def free_port():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        return sock.getsockname()[1]


with tempfile.TemporaryDirectory(prefix='laravel13idc-classic-sale-') as tmp:
    root = pathlib.Path(tmp)
    db = root / 'sale.sqlite'
    db.touch()
    for folder in ['framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private']:
        (root / folder).mkdir(parents=True)
    (root / 'installed.lock').write_text('isolated acceptance')
    web_port = free_port()
    base = f'http://127.0.0.1:{web_port}'
    env = dict(os.environ, LOCAL_CLASSIC_TEST_REPO=str(repo), APP_ENV='local', APP_DEBUG='false', APP_URL=base,
               APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(), APP_CONFIG_CACHE=str(root / 'config.php'),
               APP_ROUTES_CACHE=str(root / 'routes.php'), DB_CONNECTION='sqlite', DB_URL='', DB_DATABASE=str(db),
               DB_QUEUE_CONNECTION='sqlite', DB_QUEUE_TABLE='jobs', LARAVEL_STORAGE_PATH=tmp, CACHE_STORE='file',
               SESSION_DRIVER='file', SESSION_SECURE_COOKIE='false', QUEUE_CONNECTION='database', LOG_CHANNEL='single')

    def cli(*args):
        result = subprocess.run(['php', 'artisan', *args], cwd=repo, env=env, capture_output=True, text=True, timeout=60)
        if result.returncode:
            raise RuntimeError(result.stdout + result.stderr)
        return result.stdout

    public = root / 'public'
    shutil.copytree(repo / 'public', public)
    env['CLASSIC_TEST_PUBLIC'] = str(public)
    bootstrap = "require getenv('LOCAL_CLASSIC_TEST_REPO').'/vendor/autoload.php';\n$app=require getenv('LOCAL_CLASSIC_TEST_REPO').'/bootstrap/app.php';\n$app->usePublicPath(getenv('CLASSIC_TEST_PUBLIC'));\n"
    console_bootstrap = root / 'console.php'
    console_bootstrap.write_text('<?php\n' + bootstrap + 'exit($app->handleCommand(new Symfony\\Component\\Console\\Input\\ArgvInput));\n')
    (public / 'index.php').write_text('<?php\n' + bootstrap + '$app->handleRequest(Illuminate\\Http\\Request::capture());\n')
    router = root / 'router.php'
    router.write_text("<?php $path=parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); if ($path !== '/' && is_file(getenv('CLASSIC_TEST_PUBLIC').$path)) { return false; } require getenv('CLASSIC_TEST_PUBLIC').'/index.php';")
    def cli(*arguments):
        result = subprocess.run(['php', str(console_bootstrap), *arguments], cwd=repo, env=env, capture_output=True, text=True, timeout=60)
        if result.returncode:
            raise RuntimeError(result.stdout + result.stderr)
        return result.stdout
    cli('migrate', '--force')
    cli('filament:assets')
    requests = []
    state = {'settled': False, 'paid': False, 'gateway_order': None, 'gateway_amount': None, 'cost': '8.00', 'status': 'Active', 'selected': 501, 'due': '2026-12-20 00:00:00', 'cancelled': False}

    class Supplier(http.server.BaseHTTPRequestHandler):
        def do_GET(self):
            self.respond('GET', urllib.parse.parse_qs(urllib.parse.urlsplit(self.path).query))

        def do_POST(self):
            body = self.rfile.read(int(self.headers.get('Content-Length', 0))).decode()
            self.respond('POST', urllib.parse.parse_qs(body, keep_blank_values=True))

        def respond(self, method, query):
            path = urllib.parse.urlsplit(self.path).path
            params = {k: v[0] for k, v in query.items()}
            requests.append((method, path, params))
            if path == '/mapi.php':
                with sqlite3.connect(db) as connection:
                    assert connection.execute('select count(*) from payment_attempts where out_trade_no=?',
                                              (params['out_trade_no'],)).fetchone()[0] == 1
                assert params['money'] in ['9.60', '2.40']
                state['gateway_amount'] = params['money']
                signed = {k: v for k, v in params.items() if k not in ['sign', 'sign_type'] and v != ''}
                digest = hashlib.md5(('&'.join(k + '=' + signed[k] for k in sorted(signed)) + 'epay-test-secret').encode()).hexdigest()
                assert params['sign'] == digest
                state['gateway_order'] = params['out_trade_no']
                result = {'code': 1, 'payurl': base + '/simulated-payment'}
            elif path == '/zjmf_api_login':
                assert params['password'] == 'supplier-api-secret'
                result = {'status': 200, 'jwt': 'supplier-private-jwt'}
            else:
                assert self.headers.get('Authorization') == 'Bearer supplier-private-jwt'
                if path == '/cart/get_product_config':
                    result = {'status': 200, 'data': {'products': {'id': 17, 'name': 'Acceptance Cloud', 'location_version': 1,
                        'password': {'show': 1, 'rule': {'upper': 1, 'lower': 1, 'num': 1, 'special': 1}}, 'host': {'show': 0}},
                        'product_pricings': [{'code': 'CNY', 'msetupfee': '7.00'}],
                        'config_groups': [{'id': 10, 'options': [{'id': 50, 'option_name': 'CPU', 'option_type': 1, 'upgrade': 1,
                            'sub': [{'id': 501, 'option_name': 'Small', 'pricings': [{'code': 'CNY', 'msetupfee': '1.00'}]},
                                    {'id': 502, 'option_name': 'Large', 'pricings': [{'code': 'CNY', 'msetupfee': '3.00'}]}]}]}],
                        'customfields': [{'id': 80, 'fieldname': 'License', 'fieldtype': 'password', 'required': 1}], 'advanced': []}}
                elif path == '/user_info':
                    result = {'status': 200, 'data': {'user': {'currency': 1}}}
                elif path == '/cart/stock_control':
                    result = {'status': 200, 'data': {'product': {'hidden': 0, 'stock_control': 1, 'qty': 5}}}
                elif path == '/cart/get_total':
                    result = {'status': 200, 'products': {'total': '10.00' if params.get('configoption[50]') == '502' else '8.00'}, 'currency': {'id': 1, 'code': 'CNY'}}
                elif path == '/cart/clear':
                    result = {'status': 400, 'domainstatus': 'Active', 'hostid': 999} if state['paid'] else {'status': 200}
                elif path == '/cart/add_to_shop':
                    assert params['currencyid'] == params['currecncyid'] == '1'
                    assert params['password'] == 'StrongPassword12!'
                    result = {'status': 200}
                elif path == '/cart/settle':
                    assert not state['settled'], 'Duplicate upstream order'
                    assert params['cart_data[pid]'] == '17'
                    with sqlite3.connect(db) as connection:
                        assert connection.execute('select status from fulfillment_tasks').fetchone()[0] == 'running'
                    state['settled'] = True
                    result = {'status': 200, 'data': {'invoiceid': 42, 'hostid': [999]}}
                elif path == '/use_credit_page':
                    result = {'status': 200, 'data': {'total': '2.00' if params['invoiceid'] == '44' else state['cost'], 'currency': {'code': 'CNY'}}}
                elif path == '/apply_credit':
                    assert params['use_credit'] == '1'
                    if params['invoiceid'] == '42':
                        assert not state['paid'], 'Duplicate upstream purchase payment'
                    elif params['invoiceid'] == '43':
                        state['due'] = '2027-01-20 00:00:00'
                    elif params['invoiceid'] == '44':
                        state['selected'] = 502
                        state['cost'] = '10.00'
                    else:
                        raise AssertionError('Unknown operation invoice')
                    state['paid'] = True
                    result = {'status': 1001, 'data': {'hostid': [999]}}
                elif path == '/host/header':
                    assert state['paid']
                    result = {'status': 200, 'data': {'host_data': {'domainstatus': state['status'], 'nextduedate': state['due'], 'productid': 17, 'billingcycle': 'monthly', 'dedicatedip': '192.0.2.17',
                              'username': 'root', 'password': 'DELIVERED-HOST-PASSWORD'}}}
                elif path == '/host/renewpage':
                    result = {'status': 200, 'data': {'host': {'amount': state['cost'], 'billingcycle': 'monthly'}, 'currency': {'code': 'CNY'}}}
                elif path == '/host/renew':
                    result = {'status': 200, 'data': {'invoiceid': 43}}
                elif path == '/upgrade/upgrade_config_post':
                    assert params['configoption[50]'] == '502'
                    result = {'status': 200}
                elif path == '/upgrade/upgrade_config_page':
                    result = {'status': 200, 'data': {'total': '2.00', 'currency': {'code': 'CNY'}}}
                elif path == '/upgrade/checkout_config_upgrade':
                    result = {'status': 200, 'data': {'invoiceid': 44}}
                elif path == '/upgrade/index/999':
                    result = {'status': 200, 'data': {'host': [{'oid': 50, 'subid': state['selected'], 'option_type': 1, 'qty': 1}]}}
                elif path == '/host/cancel':
                    state['cancelled'] = True
                    result = {'status': 200}
                elif path == '/host/setdownstream':
                    result = {'status': 200}
                else:
                    raise AssertionError('Unexpected path ' + path)
            output = json.dumps(result).encode()
            self.send_response(200)
            self.send_header('Content-Type', 'application/json')
            self.end_headers()
            self.wfile.write(output)

        def log_message(self, *args):
            pass

    supplier = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Supplier)
    threading.Thread(target=supplier.serve_forever, daemon=True).start()
    server = None
    redis = None
    try:
        if args.redis_server:
            redis_port = free_port()
            redis = subprocess.Popen([args.redis_server, '--bind', '127.0.0.1', '--port', str(redis_port),
                                      '--save', '', '--appendonly', 'no', '--dir', tmp], stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
            for _ in range(50):
                try:
                    with socket.create_connection(('127.0.0.1', redis_port), timeout=.2):
                        break
                except OSError:
                    if redis.poll() is not None:
                        raise RuntimeError('Temporary Redis did not start')
                    time.sleep(.1)
            else:
                raise RuntimeError('Temporary Redis was not ready')
            env.update(CACHE_STORE='redis', REDIS_CLIENT='phpredis', REDIS_URL='', REDIS_HOST='127.0.0.1',
                       REDIS_PORT=str(redis_port), REDIS_PASSWORD='', REDIS_USERNAME='', REDIS_DB='0', REDIS_CACHE_DB='1')
        setup = root / 'setup.php'
        setup.write_text("""<?php
require getenv('LOCAL_CLASSIC_TEST_REPO').'/vendor/autoload.php';
$app=require getenv('LOCAL_CLASSIC_TEST_REPO').'/bootstrap/app.php';
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$url='http://127.0.0.1:""" + str(supplier.server_port) + """';
$app->make(App\\Actions\\ConfigureEpayAction::class)->execute($url,'test-merchant','epay-test-secret','Isolated acceptance');
$account=$app->make(App\\Actions\\ConfigureClassicUpstreamAction::class)->execute('Test supplier',$url,'supplier@example.test','supplier-api-secret','Isolated acceptance');
$product=$app->make(App\\Actions\\ImportClassicProductAction::class)->execute($account->id,'17',App\\Enums\\BillingCycle::MONTHLY,'CNY','20.00','Isolated acceptance');
$app->make(App\\Actions\\SyncClassicCatalogAction::class)->execute($product,'Isolated full configuration');
$admin=App\\Models\\User::factory()->create(['email'=>'operator@example.test','password'=>'AcceptancePassword123!']);
foreach(['upstream-operator','upstream-lifecycle','upstream-migrator'] as $role){$app->make(App\\Actions\\AssignUpstreamRoleAction::class)->execute($admin,$role,'Isolated operator');}

""")
        subprocess.run(['php', str(setup)], cwd=repo, env=env, check=True, capture_output=True)
        with open(root / 'http.log', 'w+') as logs:
            server = subprocess.Popen(['php', '-d', 'variables_order=EGPCS', '-S', f'127.0.0.1:{web_port}',
                                       '-t', str(public), str(router)],
                                      cwd=public, env=env, stdout=logs, stderr=logs, start_new_session=True)
            for _ in range(50):
                try:
                    urllib.request.urlopen(base + '/up', timeout=1).read()
                    break
                except (OSError, urllib.error.URLError):
                    time.sleep(.1)
            else:
                raise RuntimeError('Temporary HTTP server did not start')

            def client():
                return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

            def request(opener, path, body=None, accept='text/html'):
                data = None if body is None else urllib.parse.urlencode(body).encode()
                with opener.open(urllib.request.Request(base + path, data=data, headers={'Accept': accept}), timeout=20) as response:
                    return response.read().decode(), response.status, response.headers

            def token(html):
                return re.search(r'name="_token" value="([^"]+)"', html).group(1)

            buyer = client()
            login, _, _ = request(buyer, '/login')
            console, _, _ = request(buyer, '/register', {'_token': token(login), 'name': 'Cloud Buyer', 'email': 'cloud@example.test',
                                  'password': 'AccountPassword123!', 'password_confirmation': 'AccountPassword123!'})
            csrf = token(console)
            checkout, status, _ = request(buyer, '/checkout', {'_token': csrf, 'product_id': 1, 'billing_cycle': 'monthly',
                                         'gateway': 'epay_wechat', 'hostname': 'buyer-host', 'password': 'StrongPassword12!', 'options[configoption][50]': '501', 'options[customfield][80]': 'PRIVATE'}, 'application/json')
            assert status == 201 and json.loads(checkout)['total_amount'] == '9.60'
            invoice = json.loads(checkout)['invoice_id']
            request(buyer, f'/console/invoices/{invoice}/pay', {'_token': csrf}, 'application/json')
            payload = {'pid': 'test-merchant', 'type': 'wxpay', 'out_trade_no': state['gateway_order'], 'trade_no': 'CLASSIC-ACCEPTANCE',
                       'money': '9.60', 'trade_status': 'TRADE_SUCCESS'}
            payload['sign'] = hashlib.md5(('&'.join(k + '=' + payload[k] for k in sorted(payload)) + 'epay-test-secret').encode()).hexdigest().upper()
            for _ in range(2):
                assert request(buyer, '/api/payments/epay_wechat/notify', payload)[0] == 'success'
            cli('queue:work', 'database', '--stop-when-empty', '--tries=3', '--timeout=180')
            with sqlite3.connect(db) as connection:
                instance, status, host, credentials, downstream_token = connection.execute('select id,status,upstream_instance_id,encrypted_credentials,downstream_token from instances').fetchone()
                assert status == 'Active' and host == '999' and 'DELIVERED-HOST-PASSWORD' not in credentials
                assert connection.execute('select count(*) from payment_callbacks').fetchone()[0] == 1
                assert connection.execute('select status from fulfillment_tasks').fetchone()[0] == 'success'
                assert connection.execute('select count(*) from jobs').fetchone()[0] == 0
                operations = str(connection.execute('select request_payload,response_body from upstream_operations').fetchall())
                for secret in ['supplier-api-secret', 'supplier-private-jwt', 'StrongPassword12!', 'DELIVERED-HOST-PASSWORD']:
                    assert secret not in operations
            detail, _, headers = request(buyer, f'/console/instances/{instance}')
            assert '192.0.2.17' in detail and 'DELIVERED-HOST-PASSWORD' in detail and 'no-store' in headers['Cache-Control']
            sync = {'id': str(instance), 'token': downstream_token, 'rand_str': 'ABC123'}
            signature = hashlib.md5(json.dumps(dict(sorted(sync.items())), separators=(',', ':')).encode()).hexdigest().upper()
            incoming = {'id': str(instance), 'rand_str': 'ABC123', 'signature': signature, 'domainstatus': 'Deleted'}
            assert request(buyer, '/api/host/sync', incoming)[1] == 200
            try:
                request(buyer, '/api/host/sync', incoming)
            except urllib.error.HTTPError as error:
                assert error.code == 400
            else:
                raise AssertionError('Callback replay was accepted')
            if args.redis_server:
                replay_check = root / 'replay.php'
                replay_check.write_text("<?php require getenv('LOCAL_CLASSIC_TEST_REPO').'/vendor/autoload.php'; $app=require getenv('LOCAL_CLASSIC_TEST_REPO').'/bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); $store=Illuminate\\Support\\Facades\\Cache::getStore(); echo $store->connection()->ttl($store->getPrefix().'host_sync:" + str(instance) + ":ABC123');")
                ttl = subprocess.run(['php', str(replay_check)], cwd=repo, env=env, check=True, capture_output=True, text=True)
                assert 0 < int(ttl.stdout) <= 600
            cli('queue:work', 'database', '--stop-when-empty', '--tries=3', '--timeout=180')
            with sqlite3.connect(db) as connection:
                assert connection.execute('select status from instances').fetchone()[0] == 'Active'
            assert sum(path == '/cart/settle' for _, path, _ in requests) == 1
            assert sum(path == '/apply_credit' for _, path, _ in requests) == 1
            configured, _, _ = request(buyer, '/console/products/1/configure')
            assert 'CPU' in configured and 'License' in configured
            def settle_operation(invoice_id, trade):
                request(buyer, f'/console/invoices/{invoice_id}/pay', {'_token': csrf}, 'application/json')
                payment = {'pid': 'test-merchant', 'type': 'wxpay', 'out_trade_no': state['gateway_order'],
                           'trade_no': trade, 'money': state['gateway_amount'], 'trade_status': 'TRADE_SUCCESS'}
                payment['sign'] = hashlib.md5(('&'.join(k + '=' + payment[k] for k in sorted(payment)) + 'epay-test-secret').encode()).hexdigest()
                for _ in range(2):
                    assert request(buyer, '/api/payments/epay_wechat/notify', payment)[0] == 'success'
                cli('queue:work', 'database', '--stop-when-empty', '--tries=3', '--timeout=180')
            for action, trade in [('renew', 'RENEW-ACCEPTANCE'), ('upgrade', 'UPGRADE-ACCEPTANCE')]:
                detail, _, _ = request(buyer, f'/console/instances/{instance}')
                request_id = re.search(r'name="request_id" value="([^"]+)"', detail).group(1)
                fields = {'_token': token(detail), 'request_id': request_id, 'action': action, 'gateway': 'epay_wechat',
                          'reason': 'Isolated lifecycle acceptance', 'confirm': '1', 'options[configoption][50]': '502'}
                page, _, _ = request(buyer, f'/console/instances/{instance}/operations', fields)
                assert '待支付' in page or 'Unpaid' in page
                with sqlite3.connect(db) as connection:
                    bill = connection.execute('select max(id) from invoices').fetchone()[0]
                settle_operation(bill, trade)
                request(buyer, f'/console/instances/{instance}/operations', fields)
            with sqlite3.connect(db) as connection:
                assert connection.execute('select count(*) from instances').fetchone()[0] == 1
                assert connection.execute('select count(*) from invoices').fetchone()[0] == 3
                assert connection.execute('select count(*) from fulfillment_tasks where status="success"').fetchone()[0] == 3
                config = json.loads(connection.execute('select active_config from instances').fetchone()[0])
                assert config['upstream_configoption']['50'] == 502
                assert str(connection.execute('select recurring_amount from instances').fetchone()[0]) in ['12', '12.0', '12.00']
            assert sum(path == '/host/renew' for _, path, _ in requests) == 1
            assert sum(path == '/upgrade/checkout_config_upgrade' for _, path, _ in requests) == 1
            assert sum(path == '/apply_credit' for _, path, _ in requests) == 3
            print('PASS: full configuration purchase + renewal + upgrade through real HTTP/CSRF, gateway callbacks and independent queue workers.', flush=True)
            print('PASS: duplicate operations/callbacks reuse invoices; exact upstream cost/currency checks; no duplicate upstream debit.', flush=True)
            print('ISOLATION: temporary SQLite/storage/key and loopback suppliers; no business database or real funds.', flush=True)
            if args.preview:
                print('PREVIEW_URL=' + base, flush=True)
                print('Preview buyer: cloud@example.test / AccountPassword123!; operator: operator@example.test / AcceptancePassword123!', flush=True)
                input('Press Enter after browser review to stop only the isolated server.\n')
    finally:
        if server is not None:
            os.killpg(server.pid, signal.SIGTERM)
            try:
                server.wait(timeout=5)
            except subprocess.TimeoutExpired:
                os.killpg(server.pid, signal.SIGKILL)
                server.wait()
        supplier.shutdown()
        supplier.server_close()
        if redis is not None:
            redis.terminate()
            try:
                redis.wait(timeout=5)
            except subprocess.TimeoutExpired:
                redis.kill()
                redis.wait()
