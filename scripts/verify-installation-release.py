"""Disposable empty-DB web installation, deployment caches/assets, delivery, backup/restore and key rotation.

Uses only loopback HTTP, temporary SQLite/storage/public/cache and an independent worker.
--redis starts its own disposable Redis; never connects to a configured business service or supplier.
"""
import argparse
import base64
import http.cookiejar
import json
import os
import pathlib
import re
import shutil
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
parser.add_argument('--redis', action='store_true', help='Start a separate loopback Redis and exercise Redis queue/cache/session')
parser.add_argument('--preview-install', action='store_true', help='Pause before installation for a disposable browser acceptance')
args = parser.parse_args()
repo = pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='cloudcms-release-') as tmp:
    root = pathlib.Path(tmp)
    db = root / 'release.sqlite'
    db.touch()
    for directory in ['public/js', 'framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private']:
        (root / directory).mkdir(parents=True)
    shutil.copyfile(repo / 'public/js/classic-config.js', root / 'public/js/classic-config.js')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    password = 'Release-Isolated-' + os.urandom(12).hex() + '!'
    old_key = 'base64:' + base64.b64encode(os.urandom(32)).decode()
    env = dict(os.environ, RELEASE_TEST_REPO=str(repo), RELEASE_TEST_PUBLIC=str(root / 'public'),
               APP_ENV='local', APP_DEBUG='false', APP_URL=base, APP_KEY=old_key,
               APP_CONFIG_CACHE=str(root / 'config.php'), APP_ROUTES_CACHE=str(root / 'routes.php'),
               APP_EVENTS_CACHE=str(root / 'events.php'), DB_CONNECTION='sqlite', DB_URL='', DB_DATABASE=str(db),
               DB_QUEUE_CONNECTION='sqlite', LARAVEL_STORAGE_PATH=tmp, VIEW_COMPILED_PATH=str(root / 'framework/views'),
               CACHE_STORE='file', SESSION_DRIVER='file', SESSION_SECURE_COOKIE='false',
               QUEUE_CONNECTION='database', LOG_CHANNEL='single')
    if args.redis:
        with socket.socket() as sock:
            sock.bind(('127.0.0.1', 0))
            redis_port = sock.getsockname()[1]
        env.update(CACHE_STORE='redis', SESSION_DRIVER='redis', QUEUE_CONNECTION='redis',
                   REDIS_HOST='127.0.0.1', REDIS_PORT=str(redis_port), REDIS_PASSWORD='null',
                   REDIS_URL='', REDIS_DB='0', REDIS_CACHE_DB='1', REDIS_PREFIX='release_isolated_',
                   REDIS_CLIENT='phpredis', REDIS_QUEUE_RETRY_AFTER='240')
    bootstrap = "require getenv('RELEASE_TEST_REPO').'/vendor/autoload.php';\n$app=require getenv('RELEASE_TEST_REPO').'/bootstrap/app.php';\n$app->usePublicPath(getenv('RELEASE_TEST_PUBLIC'));\n"
    console = root / 'console.php'
    console.write_text('<?php\n' + bootstrap + 'exit($app->handleCommand(new Symfony\\Component\\Console\\Input\\ArgvInput));\n')
    (root / 'public/index.php').write_text('<?php\n' + bootstrap + '$app->handleRequest(Illuminate\\Http\\Request::capture());\n')
    router = root / 'router.php'
    router.write_text("<?php\n$path=parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);\nif ($path !== '/' && is_file(getenv('RELEASE_TEST_PUBLIC').$path)) { return false; }\nrequire getenv('RELEASE_TEST_PUBLIC').'/index.php';\n")

    def cli(*arguments, expected=0):
        result = subprocess.run(['php', str(console), *arguments], cwd=repo, env=env, capture_output=True, text=True, timeout=60)
        assert result.returncode == expected, result.stdout + result.stderr
        return result.stdout + result.stderr

    def client():
        return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def request(opener, path, data=None, accept='text/html'):
        body = None if data is None else urllib.parse.urlencode(data).encode()
        with opener.open(urllib.request.Request(base + path, data=body, headers={'Accept': accept}), timeout=30) as response:
            return response.read().decode(), response.status, response.headers

    def rejected(opener, path, status, data=None):
        try:
            request(opener, path, data)
        except urllib.error.HTTPError as error:
            assert error.code == status, (path, error.code)
        else:
            raise AssertionError(f'Expected {status} at {path}')

    def csrf(html):
        return re.search(r'name="_token"\s+value="([^"]+)"', html).group(1)

    logs = open(root / 'http.log', 'w+')
    redis_server = None
    redis_logs = None
    server = subprocess.Popen(['php', '-d', 'variables_order=EGPCS', '-S', f'127.0.0.1:{port}', '-t', str(root / 'public'), str(router)],
                              cwd=root / 'public', env=env, stdout=logs, stderr=logs, start_new_session=True)
    try:
        if args.redis:
            redis_binary = shutil.which('redis-server') or '/www/server/redis/src/redis-server'
            redis_logs = open(root / 'redis.log', 'w+')
            redis_server = subprocess.Popen([redis_binary, '--bind', '127.0.0.1', '--port', str(redis_port),
                                             '--save', '', '--appendonly', 'no', '--dir', tmp, '--loglevel', 'warning'],
                                            stdout=redis_logs, stderr=redis_logs, start_new_session=True)
            for _ in range(50):
                try:
                    with socket.create_connection(('127.0.0.1', redis_port), timeout=.2):
                        break
                except OSError:
                    time.sleep(.1)
            else:
                raise RuntimeError('Disposable Redis failed to start')
        for _ in range(50):
            try:
                urllib.request.urlopen(base + '/up', timeout=1).read()
                break
            except (OSError, urllib.error.URLError):
                time.sleep(.1)
        else:
            raise RuntimeError('Isolated server failed to start')
        # Publish before the wizard redirects to the first Filament login, including its Livewire scripts.
        cli('filament:assets')
        installer = client()
        html, _, headers = request(installer, '/install')
        assert 'no-store' in headers['Cache-Control'] and '安装令牌' in html
        assert not (root / 'installed.lock').exists()
        with sqlite3.connect(db) as connection:
            assert connection.execute('select name from sqlite_master where type="table"').fetchall() == []
        install_token = cli('app:install-token').splitlines()[0]
        form = {'install_token': install_token, 'admin_name': 'Release installer', 'admin_email': 'admin@example.test',
                'admin_password': password, 'admin_password_confirmation': password, 'reason': 'Isolated release install'}
        rejected(installer, '/install', 419, form)
        html, _, _ = request(installer, '/install', dict(form, _token=csrf(html), install_token='invalid'))
        assert '安装令牌无效' in html and not (root / 'installed.lock').exists()
        if args.preview_install:
            print('PREVIEW_URL=' + base, flush=True)
            print('PREVIEW_TOKEN=' + install_token, flush=True)
            print('PREVIEW_EMAIL=admin@example.test', flush=True)
            print('PREVIEW_PASSWORD=' + password, flush=True)
            input('Complete the disposable install in the browser, then press Enter to continue. ')
        else:
            html, _, _ = request(installer, '/install', dict(form, _token=csrf(html)))
            assert '登录' in html
        assert (root / 'installed.lock').exists()
        rejected(installer, '/install', 404)
        assert not (root / 'installation.token').exists()
        with sqlite3.connect(db) as connection:
            assert connection.execute('select count(*) from users').fetchone()[0] == 1
            assert connection.execute("select count(*) from audit_logs where action='installation.bootstrap' and ip is not null and request_id is not null").fetchone()[0] == 1
        cli('config:cache')
        cli('route:cache')
        cli('view:cache')
        cli('event:cache')
        assert 'local validation only' in cli('app:deployment-check')
        assert '生产环境' in cli('app:deployment-check', '--production', expected=1)
        admin = client()
        html, _, _ = request(admin, '/login')
        request(admin, '/login', {'_token': csrf(html), 'email': 'admin@example.test', 'password': password})
        html, _, _ = request(admin, '/admin/access')
        assert '角色' in html
        rejected(admin, '/admin/finance', 403)
        for asset in ['/js/filament/filament/app.js', '/css/filament/filament/app.css', '/js/classic-config.js']:
            request(admin, asset)

        cli('cards:create-product', 'Release zero-cost delivery', '0.00', '--reason=Isolated release smoke')
        cards = root / 'cards.txt'
        cards.write_text('RELEASE-DISPOSABLE-CARD\n')
        cards.chmod(0o600)
        cli('cards:import', '1', str(cards), '--reason=Isolated delivery stock')
        buyer = client()
        html, _, _ = request(buyer, '/register')
        request(buyer, '/register', {'_token': csrf(html), 'name': 'Buyer', 'email': 'buyer@example.test',
                                    'password': password, 'password_confirmation': password})
        html, _, _ = request(buyer, '/console')
        created, status, _ = request(buyer, '/checkout', {'request_id': '01KAD000000000000000000001', '_token': csrf(html), 'product_id': 1, 'billing_cycle': 'onetime'}, 'application/json')
        assert status == 201
        invoice_id = json.loads(created)['invoice_id']
        cli('queue:work', 'redis' if args.redis else 'database', '--stop-when-empty', '--tries=3', '--timeout=180')
        with sqlite3.connect(db) as connection:
            instance_id = connection.execute('select instance_id from fulfillment_tasks where invoice_id=?', (invoice_id,)).fetchone()[0]
            assert connection.execute('select status from invoices where id=?', (invoice_id,)).fetchone()[0] == 'Paid'
            assert connection.execute('select status from instances where id=?', (instance_id,)).fetchone()[0] == 'Active'
        assert 'RELEASE-DISPOSABLE-CARD' in request(buyer, f'/console/instances/{instance_id}')[0]
        cli('schedule:list')
        cli('schedule:run')

        # A consistent offline snapshot includes the DB, sealed install state, private files and matching key.
        cli('down', '--retry=60')
        backup = root / 'backup'
        backup.mkdir(mode=0o700)
        with sqlite3.connect(db) as source, sqlite3.connect(backup / 'database.sqlite') as target:
            source.backup(target)
        shutil.copyfile(root / 'installed.lock', backup / 'installed.lock')
        shutil.copytree(root / 'app/private', backup / 'private')
        (backup / 'app-key').write_text(old_key)
        (backup / 'app-key').chmod(0o600)
        with sqlite3.connect(db) as connection:
            connection.execute("update products set name='Restore must remove this edit' where id=1")
        with sqlite3.connect(backup / 'database.sqlite') as source, sqlite3.connect(db) as target:
            source.backup(target)
        shutil.copyfile(backup / 'installed.lock', root / 'installed.lock')
        assert 'local validation only' in cli('app:deployment-check')

        next_key = 'base64:' + base64.b64encode(os.urandom(32)).decode()
        next_file = root / 'next.key'
        next_file.write_text(next_key)
        next_file.chmod(0o600)
        cli('app:reencrypt-secrets', '--new-key-file=' + str(next_file), '--reason=Isolated rotation drill', '--workers-stopped')
        # The old config cache must fail at application boot, including /up/CLI, before any order can run.
        assert 'MAC is invalid' in cli('list', expected=1)
        (root / 'config.php').unlink()  # trusted file operation while the old cached key cannot boot
        env['APP_KEY'] = next_key
        cli('config:cache')
        assert 'local validation only' in cli('app:deployment-check')
        cli('up')
        # The HTTP server inherited the old environment, but reads the new sealed configuration cache per request.
        buyer = client()
        html, _, _ = request(buyer, '/login')
        request(buyer, '/login', {'_token': csrf(html), 'email': 'buyer@example.test', 'password': password})
        assert 'RELEASE-DISPOSABLE-CARD' in request(buyer, f'/console/instances/{instance_id}')[0]
        with sqlite3.connect(db) as connection:
            assert connection.execute('select name from products where id=1').fetchone()[0] == 'Release zero-cost delivery'
            assert connection.execute("select count(*) from audit_logs where action='security.reencrypt'").fetchone()[0] == 1
        print('PASS: empty-DB web install; token/CSRF; audited least-privilege admin; installer closed; assets and config/route/view/event caches.')
        print('PASS: real registration, checkout, independent worker delivery and scheduler; offline backup/restore; key rotation and old-key boot refusal; delivered credentials remain readable.')
        if args.redis:
            print('PASS: private loopback Redis queue/cache/session; one-server scheduler locks; no shared Redis was contacted.')
        print('LIMIT: SQLite/local isolation only. MySQL 8, production Redis/FPM/TLS, resident services and paid supplier acceptance require the production runbook.')
    finally:
        os.killpg(server.pid, signal.SIGTERM)
        server.wait(timeout=10)
        logs.close()
        if redis_server is not None:
            redis_server.terminate()
            redis_server.wait(timeout=10)
        if redis_logs is not None:
            redis_logs.close()
