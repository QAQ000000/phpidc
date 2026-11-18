"""Isolated inventory acceptance: real HTTP, CSRF, RBAC and a separate DB queue worker."""
import argparse
import base64
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
parser.add_argument('--preview', action='store_true', help='Keep temporary server for browser review until Enter')
args = parser.parse_args()
repo = pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='laravel13idc-inventory-') as tmp:
    root = pathlib.Path(tmp)
    db = root / 'inventory.sqlite'
    db.touch()
    for folder in ['public', 'framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private']:
        (root / folder).mkdir(parents=True)
    (root / 'installed.lock').write_text('isolated inventory acceptance')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    password = 'Isolated-' + os.urandom(12).hex() + '!'
    env = dict(os.environ, INVENTORY_TEST_REPO=str(repo), INVENTORY_TEST_PUBLIC=str(root / 'public'), INVENTORY_TEST_PASSWORD=password,
               APP_ENV='local', APP_DEBUG='false', APP_URL=base, APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(),
               APP_CONFIG_CACHE=str(root / 'config.php'), APP_ROUTES_CACHE=str(root / 'routes.php'),
               DB_CONNECTION='sqlite', DB_URL='', DB_DATABASE=str(db), DB_QUEUE_CONNECTION='sqlite', DB_QUEUE_TABLE='jobs',
               LARAVEL_STORAGE_PATH=tmp, VIEW_COMPILED_PATH=str(root / 'framework/views'), CACHE_STORE='file',
               SESSION_DRIVER='file', SESSION_SECURE_COOKIE='false', QUEUE_CONNECTION='database', LOG_CHANNEL='single')
    bootstrap = "require getenv('INVENTORY_TEST_REPO').'/vendor/autoload.php';\n$app=require getenv('INVENTORY_TEST_REPO').'/bootstrap/app.php';\n$app->usePublicPath(getenv('INVENTORY_TEST_PUBLIC'));\n"
    console = root / 'console.php'
    console.write_text('<?php\n' + bootstrap + 'exit($app->handleCommand(new Symfony\\Component\\Console\\Input\\ArgvInput));\n')
    (root / 'public/index.php').write_text('<?php\n' + bootstrap + '$app->handleRequest(Illuminate\\Http\\Request::capture());\n')
    router = root / 'router.php'
    router.write_text("<?php\n$path=parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);\nif ($path !== '/' && is_file(getenv('INVENTORY_TEST_PUBLIC').$path)) { return false; }\nrequire getenv('INVENTORY_TEST_PUBLIC').'/index.php';\n")

    def cli(*arguments):
        result = subprocess.run(['php', str(console), *arguments], cwd=repo, env=env, capture_output=True, text=True, timeout=45)
        if result.returncode:
            raise RuntimeError(result.stdout + result.stderr)
        return result.stdout

    cli('migrate', '--force')
    cli('filament:assets')
    cli('cards:create-product', 'Sub2API 充值卡', '10.00', '--driver=sub2api', '--reason=Isolated acceptance')
    cli('cards:create-product', '数字服务激活码', '24.00', '--reason=Isolated catalog')
    fixture = root / 'fixture.php'
    fixture.write_text('<?php\n' + bootstrap + r'''
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Models\User::factory()->create(['name'=>'Inventory Operator','email'=>'operator@example.test','password'=>getenv('INVENTORY_TEST_PASSWORD')]);
App\Models\User::factory()->create(['name'=>'Acceptance Buyer','email'=>'buyer@example.test','password'=>getenv('INVENTORY_TEST_PASSWORD'),'credit'=>'20.00']);
App\Models\User::factory()->create(['name'=>'Inventory Viewer','email'=>'viewer@example.test','password'=>getenv('INVENTORY_TEST_PASSWORD')]);
''')
    subprocess.run(['php', str(fixture)], cwd=repo, env=env, capture_output=True, check=True)
    cli('inventory:role', '1', 'inventory-operator', '--reason=Isolated operator')
    cli('inventory:role', '3', 'inventory-viewer', '--reason=Isolated viewer')
    logs = open(root / 'http.log', 'w+')
    server = subprocess.Popen(['php', '-d', 'variables_order=EGPCS', '-S', f'127.0.0.1:{port}', '-t', str(root / 'public'), str(router)],
                              cwd=root / 'public', env=env, stdout=logs, stderr=logs, start_new_session=True)
    try:
        for _ in range(50):
            try:
                urllib.request.urlopen(base + '/up', timeout=1).read()
                break
            except (OSError, urllib.error.URLError):
                time.sleep(.1)
        else:
            raise RuntimeError('Isolated server did not start')

        def client():
            return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

        def request(opener, path, body=None, accept='text/html'):
            data = None if body is None else urllib.parse.urlencode(body).encode()
            with opener.open(urllib.request.Request(base + path, data=data, headers={'Accept': accept}), timeout=20) as response:
                return response.read().decode(), response.status, response.headers

        def csrf(html):
            return re.search(r'name="_token" value="([^"]+)"', html).group(1)

        def login(email):
            opener = client()
            form, _, _ = request(opener, '/login')
            request(opener, '/login', {'_token': csrf(form), 'email': email, 'password': password})
            return opener

        buyer = login('buyer@example.test')
        operator = login('operator@example.test')
        viewer = login('viewer@example.test')
        html, _, _ = request(buyer, '/console')
        checkout, status, _ = request(buyer, '/checkout', {'request_id': '01KAD000000000000000000001', '_token': csrf(html), 'product_id': 1, 'billing_cycle': 'onetime', 'use_credit': 1}, 'application/json')
        assert status == 201
        invoice = json.loads(checkout)['invoice_id']
        with sqlite3.connect(db) as connection:
            task, instance = connection.execute('select id,instance_id from fulfillment_tasks where invoice_id=?', (invoice,)).fetchone()
            connection.execute('update fulfillment_tasks set max_attempts=1 where id=?', (task,))
        cli('queue:work', 'database', '--stop-when-empty', '--tries=3', '--timeout=180')
        with sqlite3.connect(db) as connection:
            assert connection.execute('select status from fulfillment_tasks where id=?', (task,)).fetchone()[0] == 'failed'
            assert connection.execute('select status from invoices where id=?', (invoice,)).fetchone()[0] == 'Paid'
        queue, _, headers = request(operator, '/admin/fulfillment')
        assert '缺货候选' in queue and '确认重试' in queue and 'no-store' in headers['Cache-Control']
        cards, _, _ = request(operator, '/admin/inventory/cards?product_id=1')
        try:
            request(operator, '/admin/inventory/import', {'product_id': 1, 'codes': 'REJECT-CSRF', 'reason': 'Missing token'})
        except urllib.error.HTTPError as error:
            assert error.code == 419
        else:
            raise AssertionError('CSRF protection did not reject forged request')
        imported, _, _ = request(operator, '/admin/inventory/import', {'_token': csrf(cards), 'product_id': 1, 'codes': 'INVENTORY-ONLY-OWNER\nINVENTORY-ONLY-OWNER', 'reason': 'Stock arrived'})
        assert '新增 1 张，重复 1 张。' in imported and 'INVENTORY-ONLY-OWNER' not in imported
        assert '<textarea name="codes" required' in imported
        with sqlite3.connect(db) as connection:
            assert connection.execute('select status from fulfillment_tasks where id=?', (task,)).fetchone()[0] == 'failed', 'Import revived terminal task'
            assert connection.execute('select count(*) from jobs').fetchone()[0] == 0
        retried, _, _ = request(operator, f'/admin/fulfillment/{task}/retry', {'_token': csrf(queue), 'confirm': 1, 'reason': 'Stock confirmed; retry approved'})
        assert '等待队列处理' in retried
        try:
            request(operator, f'/admin/fulfillment/{task}/retry', {'_token': csrf(queue), 'confirm': 1, 'reason': 'Double click'}, 'application/json')
        except urllib.error.HTTPError as error:
            assert error.code == 422
        else:
            raise AssertionError('Duplicate retry was accepted')
        with sqlite3.connect(db) as connection:
            assert connection.execute('select count(*) from jobs').fetchone()[0] == 1
        cli('queue:work', 'database', '--stop-when-empty', '--tries=3', '--timeout=180')
        delivered, _, headers = request(buyer, f'/console/instances/{instance}')
        assert 'INVENTORY-ONLY-OWNER' in delivered and 'no-store' in headers['Cache-Control']
        with sqlite3.connect(db) as connection:
            assert connection.execute('select credit from users where id=2').fetchone()[0] == 10
            assert connection.execute('select count(*) from fulfillment_tasks').fetchone()[0] == 1
            assert connection.execute("select count(*) from redemption_codes where status='sold'").fetchone()[0] == 1
            assert connection.execute('select count(*) from financial_transactions where reference_key=?', (f'settle:{invoice}',)).fetchone()[0] == 1
            assert connection.execute("select actor_id from audit_logs where action='fulfillment.retry'").fetchone()[0] == 1
            assert 'INVENTORY-ONLY-OWNER' not in connection.execute('select code from redemption_codes').fetchone()[0]
        for path in ['/admin/inventory', '/admin/inventory/cards', '/admin/fulfillment']:
            html, _, _ = request(viewer, path)
            assert 'INVENTORY-ONLY-OWNER' not in html and '加密导入' not in html
            try:
                request(buyer, path)
            except urllib.error.HTTPError as error:
                assert error.code == 403
            else:
                raise AssertionError('Customer entered inventory panel')
        try:
            request(viewer, '/admin/inventory/import', {'_token': csrf(html), 'product_id': 1, 'codes': 'FORGED', 'reason': 'Viewer write'})
        except urllib.error.HTTPError as error:
            assert error.code == 403
        else:
            raise AssertionError('Viewer imported cards')
        cli('inventory:role', '3', 'inventory-viewer', '--revoke', '--reason=Acceptance revoke')
        try:
            request(viewer, '/admin/inventory')
        except urllib.error.HTTPError as error:
            assert error.code == 403
        else:
            raise AssertionError('Revoked viewer retained access')
        print('PASS: isolated migrations; real session/CSRF; customer/viewer/operator permission boundaries and revoke.')
        print('PASS: Paid shortage remains failed after import; explicit retry queues once; separate worker delivers one encrypted card without a second debit.')
        if args.preview:
            # Keep another genuine paid shortage visible for manual browser review.
            html, _, _ = request(buyer, '/console')
            request(buyer, '/checkout', {'request_id': '01KAD000000000000000000002', '_token': csrf(html), 'product_id': 1, 'billing_cycle': 'onetime', 'use_credit': 1}, 'application/json')
            with sqlite3.connect(db) as connection:
                connection.execute("update fulfillment_tasks set max_attempts=1 where status='pending'")
            cli('queue:work', 'database', '--stop-when-empty', '--tries=3', '--timeout=180')
            print('PREVIEW_URL=' + base, flush=True)
            print('PREVIEW_OPERATOR=operator@example.test', flush=True)
            print('PREVIEW_PASSWORD=' + password, flush=True)
            input('Temporary preview ready; press Enter to close. ')
    finally:
        os.killpg(server.pid, signal.SIGTERM)
        try:
            server.wait(timeout=5)
        except subprocess.TimeoutExpired:
            os.killpg(server.pid, signal.SIGKILL)
            server.wait()
        logs.close()
