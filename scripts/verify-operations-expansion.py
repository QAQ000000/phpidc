"""Isolated HTTP/CSRF, queue, SMTP and operations acceptance; --preview keeps a disposable site open."""
import argparse
import base64
import email
import email.policy
import http.cookiejar
import json
import os
import pathlib
import re
import signal
import socket
import socketserver
import sqlite3
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--preview', action='store_true')
args = parser.parse_args()
repo = pathlib.Path(__file__).resolve().parents[1]
messages = []


class SmtpCapture(socketserver.StreamRequestHandler):
    def handle(self):
        self.wfile.write(b'220 loopback-test SMTP\r\n')
        while line := self.rfile.readline():
            command = line.split(b' ', 1)[0].strip().upper()
            if command in (b'EHLO', b'HELO'):
                self.wfile.write(b'250 loopback-test\r\n')
            elif command == b'DATA':
                self.wfile.write(b'354 End with dot\r\n')
                payload = []
                while (part := self.rfile.readline()) not in (b'.\r\n', b''):
                    payload.append(part[1:] if part.startswith(b'..') else part)
                parsed = email.message_from_bytes(b''.join(payload), policy=email.policy.default)
                messages.append({'subject': str(parsed['Subject']), 'body': parsed.get_content()})
                self.wfile.write(b'250 Captured in test memory\r\n')
            elif command == b'QUIT':
                self.wfile.write(b'221 Bye\r\n')
                return
            else:
                self.wfile.write(b'250 OK\r\n')


with tempfile.TemporaryDirectory(prefix='laravel13idc-operations-') as tmp:
    root = pathlib.Path(tmp)
    db = root / 'operations.sqlite'
    db.touch()
    for folder in ['public', 'framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private', 'app/downloads']:
        (root / folder).mkdir(parents=True)
    (root / 'installed.lock').write_text('isolated operations acceptance')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    password = 'Ops-Isolated-' + os.urandom(10).hex() + '!'
    smtp = socketserver.ThreadingTCPServer(('127.0.0.1', 0), SmtpCapture)
    smtp.daemon_threads = True
    threading.Thread(target=smtp.serve_forever, daemon=True).start()
    env = dict(os.environ, OPS_TEST_REPO=str(repo), OPS_TEST_PUBLIC=str(root / 'public'), OPS_TEST_PASSWORD=password,
               OPS_TEST_SMTP_PORT=str(smtp.server_address[1]), APP_ENV='local', APP_DEBUG='false', APP_URL=base,
               APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(), APP_CONFIG_CACHE=str(root / 'config.php'),
               APP_ROUTES_CACHE=str(root / 'routes.php'), DB_CONNECTION='sqlite', DB_URL='', DB_DATABASE=str(db), DB_QUEUE_CONNECTION='sqlite',
               LARAVEL_STORAGE_PATH=tmp, VIEW_COMPILED_PATH=str(root / 'framework/views'), CACHE_STORE='file',
               SESSION_DRIVER='file', SESSION_SECURE_COOKIE='false', QUEUE_CONNECTION='database', LOG_CHANNEL='single')
    bootstrap = "require getenv('OPS_TEST_REPO').'/vendor/autoload.php';\n$app=require getenv('OPS_TEST_REPO').'/bootstrap/app.php';\n$app->usePublicPath(getenv('OPS_TEST_PUBLIC'));\n"
    console = root / 'console.php'
    console.write_text('<?php\n' + bootstrap + 'exit($app->handleCommand(new Symfony\\Component\\Console\\Input\\ArgvInput));\n')
    (root / 'public/index.php').write_text('<?php\n' + bootstrap + '$app->handleRequest(Illuminate\\Http\\Request::capture());\n')
    router = root / 'router.php'
    router.write_text("<?php\n$path=parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);\nif ($path !== '/' && is_file(getenv('OPS_TEST_PUBLIC').$path)) { return false; }\nrequire getenv('OPS_TEST_PUBLIC').'/index.php';\n")

    def cli(*arguments):
        result = subprocess.run(['php', str(console), *arguments], cwd=repo, env=env, capture_output=True, text=True, timeout=45)
        assert result.returncode == 0, result.stdout + result.stderr
        return result.stdout

    cli('migrate', '--force')
    cli('filament:assets')
    fixture = root / 'fixture.php'
    fixture.write_text('<?php\n' + bootstrap + r'''
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (($argv[1] ?? '') === 'mature') {
    App\Models\AffiliateCommission::where('status','pending')->update(['available_at'=>now()->subMinute()]); exit;
}
$operator=App\Models\User::factory()->create(['name'=>'运营验收','email'=>'operator@example.test','password'=>getenv('OPS_TEST_PASSWORD')]);
$buyer=App\Models\User::factory()->create(['name'=>'客户验收','email'=>'buyer@example.test','password'=>getenv('OPS_TEST_PASSWORD'),'credit'=>'100.00','referred_by'=>$operator->id]);
$viewer=App\Models\User::factory()->create(['name'=>'查看员验收','email'=>'viewer@example.test','password'=>getenv('OPS_TEST_PASSWORD')]);
foreach (['promotion-operator','affiliate-operator','support-agent','notification-operator','download-operator','finance-payout'] as $role) {
    app(App\Actions\AssignAdminRoleAction::class)->execute($operator,$role,'Isolated HTTP acceptance');
}
foreach (['promotion-viewer','affiliate-viewer','support-viewer','notification-viewer','download-viewer','finance-viewer'] as $role) {
    app(App\Actions\AssignAdminRoleAction::class)->execute($viewer,$role,'Isolated viewer acceptance');
}
$product=app(App\Actions\CreateLocalCardProductAction::class)->execute('运营验收充值卡','10.00','0.00','CNY','Isolated purchase');
app(App\Actions\ImportRedemptionCodesAction::class)->execute($product,['ISOLATED-CARD-DO-NOT-NOTIFY'],'Isolated stock');
app(App\Actions\ConfigureIdentityAction::class)->execute(['mail.transport'=>'smtp','mail.host'=>'127.0.0.1','mail.port'=>(int)getenv('OPS_TEST_SMTP_PORT'),'mail.from_address'=>'accounts@example.test'],'Loopback SMTP only');
echo json_encode(['product'=>$product->id,'operator'=>$operator->id,'buyer'=>$buyer->id,'viewer'=>$viewer->id]);
''')

    def fixture_run(*arguments):
        result = subprocess.run(['php', str(fixture), *arguments], cwd=repo, env=env, capture_output=True, text=True, timeout=30)
        assert result.returncode == 0, result.stdout + result.stderr
        return result.stdout

    ids = json.loads(fixture_run())
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
            raise RuntimeError('Isolated HTTP server failed to start')

        def client():
            return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

        def request(opener, path, body=None, accept='text/html'):
            data = None if body is None else urllib.parse.urlencode(body).encode()
            with opener.open(urllib.request.Request(base + path, data=data, headers={'Accept': accept}), timeout=20) as response:
                return response.read().decode(), response.status, response.headers

        def token(html):
            return re.search(r'name="_token"\s+value="([^"]+)"', html).group(1)

        def login(opener, name):
            html, _, _ = request(opener, '/login')
            request(opener, '/login', {'_token': token(html), 'email': name + '@example.test', 'password': password})

        def post(opener, from_path, path, data):
            html, _, _ = request(opener, from_path)
            return request(opener, path, {'_token': token(html), **data})

        def error(opener, path, expected, data=None):
            try:
                request(opener, path, data)
                raise AssertionError('Expected HTTP error at ' + path)
            except urllib.error.HTTPError as err:
                assert err.code == expected, (path, err.code)

        operator, buyer, viewer, guest = client(), client(), client(), client()
        login(operator, 'operator')
        login(buyer, 'buyer')
        login(viewer, 'viewer')
        post(operator, '/admin/coupons', '/admin/operations/config/coupon', {'code': 'HTTP-SAVE', 'type': 'percent', 'value': '10.00',
             'min_order_amount': '0.00', 'applies_to': 'both', 'product_ids_json': 'null', 'per_user_limit': 1, 'is_active': 1, 'reason': 'HTTP coupon creation'})
        post(operator, '/admin/affiliates', '/admin/operations/config/rule', {'name': 'HTTP global rule', 'commission_rate': '20.00', 'priority': 10,
             'product_ids_json': '[]', 'is_active': 1, 'reason': 'HTTP rule creation'})
        post(operator, '/admin/notifications', '/admin/operations/config/template', {'event_key': 'invoice.paid', 'channel': 'email', 'subject': '已付款 {{invoice_no}}',
             'body_markdown': '验收账单 {{invoice_no}} 已付款 {{amount}} {{currency}}。', 'variable_mapping_json': '{}', 'is_enabled': 1, 'reason': 'HTTP notification template'})
        for page in ['coupons', 'affiliates', 'payouts', 'support', 'notifications', 'downloads']:
            assert request(viewer, '/admin/' + page)[1] == 200
        html, _, _ = request(viewer, '/admin/coupons')
        error(viewer, '/admin/operations/config/coupon', 403, {'_token': token(html)})
        html, _, _ = request(operator, '/admin/coupons')
        error(operator, '/admin/operations/config/coupon', 419, {'code': 'NO-CSRF'})
        post(buyer, '/console/coupons', '/console/coupons', {'code': 'HTTP-SAVE'})
        html, _, _ = request(buyer, '/console')
        invoice_json, status, _ = request(buyer, '/checkout', {'request_id': '01KAD000000000000000000001', '_token': token(html), 'product_id': ids['product'], 'billing_cycle': 'onetime', 'use_credit': 1, 'coupon_code': 'HTTP-SAVE'}, 'application/json')
        invoice = json.loads(invoice_json)
        assert status == 201 and invoice['total_amount'] == '9.00' and invoice['status'] == 'Paid', invoice
        cli('queue:work', '--stop-when-empty', '--tries=1')
        with sqlite3.connect(db) as conn:
            assert conn.execute('select count(*) from affiliate_commissions where amount=1.80 and status="pending"').fetchone()[0] == 1
            assert conn.execute('select count(*) from instances where status="Active"').fetchone()[0] == 1
        fixture_run('mature')
        cli('affiliate:process')
        html, _, _ = request(operator, '/console/affiliate')
        request_id = re.search(r'name="request_id" value="([^"]+)"', html).group(1)
        payout_data = {'_token': token(html), 'request_id': request_id, 'amount': '1.80'}
        request(operator, '/console/affiliate/withdraw', payout_data)
        request(operator, '/console/affiliate/withdraw', payout_data)
        with sqlite3.connect(db) as conn:
            withdrawal_id = conn.execute('select id from affiliate_withdrawals').fetchone()[0]
        approval = {'decision': 'approve', 'reason': 'HTTP finance approval', 'confirm': 1}
        post(operator, '/admin/payouts', '/admin/operations/payouts/' + str(withdrawal_id), approval)
        post(operator, '/admin/payouts', '/admin/operations/payouts/' + str(withdrawal_id), approval)
        post(buyer, '/console/tickets', '/console/tickets', {'subject': '验收工单', 'body': '<script>do-not-render()</script>'})
        with sqlite3.connect(db) as conn:
            ticket_id = conn.execute('select id from tickets').fetchone()[0]
        ticket_path = '/console/tickets/' + str(ticket_id)
        error(viewer, ticket_path, 403)
        post(operator, '/admin/support?edit=' + str(ticket_id), '/admin/operations/tickets/' + str(ticket_id), {'operation': 'reply', 'body': '客服已确认', 'reason': 'HTTP support response'})
        assert '客服已确认' in request(buyer, ticket_path)[0]
        post(operator, '/admin/downloads', '/admin/operations/downloads', {'title': '受限外部客户端', 'category': 'tools', 'version': '1.0', 'storage_type': 'external_url',
             'external_url': 'https://example.test/restricted-package', 'access_level': 'active_customer', 'sort_order': 0, 'is_active': 1, 'reason': 'HTTP restricted download'})
        with sqlite3.connect(db) as conn:
            resource_id = conn.execute('select id from download_resources').fetchone()[0]
        assert 'restricted-package' not in request(guest, '/downloads')[0]
        error(guest, '/downloads/' + str(resource_id), 403)
        error(viewer, '/downloads/' + str(resource_id), 403)
        # Inspect authorization without following a third-party redirect.
        class NoRedirect(urllib.request.HTTPRedirectHandler):
            def redirect_request(self, req, fp, code, msg, headers, newurl):
                return None
        download_client = urllib.request.build_opener(NoRedirect(), *[handler for handler in buyer.handlers if isinstance(handler, urllib.request.HTTPCookieProcessor)])
        try:
            request(download_client, '/downloads/' + str(resource_id))
            raise AssertionError('Expected HTTPS external redirect')
        except urllib.error.HTTPError as err:
            assert err.code == 302 and err.headers['Location'] == 'https://example.test/restricted-package'
        cli('queue:work', '--stop-when-empty', '--tries=1')
        with sqlite3.connect(db) as conn:
            assert conn.execute('select count(*) from failed_jobs').fetchone()[0] == 0
            assert conn.execute('select count(*) from affiliate_withdrawals').fetchone()[0] == 1
            assert conn.execute('select CAST(credit AS TEXT),CAST(affiliate_balance AS TEXT) from users where id=?', [ids['operator']]).fetchone() == ('1.8', '0')
            assert conn.execute('select count(*) from financial_transactions where reference_key=?', ['payout:' + str(withdrawal_id)]).fetchone()[0] == 1
            assert conn.execute('select count(*) from audit_logs where action="affiliate.withdrawal_approved"').fetchone()[0] == 1
            assert conn.execute('select count(*) from notification_logs where status="sent"').fetchone()[0] >= 5
        assert any('9.00' in message['body'] for message in messages), messages
        assert all('ISOLATED-CARD-DO-NOT-NOTIFY' not in message['body'] for message in messages)
        print('PASS: real HTTP/CSRF/RBAC, discounted checkout/delivery, queued commission/SMTP, maturation, idempotent wallet payout, tickets and authorized downloads.', flush=True)
        if args.preview:
            print(json.dumps({'url': base, 'operator': 'operator@example.test', 'buyer': 'buyer@example.test', 'viewer': 'viewer@example.test', 'password': password, 'temporary_root': tmp}), flush=True)
            input('Press Enter to stop and remove the disposable site: ')
    finally:
        os.killpg(server.pid, signal.SIGTERM)
        server.wait(timeout=10)
        logs.close()
        smtp.shutdown()
        smtp.server_close()
