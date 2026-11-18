"""Isolated admin acceptance: real sessions/CSRF, web roles, stock retry, refund and audit."""
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
parser.add_argument('--preview', action='store_true', help='Keep temporary site for browser review until Enter')
args = parser.parse_args()
repo = pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='laravel13idc-admin-') as tmp:
    root = pathlib.Path(tmp)
    db = root / 'admin.sqlite'
    db.touch()
    for folder in ['public', 'framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private']:
        (root / folder).mkdir(parents=True)
    (root / 'installed.lock').write_text('isolated admin acceptance')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    password = 'Isolated-' + os.urandom(12).hex() + '!'
    env = dict(os.environ, ADMIN_TEST_REPO=str(repo), ADMIN_TEST_PUBLIC=str(root / 'public'), ADMIN_TEST_PASSWORD=password,
               APP_ENV='local', APP_DEBUG='false', APP_URL=base, APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(),
               APP_CONFIG_CACHE=str(root / 'config.php'), APP_ROUTES_CACHE=str(root / 'routes.php'),
               DB_CONNECTION='sqlite', DB_URL='', DB_DATABASE=str(db), DB_QUEUE_CONNECTION='sqlite', DB_QUEUE_TABLE='jobs',
               LARAVEL_STORAGE_PATH=tmp, VIEW_COMPILED_PATH=str(root / 'framework/views'), CACHE_STORE='file',
               SESSION_DRIVER='file', SESSION_SECURE_COOKIE='false', QUEUE_CONNECTION='database', LOG_CHANNEL='single')
    bootstrap = "require getenv('ADMIN_TEST_REPO').'/vendor/autoload.php';\n$app=require getenv('ADMIN_TEST_REPO').'/bootstrap/app.php';\n$app->usePublicPath(getenv('ADMIN_TEST_PUBLIC'));\n"
    console = root / 'console.php'
    console.write_text('<?php\n' + bootstrap + 'exit($app->handleCommand(new Symfony\\Component\\Console\\Input\\ArgvInput));\n')
    (root / 'public/index.php').write_text('<?php\n' + bootstrap + '$app->handleRequest(Illuminate\\Http\\Request::capture());\n')
    router = root / 'router.php'
    router.write_text("<?php\n$path=parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);\nif ($path !== '/' && is_file(getenv('ADMIN_TEST_PUBLIC').$path)) { return false; }\nrequire getenv('ADMIN_TEST_PUBLIC').'/index.php';\n")

    def cli(*arguments):
        result = subprocess.run(['php', str(console), *arguments], cwd=repo, env=env, capture_output=True, text=True, timeout=45)
        if result.returncode:
            raise RuntimeError(result.stdout + result.stderr)
        return result.stdout

    cli('migrate', '--force')
    cli('filament:assets')
    cli('cards:create-product', '后台验收充值卡', '10.00', '--reason=Isolated admin acceptance')
    fixture = root / 'fixture.php'
    fixture.write_text('<?php\n' + bootstrap + r'''
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
foreach (['manager', 'buyer', 'viewer', 'auditor', 'operator'] as $name) {
    App\Models\User::factory()->create(['name'=>ucfirst($name),'email'=>$name.'@example.test',
        'password'=>getenv('ADMIN_TEST_PASSWORD'),'credit'=>$name === 'buyer' ? '20.00' : '0.00']);
}
App\Models\PaymentCallback::factory()->create(['invoice_id'=>null,'invoice_no'=>'UNKNOWN-EXACT-42','gateway'=>'epay_wechat',
    'outcome'=>'unknown_invoice','raw_payload'=>['sign'=>'DO-NOT-EXPOSE-PAYMENT-SIGN']]);
Illuminate\Support\Facades\DB::table('failed_jobs')->insert(['uuid'=>'admin-queue-42','connection'=>'database','queue'=>'default',
    'payload'=>'DO-NOT-EXPOSE-JOB-PAYLOAD','exception'=>'DO-NOT-EXPOSE-JOB-EXCEPTION','failed_at'=>now()]);
''')
    subprocess.run(['php', str(fixture)], cwd=repo, env=env, capture_output=True, check=True)
    for user_id, role in [(1, 'access-manager'), (3, 'finance-viewer'), (3, 'task-viewer'), (4, 'audit-viewer'),
                          (5, 'inventory-operator'), (5, 'task-viewer')]:
        cli('admin:role', str(user_id), role, '--reason=Isolated admin role')
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

        def request(opener, path, body=None, accept='text/html'):
            data = None if body is None else urllib.parse.urlencode(body).encode()
            with opener.open(urllib.request.Request(base + path, data=data, headers={'Accept': accept}), timeout=20) as response:
                return response.read().decode(), response.status, response.headers

        def rejected(opener, path, body, code):
            try:
                request(opener, path, body, 'application/json')
            except urllib.error.HTTPError as error:
                assert error.code == code, f'{path}: expected {code}, got {error.code}'
            else:
                raise AssertionError(f'{path}: request was unexpectedly accepted')

        def csrf(html):
            return re.search(r'name="_token" value="([^"]+)"', html).group(1)

        def login(name):
            opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
            html, _, _ = request(opener, '/login')
            request(opener, '/login', {'_token': csrf(html), 'email': name + '@example.test', 'password': password})
            return opener

        manager, buyer, viewer, auditor, operator = [login(name) for name in ['manager', 'buyer', 'viewer', 'auditor', 'operator']]
        access, _, headers = request(manager, '/admin/access?user_id=5')
        assert 'no-store' in headers['Cache-Control']
        grant = {'_token': csrf(access), 'role': 'finance-operator', 'operation': 'grant', 'reason': 'Web finance grant', 'confirm': 1}
        rejected(manager, '/admin/access/5/role', {key: value for key, value in grant.items() if key != '_token'}, 419)
        rejected(viewer, '/admin/access/5/role', dict(grant, _token=csrf(request(viewer, '/admin/finance')[0])), 403)
        rejected(manager, '/admin/access/5/role', dict(grant, role='access-manager'), 422)
        request(manager, '/admin/access/5/role', grant)
        request(operator, '/admin/finance')
        for path in ['/admin/finance', '/admin/tasks', '/admin/access', '/admin/audit']:
            rejected(buyer, path, None, 403)

        html, _, _ = request(buyer, '/console')
        created, status, _ = request(buyer, '/checkout', {'request_id': '01KAD000000000000000000001', '_token': csrf(html), 'product_id': 1, 'billing_cycle': 'onetime', 'use_credit': 1}, 'application/json')
        assert status == 201
        invoice = json.loads(created)['invoice_id']
        with sqlite3.connect(db) as connection:
            task, instance = connection.execute('select id,instance_id from fulfillment_tasks where invoice_id=?', (invoice,)).fetchone()
            connection.execute('update fulfillment_tasks set max_attempts=1 where id=?', (task,))
        cli('queue:work', 'database', '--stop-when-empty', '--tries=3', '--timeout=180')
        with sqlite3.connect(db) as connection:
            assert connection.execute('select status from fulfillment_tasks where id=?', (task,)).fetchone()[0] == 'failed'
        cards, _, _ = request(operator, '/admin/inventory/cards')
        request(operator, '/admin/inventory/import', {'_token': csrf(cards), 'product_id': 1, 'codes': 'ADMIN-ONE-DELIVERED-CARD', 'reason': 'Confirmed stock'})
        with sqlite3.connect(db) as connection:
            assert connection.execute('select status from fulfillment_tasks where id=?', (task,)).fetchone()[0] == 'failed'
        tasks, _, _ = request(operator, '/admin/tasks')
        assert '重试原任务' in tasks and 'DO-NOT-EXPOSE-JOB' not in tasks
        retry = {'_token': csrf(tasks), 'reason': 'Confirmed stock and paid order', 'confirm': 1}
        rejected(viewer, f'/admin/tasks/{task}/retry', dict(retry, _token=csrf(request(viewer, '/admin/tasks')[0])), 403)
        request(operator, f'/admin/tasks/{task}/retry', retry)
        rejected(operator, f'/admin/tasks/{task}/retry', retry, 422)
        with sqlite3.connect(db) as connection:
            assert connection.execute('select count(*) from jobs').fetchone()[0] == 1
        cli('queue:work', 'database', '--stop-when-empty', '--tries=3', '--timeout=180')
        owner, _, _ = request(buyer, f'/console/instances/{instance}')
        assert 'ADMIN-ONE-DELIVERED-CARD' in owner

        detail, _, _ = request(operator, f'/admin/finance/invoice?invoice_id={invoice}')
        assert 'ADMIN-ONE-DELIVERED-CARD' not in detail
        refund = {'_token': csrf(detail), 'mode': 'refund_wallet', 'reason': 'Approved delivered-card refund', 'confirm': 1, 'amount': '9999.00'}
        rejected(operator, f'/admin/finance/invoices/{invoice}/refund', {key: value for key, value in refund.items() if key != '_token'}, 419)
        rejected(operator, f'/admin/finance/invoices/{invoice}/refund', refund, 422)
        request(operator, f'/admin/finance/invoices/{invoice}/refund', dict(refund, keep_service=1))
        request(operator, f'/admin/finance/invoices/{invoice}/refund', dict(refund, keep_service=1))
        ledger, _, _ = request(viewer, '/admin/finance/ledger')
        assert f'refund:{invoice}' in ledger and 'UNKNOWN-EXACT-42' in ledger and 'DO-NOT-EXPOSE-PAYMENT-SIGN' not in ledger
        audits, _, _ = request(auditor, '/admin/audit')
        assert 'Web finance grant' in audits and 'Approved delivered-card refund' in audits and 'ADMIN-ONE-DELIVERED-CARD' not in audits
        with sqlite3.connect(db) as connection:
            assert connection.execute('select credit,frozen_credit from users where id=2').fetchone() == (20, 0)
            assert connection.execute('select status from invoices where id=?', (invoice,)).fetchone()[0] == 'Refunded'
            assert connection.execute('select count(*) from financial_transactions where reference_key=?', (f'refund:{invoice}',)).fetchone()[0] == 1
            assert connection.execute("select status from redemption_codes").fetchone()[0] == 'sold'
            assert connection.execute('select status from instances where id=?', (instance,)).fetchone()[0] == 'Cancelled'
            assert connection.execute("select count(*) from audit_logs where action='invoice.refund' and actor_id=5 and ip is not null and request_id is not null").fetchone()[0] == 1
        request(manager, '/admin/access/5/role', dict(grant, operation='revoke', reason='Web finance revoke'))
        rejected(operator, '/admin/finance', None, 403)
        rejected(operator, f'/admin/finance/invoices/{invoice}/refund', refund, 403)
        request(operator, '/admin/inventory')
        print('PASS: isolated migrations/assets; real session/CSRF; fixed-role grant/revoke; customer/viewer/operator boundaries.')
        print('PASS: paid shortage stays manual after import; one retry/job/delivery; full refund once; sold card retained; audit actor/IP/request ID; no real external calls.')
        if args.preview:
            # Restore only the disposable operator's authority for interactive review.
            cli('admin:role', '5', 'finance-operator', '--reason=Disposable browser review')
            cli('admin:role', '5', 'audit-viewer', '--reason=Disposable browser review')
            print('PREVIEW_URL=' + base, flush=True)
            print('PREVIEW_OPERATOR=operator@example.test', flush=True)
            print('PREVIEW_MANAGER=manager@example.test', flush=True)
            print('PREVIEW_PASSWORD=' + password, flush=True)
            print('PREVIEW_INVOICE=' + str(invoice), flush=True)
            input('Temporary preview ready; press Enter to close. ')
    finally:
        os.killpg(server.pid, signal.SIGTERM)
        try:
            server.wait(timeout=5)
        except subprocess.TimeoutExpired:
            os.killpg(server.pid, signal.SIGKILL)
            server.wait()
        logs.close()
