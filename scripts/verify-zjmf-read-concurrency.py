"""Exercise the actual HTTP transport against a loopback-only delayed supplier.

Uses an unsaved synthetic account, an in-memory cache and an isolated storage
directory. No preview/business database or real supplier endpoint is accepted.
"""

import base64
import http.server
import json
import os
import pathlib
import subprocess
import tempfile
import threading
import time
import urllib.parse


def main():
    os.umask(0o077)
    repo = pathlib.Path(__file__).resolve().parents[1]
    root = pathlib.Path(tempfile.mkdtemp(prefix='laravel13idc-read-concurrency-'))
    state = {'active': 0, 'maximum': 0, 'requests': []}
    lock = threading.Lock()

    class Supplier(http.server.BaseHTTPRequestHandler):
        def log_message(self, *args):
            pass

        def do_GET(self):
            self.respond()

        def do_POST(self):
            self.respond()

        def respond(self):
            path = urllib.parse.urlsplit(self.path).path
            if path == '/zjmf_api_login':
                result = {'status': 200, 'jwt': 'synthetic-loopback-token'}
            else:
                with lock:
                    state['active'] += 1
                    state['maximum'] = max(state['maximum'], state['active'])
                    state['requests'].append({'path': path, 'started': time.monotonic()})
                try:
                    time.sleep(0.25)
                    result = {'status': 200, 'data': {'status': 'on'}, 'total': '0.10'}
                finally:
                    with lock:
                        state['active'] -= 1
            body = json.dumps(result).encode()
            self.send_response(200)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)

    server = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Supplier)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    storage = root / 'storage'
    for directory in ['framework/cache/data', 'framework/sessions', 'framework/views', 'logs']:
        (storage / directory).mkdir(parents=True, exist_ok=True)
    clean = {key: value for key, value in os.environ.items() if not key.startswith(
        ('APP_', 'DB_', 'REDIS_', 'LARAVEL_', 'VIEW_', 'QUEUE_', 'CACHE_', 'SESSION_', 'MAIL_', 'LOG_', 'BROADCAST_'))}
    env = dict(clean, APP_ENV='testing', APP_DEBUG='false', DB_CONNECTION='sqlite', DB_DATABASE=':memory:',
               DB_URL='', APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(),
               CACHE_STORE='array', SESSION_DRIVER='array', QUEUE_CONNECTION='sync', MAIL_MAILER='array',
               LARAVEL_STORAGE_PATH=str(storage), APP_CONFIG_CACHE=str(root / 'config.php'),
               APP_SERVICES_CACHE=str(root / 'services.php'), APP_PACKAGES_CACHE=str(root / 'packages.php'),
               CONCURRENCY_REPO=str(repo), CONCURRENCY_ENDPOINT='http://127.0.0.1:' + str(server.server_port))
    harness = root / 'verify.php'
    harness.write_text(r'''<?php
declare(strict_types=1);
require getenv('CONCURRENCY_REPO').'/vendor/autoload.php';
$app = require getenv('CONCURRENCY_REPO').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$account = new App\Models\UpstreamApi(['base_url' => getenv('CONCURRENCY_ENDPOINT'),
    'username' => 'synthetic-user', 'encrypted_secret' => 'synthetic-api-password', 'auth_header_mode' => 'bearer']);
$account->id = 123;
$buffer = new App\Services\UpstreamOperationBuffer;
$client = new App\Drivers\Upstream\ZjmfClient($account, $buffer);
$requests = ['power' => ['method' => 'POST', 'path' => '/provision/default', 'params' => ['id' => '4253', 'func' => 'status']]];
foreach (['cpu','disk','memory','flow'] as $type) {
    $requests[$type] = ['method' => 'GET', 'path' => '/provision/chart/4253', 'params' => ['type' => $type]];
}
$started = hrtime(true);
$results = $client->readBatch($requests);
$operations = $buffer->drain();
if (array_keys($results) !== array_keys($requests) || count($operations) !== 6) {
    throw new RuntimeException('Lost a result or an individual upstream trace.');
}
foreach ($results as $result) {
    if ($result['status'] !== 200 || $result['total'] !== '0.10') {
        throw new RuntimeException('Invalid synthetic result.');
    }
}
echo json_encode(['status' => 'PASS', 'elapsed_ms' => (hrtime(true)-$started)/1000000,
    'results' => count($results), 'operation_traces' => count($operations)], JSON_THROW_ON_ERROR);
''')
    try:
        run = subprocess.run([os.environ.get('APP_PHP', '/www/server/php/84/bin/php'), str(harness)],
                             cwd=repo, env=env, capture_output=True, text=True, timeout=20)
        (root / 'php.log').write_text(run.stdout + run.stderr)
        if run.returncode:
            raise RuntimeError('Isolated transport harness failed; see ' + str(root / 'php.log'))
        result = json.loads(run.stdout)
        assert len(state['requests']) == 5, state
        assert 2 <= state['maximum'] <= 4, state
        result.update(maximum_simultaneous_reads=state['maximum'], requests=state['requests'],
                      business_database_used=False, external_supplier_used=False, evidence=str(root))
        (root / 'result.json').write_text(json.dumps(result, indent=2))
        print(json.dumps(result, indent=2))
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=2)


if __name__ == '__main__':
    main()
