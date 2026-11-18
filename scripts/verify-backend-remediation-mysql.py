"""Run FIX-007..017 regressions in disposable offline MySQL 8; never migrate existing databases."""

import argparse
import base64
import os
import pathlib
import subprocess
import tempfile
import time
import uuid

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--image', default='mysql:8.0.35', help='Locally available MySQL 8 image; never pulled')
parser.add_argument('--full', action='store_true', help='Also run historical CMS/catalog/telemetry suites; their strict JSON object order assertions are not MySQL portable')
args = parser.parse_args()
repo = pathlib.Path(__file__).resolve().parents[1]
subprocess.run(['docker', 'image', 'inspect', args.image], capture_output=True, check=True, timeout=10)
container = 'laravel13idc-fix007-017-' + uuid.uuid4().hex[:12]

with tempfile.TemporaryDirectory(prefix='laravel13idc-fix007-017-mysql-') as temporary:
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
               DB_SOCKET=str(socket_dir / 'mysqld.sock'), DB_DATABASE='backend_isolated',
               DB_USERNAME='root', DB_PASSWORD='', CACHE_STORE='array', QUEUE_CONNECTION='sync', SESSION_DRIVER='array', LOG_CHANNEL='single', BCRYPT_ROUNDS='4',
               REMEDIATION_REPO=str(repo), REMEDIATION_ROOT=str(root))
    probe = root / 'probe.php'
    probe.write_text("""<?php
$pdo = new PDO('mysql:unix_socket='.getenv('DB_SOCKET').';dbname=backend_isolated', 'root', '');
$version = $pdo->query('SELECT VERSION()')->fetchColumn();
if (!str_starts_with($version, '8.')) { throw new RuntimeException('MySQL 8 is required.'); }
echo $version;
""")
    try:
        subprocess.run(['docker', 'run', '--detach', '--pull=never', '--name', container,
                        '--label', 'laravel13idc.remediation=FIX-007-017', '--network', 'none',
                        '--tmpfs', '/var/lib/mysql:rw,size=768m',
                        '--mount', 'type=bind,source=' + str(socket_dir) + ',target=/var/run/mysqld',
                        '--env', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes', '--env', 'MYSQL_DATABASE=backend_isolated',
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
        cases = ['tests/Feature/BackendRemediationTest.php', 'tests/Feature/ClassicPanelLoginTest.php', 'tests/Feature/PurchaseIdempotencyTest.php']
        if args.full:
            cases += ['tests/Feature/CmsAdministrationTest.php', 'tests/Feature/CmsStorefrontTest.php', 'tests/Feature/ClassicExpansionTest.php', 'tests/Feature/ClassicCloudTelemetryTest.php']
        subprocess.run(['vendor/bin/pest', *cases, '--compact'], cwd=repo, env=env, check=True, timeout=180)
        print('PASS: targeted isolated MySQL 8 new remediation, panel and purchase regressions; business databases untouched.', flush=True)
    finally:
        subprocess.run(['docker', 'rm', '--force', container], capture_output=True, timeout=20)
