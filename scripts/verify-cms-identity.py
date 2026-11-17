"""Isolated CMS/account acceptance with real HTTP, CSRF, file sessions and loopback SMTP."""
import argparse
import base64
import email
import email.policy
import http.cookiejar
import http.server
import hashlib
import hmac
import json
import os
import pathlib
import re
import signal
import socket
import socketserver
import ssl
import subprocess
import tempfile
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--preview', action='store_true', help='Keep the isolated server open for browser review until Enter')
parser.add_argument('--providers', action='store_true', help='Also exercise source-verified SMS HTTPS and Telegram signed callbacks with isolated providers')
args = parser.parse_args()
repo = pathlib.Path(__file__).resolve().parents[1]
messages = []
sms_messages = []
kyc_requests = []
kyc_result = {'status': 400}


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


class SmsCapture(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        assert self.headers['Content-Type'].startswith('application/x-www-form-urlencoded')
        data = urllib.parse.parse_qs(self.rfile.read(int(self.headers['Content-Length'])).decode())
        if self.path == '/smsapi.php?action=send':
            assert self.headers['api'] == 'isolated-api' and self.headers['key'] == 'isolated-key'
            assert data['to'] == ['13800138000'] and data['template_id'] == ['isolated-approved-template']
            assert data['content'] == ['【隔离验收】验证码' + data['vars[code]'][0] + '，10分钟内有效']
            sms_messages.append(data)
            result = {'status': 200}
        else:
            assert self.path.startswith('/certapi.php?action=')
            assert self.headers['api'] == 'isolated-kyc-api' and self.headers['key'] == 'isolated-kyc-key'
            action = urllib.parse.parse_qs(urllib.parse.urlparse(self.path).query)['action'][0]
            kyc_requests.append(action)
            if action == 'initialize':
                assert data['cert_name'] == ['隔离用户'] and data['cert_no'] == ['11010119900101001X']
                assert data['cert_type'] == ['IDENTITY_CARD'] and data['biz_code'] == ['FACE']
                assert data['outer_order_no'][0].startswith('cms_kyc_')
                result = {'status': 200, 'certify_id': 'ISOLATED-CERTIFICATE'}
            elif action == 'certify':
                assert data == {'certify_id': ['ISOLATED-CERTIFICATE']}
                result = {'status': 200, 'url': 'https://cert.example.test/isolated-authorization'}
            else:
                assert action == 'query' and data == {'certify_id': ['ISOLATED-CERTIFICATE']}
                result = dict(kyc_result)
        body = json.dumps(result).encode()
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args):
        pass


with tempfile.TemporaryDirectory(prefix='laravel13idc-cms-identity-') as tmp:
    root = pathlib.Path(tmp)
    for folder in ['public', 'framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private']:
        (root / folder).mkdir(parents=True)
    (root / 'installed.lock').write_text('isolated CMS/account acceptance')
    database = root / 'test.sqlite'
    database.touch()
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    password = 'Isolated-' + os.urandom(12).hex() + '!'
    smtp = socketserver.ThreadingTCPServer(('127.0.0.1', 0), SmtpCapture)
    smtp.daemon_threads = True
    threading.Thread(target=smtp.serve_forever, daemon=True).start()
    env = dict(os.environ, CMS_TEST_REPO=str(repo), CMS_TEST_PUBLIC=str(root / 'public'), CMS_TEST_PASSWORD=password,
               APP_ENV='local', APP_DEBUG='false', APP_URL=base, APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(),
               APP_CONFIG_CACHE=str(root / 'config.php'), APP_ROUTES_CACHE=str(root / 'routes.php'),
               DB_CONNECTION='sqlite', DB_URL='', DB_DATABASE=str(database),
               LARAVEL_STORAGE_PATH=tmp, VIEW_COMPILED_PATH=str(root / 'framework/views'), CACHE_STORE='file',
               SESSION_DRIVER='file', SESSION_SECURE_COOKIE='false', SESSION_COOKIE='cms_identity_test',
               QUEUE_CONNECTION='sync', LOG_CHANNEL='single')
    sms_server = None
    php_tls_options = []
    telegram_token = '123456:' + os.urandom(24).hex()
    if args.providers:
        cert, key = root / 'test-ca.pem', root / 'test-key.pem'
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', str(key), '-out', str(cert),
                        '-days', '1', '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost'], capture_output=True, check=True)
        os.chmod(key, 0o600)
        sms_server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), SmsCapture)
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(cert, key)
        sms_server.socket = context.wrap_socket(sms_server.socket, server_side=True)
        threading.Thread(target=sms_server.serve_forever, daemon=True).start()
        php_tls_options = ['-d', f'curl.cainfo={cert}', '-d', f'openssl.cafile={cert}']
    bootstrap = "require getenv('CMS_TEST_REPO').'/vendor/autoload.php';\n$app=require getenv('CMS_TEST_REPO').'/bootstrap/app.php';\n$app->usePublicPath(getenv('CMS_TEST_PUBLIC'));\n"
    console = root / 'console.php'
    console.write_text('<?php\n' + bootstrap + 'exit($app->handleCommand(new Symfony\\Component\\Console\\Input\\ArgvInput));\n')
    (root / 'public/index.php').write_text('<?php\n' + bootstrap + '$app->handleRequest(Illuminate\\Http\\Request::capture());\n')
    router = root / 'router.php'
    router.write_text("<?php\n$path=parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);\nif ($path !== '/' && is_file(getenv('CMS_TEST_PUBLIC').$path)) { return false; }\nrequire getenv('CMS_TEST_PUBLIC').'/index.php';\n")

    def cli(*arguments):
        result = subprocess.run(['php', str(console), *arguments], cwd=repo, env=env, capture_output=True, text=True, timeout=45)
        if result.returncode:
            raise RuntimeError(result.stdout + result.stderr)
        return result.stdout

    cli('migrate', '--force')
    cli('filament:assets')
    fixture = root / 'fixture.php'
    fixture.write_text('<?php\n' + bootstrap + r'''
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Models\User::factory()->create(['name'=>'CMS Publisher','email'=>'publisher@example.test','password'=>getenv('CMS_TEST_PASSWORD')]);
App\Models\User::factory()->unverified()->create(['name'=>'CMS Customer','email'=>'customer@example.test','password'=>getenv('CMS_TEST_PASSWORD')]);
App\Models\User::factory()->create(['name'=>'CMS Editor','email'=>'editor@example.test','password'=>getenv('CMS_TEST_PASSWORD')]);
$product = App\Models\Product::factory()->create(['name'=>'账户验收产品']);
for ($index = 1; $index <= 61; $index++) {
    App\Models\Invoice::factory()->create(['user_id'=>2, 'product_id'=>$product->id, 'invoice_no'=>sprintf('PORTAL-%03d', $index),
        'line_items'=>['private_snapshot'=>'PRIVATE-PORTAL-SNAPSHOT']]);
}
for ($index = 1; $index <= 51; $index++) {
    App\Models\Instance::factory()->active()->create(['user_id'=>2, 'product_id'=>$product->id, 'display_name'=>sprintf('本人服务-%03d', $index),
        'billing_cycle'=>App\Enums\BillingCycle::ONETIME, 'encrypted_credentials'=>['card_key'=>'ISOLATED-OWNER-CARD']]);
}
App\Models\Invoice::factory()->create(['user_id'=>1, 'product_id'=>$product->id, 'invoice_no'=>'FOREIGN-PORTAL-INVOICE']);
App\Models\Instance::factory()->active()->create(['user_id'=>1, 'product_id'=>$product->id, 'display_name'=>'FOREIGN-PORTAL-SERVICE']);
''')
    subprocess.run(['php', str(fixture)], cwd=repo, env=env, capture_output=True, check=True)
    cli('cms:role', '1', 'cms-publisher', '--reason=Isolated acceptance')
    cli('cms:role', '3', 'cms-editor', '--reason=Isolated acceptance')
    cli('cms:category', 'news', 'updates', '平台动态', '--reason=Isolated acceptance')
    cli('cards:create-product', '验收商品', '10.00', '--reason=Isolated acceptance')
    settings = root / 'identity.json'
    settings.write_text(json.dumps({'mail.transport': 'smtp', 'mail.host': '127.0.0.1', 'mail.port': smtp.server_address[1], 'mail.from_address': 'accounts@example.test'}))
    cli('identity:configure', str(settings), '--reason=Loopback SMTP acceptance')
    if args.providers:
        settings.write_text(json.dumps({'sms.driver': 'idcsmart', 'sms.idcsmart_endpoint': f'https://localhost:{sms_server.server_port}/smsapi.php',
                                       'sms.idcsmart_api': 'isolated-api', 'sms.idcsmart_key': 'isolated-key', 'sms.idcsmart_sign': '隔离验收',
                                       'sms.binding_enabled': True, 'sms.binding_template': 'binding',
                                       'sms.idcsmart_templates': {'binding': {'id': 'isolated-approved-template', 'content': '验证码@var(code)，@var(minutes)分钟内有效'}},
                                       'telegram.bot_token': telegram_token, 'telegram.bot_username': 'isolated_test_bot', 'oauth.telegram_enabled': True,
                                       'kyc.driver': 'idcsmartali', 'kyc.enabled': True,
                                       'kyc.idcsmartali_endpoint': f'https://localhost:{sms_server.server_port}/certapi.php',
                                       'kyc.idcsmartali_api': 'isolated-kyc-api', 'kyc.idcsmartali_key': 'isolated-kyc-key', 'kyc.idcsmartali_biz_code': 'FACE'}))
        cli('identity:configure', str(settings), '--reason=Isolated TLS SMS and signed Telegram acceptance')
    log = open(root / 'http.log', 'w+')
    server = subprocess.Popen(['php', '-d', 'variables_order=EGPCS', *php_tls_options, '-S', f'127.0.0.1:{port}', '-t', str(root / 'public'), str(router)],
                              cwd=root / 'public', env=env, stdout=log, stderr=log, start_new_session=True)
    try:
        for attempt in range(50):
            try:
                urllib.request.urlopen(base + '/up', timeout=1).read()
                break
            except (OSError, urllib.error.URLError):
                time.sleep(.1)
        else:
            raise RuntimeError('Isolated HTTP server did not start')

        def client():
            return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

        def get(browser, path):
            return browser.open(base + path, timeout=20)

        def csrf(browser, path):
            html = get(browser, path).read().decode()
            return re.search(r'name="_token" value="([^"]+)"', html).group(1)

        def post(browser, path, data, page):
            values = {'_token': csrf(browser, page), **data}
            request = urllib.request.Request(base + path, urllib.parse.urlencode(values).encode(), headers={'Origin': base})
            return browser.open(request, timeout=20)

        def login(browser, address, secret=password):
            return post(browser, '/login', {'email': address, 'password': secret}, '/login')

        publisher = client()
        login(publisher, 'publisher@example.test')
        html = get(publisher, '/admin/content').read().decode()
        assert '保存文章' in html and 'livewire' in html
        article = {'category_id': '1', 'title': 'HTTP 公告', 'slug': 'http-release', 'body': '## Hello\n\nHTTP acceptance.', 'content_format': 'markdown',
                   'is_published': '0', 'reason': 'HTTP draft acceptance'}
        post(publisher, '/admin/content/articles', article, '/admin/content')
        try:
            get(publisher, '/news/updates/http-release')
            raise AssertionError('Draft leaked publicly')
        except urllib.error.HTTPError as error:
            assert error.code == 404
        post(publisher, '/admin/content/articles', {**article, 'id': '1', 'is_published': '1', 'reason': 'HTTP publish acceptance'}, '/admin/content?article=1')
        assert 'HTTP acceptance.' in get(publisher, '/news/updates/http-release').read().decode()
        assert '/news/updates/http-release' in get(publisher, '/sitemap.xml').read().decode()
        assert get(publisher, '/news/updates/http-release.md').headers['Content-Type'].startswith('text/markdown')
        editor = client()
        login(editor, 'editor@example.test')
        try:
            post(editor, '/admin/content/articles', {**article, 'id': '1', 'is_published': '1'}, '/admin/content')
            raise AssertionError('Editor changed published content')
        except urllib.error.HTTPError as error:
            assert error.code == 403
        # CSRF protection is exercised by this real HTTP process, unlike Laravel's test middleware.
        try:
            publisher.open(urllib.request.Request(base + '/admin/content/articles', urllib.parse.urlencode(article).encode()), timeout=20)
            raise AssertionError('Missing CSRF was accepted')
        except urllib.error.HTTPError as error:
            assert error.code == 419

        customer = client()
        login(customer, 'customer@example.test')
        listing = get(customer, '/console/invoices').read().decode()
        assert 'PORTAL-061' in listing and 'PORTAL-001' not in listing and '下一页' in listing
        assert 'FOREIGN-PORTAL-INVOICE' not in listing and 'PRIVATE-PORTAL-SNAPSHOT' not in listing
        assert 'PORTAL-001' in get(customer, '/console/invoices?page=4').read().decode()
        assert 'FOREIGN-PORTAL-INVOICE' not in get(customer, '/console/invoices?q=' + urllib.parse.quote('账户验收产品') + '&user_id=1').read().decode()
        listing = get(customer, '/console/instances').read().decode()
        assert '本人服务-051' in listing and 'ISOLATED-OWNER-CARD' not in listing and 'FOREIGN-PORTAL-SERVICE' not in listing
        assert '本人服务-001' in get(customer, '/console/instances?page=3').read().decode()
        assert 'ISOLATED-OWNER-CARD' in get(customer, '/console/instances/1').read().decode()
        for path in ['/console/invoices/62', '/console/instances/52']:
            try:
                get(customer, path)
                raise AssertionError('Cross-customer detail accepted: ' + path)
            except urllib.error.HTTPError as error:
                assert error.code == 403
        post(customer, '/email/verification-notification', {}, '/console/account')
        verify_url = re.search(r'https?://\S+', messages[-1]['body']).group()
        fresh = client()
        assert urllib.parse.urlparse(fresh.open(verify_url, timeout=20).url).path == '/login'
        assert '邮箱已验证' in login(fresh, 'customer@example.test').read().decode()
        assert '已验证' in get(customer, '/console/account').read().decode()
        guest = client()
        post(guest, '/forgot-password', {'email': 'customer@example.test'}, '/forgot-password')
        reset_url = re.search(r'https?://\S+', messages[-1]['body']).group()
        token = urllib.parse.urlparse(reset_url).path.rsplit('/', 1)[-1]
        new_password = 'Reset-' + os.urandom(12).hex() + '!'
        post(guest, '/reset-password', {'email': 'customer@example.test', 'token': token, 'password': new_password,
                                      'password_confirmation': new_password}, urllib.parse.urlparse(reset_url).path + '?' + urllib.parse.urlparse(reset_url).query)
        assert urllib.parse.urlparse(get(customer, '/console/account').url).path == '/login', 'Old file session survived password reset'
        login(customer, 'customer@example.test', new_password)
        assert '账户安全' in get(customer, '/console/account').read().decode()
        registration = client()
        html = get(registration, '/register').read().decode()
        assert '注册账户' in html and 'autocomplete="new-password"' in html
        response = post(registration, '/register', {'name': 'HTTP 注册客户', 'email': 'NewCustomer@Example.Test', 'password': password,
                                                   'password_confirmation': password, 'credit': '9999.00'}, '/register')
        assert urllib.parse.urlparse(response.url).path == '/console'
        assert '邮箱待验证' in response.read().decode()
        assert 'newcustomer@example.test' in get(registration, '/console/account').read().decode()
        assert len(messages) == 2
        if args.providers:
            response = post(customer, '/console/account/phone/send', {'phone': '+8613800138000'}, '/console/account')
            html = response.read().decode()
            assert '验证码已发送' in html, re.findall(r'<div class="error".*?</div>', html, re.S)
            assert len(sms_messages) == 1
            response = post(customer, '/console/account/phone/confirm', {'phone': '+8613800138000', 'code': sms_messages[-1]['vars[code]'][0]}, '/console/account')
            html = response.read().decode()
            assert '手机号已验证并绑定' in html, re.findall(r'<div class="error".*?</div>', html, re.S)
            assert '+861******8000' in html, re.findall(r'<h2>手机号绑定</h2>.*?</section>', html, re.S)
            # Existing numeric route limits share a user bucket; isolate the next workflow.
            cli('cache:clear')
            response = post(customer, '/auth/telegram/redirect', {'link': '1', 'current_password': new_password}, '/console/account')
            html = response.read().decode()
            assert 'data-telegram-login="isolated_test_bot"' in html and telegram_token not in html
            state = urllib.parse.parse_qs(urllib.parse.urlparse(response.url).query)['state'][0]
            proof = {'id': '123456789', 'first_name': ' Isolated User ', 'auth_date': str(int(time.time()))}
            data_check = '\n'.join(k + '=' + proof[k] for k in sorted(proof))
            proof['hash'] = hmac.new(hashlib.sha256(telegram_token.encode()).digest(), data_check.encode(), hashlib.sha256).hexdigest()
            response = get(customer, '/auth/telegram/callback?' + urllib.parse.urlencode({'state': state, **proof}))
            assert '第三方授权已完成' in response.read().decode()
            cli('cache:clear')
            response = post(customer, '/console/account/telegram/notifications', {'enabled': '1', 'current_password': new_password, 'target': 'UNTRUSTED-CHAT'}, '/console/account')
            assert 'Telegram 通知已开启' in response.read().decode()
            response = post(customer, '/console/account/oauth/telegram/unlink', {'current_password': new_password}, '/console/account')
            assert '第三方账户已解绑' in response.read().decode()
            assert '未开启' in get(customer, '/console/account').read().decode()
            cli('cache:clear')
            response = post(customer, '/console/account/kyc', {'real_name': '隔离用户', 'id_number': '11010119900101001X',
                                                             'current_password': new_password, 'consent': '1'}, '/console/account')
            html = response.read().decode()
            assert '实名申请已创建' in html and '11010119900101001X' not in html and '隔离用户' not in html
            assert kyc_requests == ['initialize']
            response = post(customer, '/console/account/kyc/1/authorize', {}, '/console/account')
            assert 'https://cert.example.test/isolated-authorization' in response.read().decode()
            assert kyc_requests == ['initialize', 'certify']
            post(customer, '/console/account/kyc/1/refresh', {}, '/console/account')
            assert '待核验' in get(customer, '/console/account').read().decode()
            get(customer, '/console/account/kyc/return?status=verified')
            assert '待核验' in get(customer, '/console/account').read().decode()
            kyc_result['status'] = 200
            response = post(customer, '/console/account/kyc/1/refresh', {}, '/console/account')
            assert '实名认证已通过' in response.read().decode()
            assert kyc_requests == ['initialize', 'certify', 'query', 'query']
            assert b'11010119900101001X' not in database.read_bytes() and '隔离用户'.encode() not in database.read_bytes()
            print('PASS: verified loopback TLS SMS/OTP and KYC initialize/certify/poll, original-value Telegram signature, explicit subscription/unlink, KYC return cannot verify and no plaintext identity in SQLite; no real provider messages.', flush=True)
        print('PASS: real HTTP/CSRF, CMS/RBAC, customer registration/login, owner pagination/search/details/403, guest verification continuation, loopback SMTP/reset and old file-session revocation.', flush=True)
        if args.preview:
            print(json.dumps({'base': base, 'publisher': 'publisher@example.test', 'customer': 'customer@example.test', 'password': password,
                              'customer_password': new_password, 'storage': str(root)}, ensure_ascii=False), flush=True)
            input('Browser preview ready; Enter to stop and remove temporary data.\n')
    finally:
        os.killpg(server.pid, signal.SIGTERM)
        server.wait(timeout=10)
        smtp.shutdown()
        smtp.server_close()
        if sms_server is not None:
            sms_server.shutdown()
            sms_server.server_close()
        log.close()
