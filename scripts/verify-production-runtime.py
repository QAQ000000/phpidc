"""Exercise one disposable production stack with loopback payment, supplier and SMTP peers.

Requires root, www, cached mysql:8.0.35/redis:7-alpine, PHP84 FPM, Nginx and OpenSSL.
Never connects to business databases or real external peers. Private evidence remains in /tmp;
all owned processes and containers are removed, including on failure. No service is installed.
"""

import hashlib
import http.cookiejar
import json
import os
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from collections import Counter
from html.parser import HTMLParser

from production_runtime_environment import RuntimeEnvironment
from production_runtime_supplier import LoopbackMail, LoopbackSupplier


class Inputs(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.values = {}
        self.feed(html)

    def handle_starttag(self, tag, attributes):
        attrs = dict(attributes)
        if tag == 'input' and attrs.get('name'):
            self.values.setdefault(attrs['name'], attrs.get('value', ''))


def signature(payload):
    values = {key: value for key, value in payload.items() if key not in ['sign', 'sign_type'] and value != ''}
    return hashlib.md5(('&'.join(key + '=' + str(values[key]) for key in sorted(values)) + 'epay-test-secret').encode()).hexdigest()


def main():
    runtime = RuntimeEnvironment()
    print('Private evidence: ' + str(runtime.root), flush=True)
    result = {'status': 'RUNNING', 'baseline': subprocess.check_output(
        ['git', 'rev-parse', 'HEAD'], cwd=runtime.repo, text=True).strip(),
        'scope': 'disposable production components; loopback external peers; no production activation'}
    with runtime, LoopbackSupplier() as supplier, LoopbackMail() as mail:
        runtime.env.update(B5_SUPPLIER_URL=supplier.base_url, B5_SMTP_PORT=str(mail.port),
                           B5_CUSTOMER_PASSWORD='Runtime-' + os.urandom(16).hex() + '!')
        fixture_path = runtime.repo / 'scripts/fixtures/production-runtime.php'

        def fixture(mode):
            completed = runtime.run_php(fixture_path, mode)
            if completed.returncode:
                (runtime.root / 'fixture-failure.log').write_text(completed.stdout + completed.stderr)
                raise RuntimeError('Fixture failed; inspect private evidence')
            return json.loads(completed.stdout)

        def snapshot():
            return fixture('snapshot')

        def wait_for(predicate, description, timeout=40):
            deadline = time.monotonic() + timeout
            latest = None
            while time.monotonic() < deadline:
                assert not supplier.errors, supplier.errors
                latest = snapshot()
                assert latest['failed_jobs'] == 0, 'Resident worker failed a job'
                if predicate(latest):
                    return latest
                time.sleep(.3)
            (runtime.root / 'timeout-snapshot.json').write_text(json.dumps(latest, indent=2))
            raise AssertionError('Timed out: ' + description)

        def client():
            jar = http.cookiejar.CookieJar()
            opener = urllib.request.build_opener(urllib.request.ProxyHandler({}),
                urllib.request.HTTPSHandler(context=runtime.ssl_context()), urllib.request.HTTPCookieProcessor(jar))
            return opener, jar

        def request(opener, path, body=None, status=200, accept='text/html'):
            data = None if body is None else urllib.parse.urlencode(body).encode()
            req = urllib.request.Request(runtime.base_url + path, data=data, headers={'Accept': accept})
            try:
                response = opener.open(req, timeout=35)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                content = response.read().decode()
                if response.status != status:
                    (runtime.root / 'http-failure.html').write_text(content)
                    raise AssertionError(f'{path}: expected {status}, got {response.status}; inspect private evidence')
                return content, response.headers

        runtime.run_cli('filament:assets')
        installer, _ = client()
        install_page, headers = request(installer, '/install')
        assert 'no-store' in headers['Cache-Control']
        install_token = runtime.run_cli('app:install-token').splitlines()[0]
        admin_password = 'Install-' + os.urandom(16).hex() + '!'
        install_form = {'install_token': install_token, 'admin_name': 'Runtime Access Manager',
            'admin_email': 'runtime-admin@example.test', 'admin_password': admin_password,
            'admin_password_confirmation': admin_password, 'reason': 'B5 isolated production installation'}
        request(installer, '/install', install_form, status=419)
        installed, _ = request(installer, '/install', dict(install_form, _token=Inputs(install_page).values['_token']))
        assert '登录' in installed and (runtime.root / 'storage/installed.lock').is_file()
        request(installer, '/install', status=404)
        post_install_login, _ = request(installer, '/login')
        request(installer, '/install', {'_token': Inputs(post_install_login).values['_token']}, status=404)
        assert not (runtime.root / 'storage/installation.token').exists()
        runtime.run_cli('config:cache')
        runtime.run_cli('route:cache')
        runtime.run_cli('view:cache')
        runtime.run_cli('event:cache')
        deployment = runtime.run_cli('app:deployment-check', '--production')
        assert 'PASS: production' in deployment
        (runtime.root / 'deployment-check.log').write_text(deployment)
        administrator, _ = client()
        admin_login, _ = request(administrator, '/login')
        request(administrator, '/login', {'_token': Inputs(admin_login).values['_token'],
            'email': 'runtime-admin@example.test', 'password': admin_password})
        admin_page, _ = request(administrator, '/admin')
        assert 'Runtime Access Manager' in admin_page
        for asset in ['/js/filament/filament/app.js', '/css/filament/filament/app.css', '/js/classic-config.js']:
            contents, asset_headers = request(administrator, asset)
            assert contents and 'text/html' not in asset_headers['Content-Type']
        fixture_ids = fixture('seed')
        initial = snapshot()
        assert initial['migrations'] == 24 and initial['invoices'] == [] and initial['tasks'] == []
        result['installation'] = {'https_certificate_verified': True, 'mode': 'production',
            'migrations': initial['migrations'], 'deployment_check': 'PASS', 'csrf_missing': 419,
            'installer_closed': 404, 'bootstrap_state': 'private', 'admin_login': 'PASS', 'published_assets_http': 3}
        print('PASS: production HTTPS/FPM installation, assets/caches and MySQL/Redis deployment check.', flush=True)

        buyer, cookies = client()
        login, _ = request(buyer, '/login')
        console, _ = request(buyer, '/login', {'_token': Inputs(login).values['_token'],
            'email': fixture_ids['customer_email'], 'password': runtime.env['B5_CUSTOMER_PASSWORD']})
        csrf = Inputs(console).values['_token']
        assert any(cookie.name == 'b5_runtime_session' and cookie.secure for cookie in cookies)
        product = fixture_ids['classic_product_id']
        configured, _ = request(buyer, f'/console/products/{product}/configure')
        defaults = Inputs(configured).values
        assert defaults['hostname'] and len(defaults['password']) >= 12
        runtime.env['B5_PROVISIONING_PASSWORD'] = defaults['password']
        count_before_quotes = Counter(item['path'] for item in supplier.requests)
        for _ in range(3):
            quoted, _ = request(buyer, f'/console/products/{product}/quote', {'_token': csrf,
                'billing_cycle': 'monthly', 'options[configoption][50]': '501'}, accept='application/json')
            quote = json.loads(quoted)
            assert quote['price'] == '9.60' and quote['setup_fee'] == '0.00' and quote['currency'] == 'CNY' and quote['estimate'] is True
        assert Counter(item['path'] for item in supplier.requests) == count_before_quotes
        purchase_form = {'request_id': '01KAD000000000000000000001', '_token': csrf,
            'product_id': product, 'billing_cycle': 'monthly', 'gateway': 'epay_wechat',
            'hostname': defaults['hostname'], 'password': defaults['password'], 'quote_token': quote['quote_token'],
            'options[configoption][50]': '501', 'options[customfield][80]': 'B5-PRIVATE-FIELD'}
        request(buyer, '/checkout', {key: value for key, value in purchase_form.items() if key != '_token'}, status=419, accept='application/json')
        assert snapshot()['invoices'] == []
        checkout, _ = request(buyer, '/checkout', purchase_form, status=201, accept='application/json')
        bill = json.loads(checkout)
        assert bill['total_amount'] == bill['gateway_amount'] == '9.60' and bill['credit_applied'] == '0.00'
        invoice_id = bill['invoice_id']
        replay, _ = request(buyer, '/checkout', purchase_form, status=201, accept='application/json')
        assert json.loads(replay)['invoice_id'] == invoice_id and len(snapshot()['invoices']) == 1

        def pay(invoice, amount, trade, reject_invalid=False):
            charge, _ = request(buyer, f'/console/invoices/{invoice}/pay', {'_token': csrf}, accept='application/json')
            charged = json.loads(charge)
            assert charged['success'] is True and charged['out_trade_no'] == supplier.state['gateway_order']
            assert supplier.state['gateway_amount'] == amount
            payload = {'pid': 'test-merchant', 'type': 'wxpay', 'out_trade_no': charged['out_trade_no'],
                'trade_no': trade, 'money': amount, 'trade_status': 'TRADE_SUCCESS'}
            payload['sign'] = signature(payload)
            if reject_invalid:
                request(buyer, '/api/payments/epay_wechat/notify', dict(payload, sign='0' * 32), status=422, accept='application/json')
                rejected = snapshot()
                assert rejected['callbacks'] == [] and rejected['tasks'] == []
                assert rejected['invoices'][0]['status'] == 'Unpaid'
            for _ in range(2):
                ack, _ = request(buyer, '/api/payments/epay_wechat/notify', payload)
                assert ack == 'success'
            return payload

        pay(invoice_id, '9.60', 'B5-PURCHASE', reject_invalid=True)
        paid = snapshot()
        assert len(paid['callbacks']) == len(paid['tasks']) == 1
        assert paid['invoices'][0]['status'] == 'Paid' and paid['tasks'][0]['status'] == 'pending'
        assert paid['queues']['default']['ready'] > 0 and not supplier.state['settled']
        worker = runtime.start_worker('default', 'default', 180)
        first_pid = worker.pid
        delivered = wait_for(lambda state: len(state['tasks']) == 1 and state['tasks'][0]['status'] == 'success'
            and state['notifications'] and all(row['status'] == 'sent' for row in state['notifications']), 'purchase delivery')
        instance = delivered['instances'][0]['id']
        detail, detail_headers = request(buyer, f'/console/instances/{instance}')
        assert 'DELIVERED-HOST-PASSWORD' in detail and '192.0.2.17' in detail
        assert 'no-store' in detail_headers['Cache-Control']
        assert supplier.state['paid_invoices'] == ['42']
        print('PASS: generated defaults/local quote, HTTP order, signed duplicate callbacks and resident Redis delivery.', flush=True)

        for offset, (action, amount) in enumerate([('renew', '9.60'), ('upgrade', '2.40')], start=2):
            if action == 'renew':
                runtime.stop_process('default')
            detail, _ = request(buyer, f'/console/instances/{instance}')
            fields = {'_token': Inputs(detail).values['_token'], 'request_id': f'01KAD00000000000000000000{offset}',
                'action': action, 'gateway': 'epay_wechat', 'confirm': '1', 'options[configoption][50]': '502'}
            request(buyer, f'/console/instances/{instance}/operations', fields)
            new_state = snapshot()
            assert len(new_state['invoices']) == offset
            operation_invoice = new_state['invoices'][-1]['id']
            pay(operation_invoice, amount, 'B5-' + action.upper())
            if action == 'renew':
                pending = snapshot()
                assert pending['tasks'][-1]['status'] == 'pending' and pending['queues']['default']['ready'] > 0
                queue_before_restart = pending['queues']['default']
                runtime.stop_redis()
                redis_failure = json.loads(runtime.run_cli('app:operations-check', '--json', expected=2, timeout=25))
                assert any(row['key'] == 'redis' and row['status'] == 'critical' for row in redis_failure['checks'])
                runtime.start_redis()
                after_restart = snapshot()
                assert after_restart['queues']['default'] == queue_before_restart
                assert after_restart['tasks'][-1]['status'] == 'pending'
                assert after_restart['invoices'] == pending['invoices'] and after_restart['ledger'] == pending['ledger']
                restarted = runtime.start_worker('default', 'default', 180)
                assert restarted.pid != first_pid
                result['interruption'] = {'worker_restart': True, 'redis_aof_restart': True,
                    'pending_queue_preserved': True, 'redis_failure_observed': True,
                    'original_worker_pid': first_pid, 'replacement_worker_pid': restarted.pid}
            wait_for(lambda state: len(state['tasks']) == offset and all(row['status'] == 'success' for row in state['tasks'])
                and all(row['status'] == 'sent' for row in state['notifications']), action + ' delivery')
            request(buyer, f'/console/instances/{instance}/operations', fields)
            assert len(snapshot()['invoices']) == offset
        counts = Counter(item['path'] for item in supplier.requests)
        assert {path: counts[path] for path in ['/cart/settle', '/host/renew', '/upgrade/checkout_config_upgrade', '/apply_credit']} == {
            '/cart/settle': 1, '/host/renew': 1, '/upgrade/checkout_config_upgrade': 1, '/apply_credit': 3}
        assert supplier.state['paid_invoices'] == ['42', '43', '44']
        print('PASS: renewal/upgrade once; stopped-worker and Redis AOF interruption preserve pending work.', flush=True)

        runtime.start_worker('maintenance-1', 'maintenance', 25)
        runtime.start_worker('maintenance-2', 'maintenance', 25)
        runtime.start_worker('receivable-check', 'receivable-check', 120)
        scheduler_started = int(time.time())
        runtime.start_scheduler()
        scheduled = wait_for(lambda state: all((state['maintenance'][kind] or {}).get('completed_at', 0) >= scheduler_started
            for kind in ['invoices', 'fulfillment', 'cards', 'lifecycle', 'notifications', 'affiliate-accrue', 'affiliate-mature']),
            'real scheduler to maintenance workers', timeout=85)
        runtime.run_cli('maintenance:dispatch', 'catalog')
        queued = wait_for(lambda state: (state['maintenance']['catalog'] or {}).get('completed_at', 0) >= scheduler_started
            and sum(state['queues'][queue]['ready'] for queue in ['lifecycle', 'lifecycle-urgent']) > 0
            and state['queues']['catalog']['ready'] > 0, 'domain queues before consumers')
        domain_queues = {key: queued['queues'][key] for key in ['lifecycle', 'lifecycle-urgent', 'catalog']}
        runtime.start_worker('lifecycle', 'lifecycle-urgent,lifecycle', 180)
        runtime.start_worker('catalog', 'catalog', 180)
        final = wait_for(lambda state: all(all(value == 0 for value in queue.values()) for queue in state['queues'].values())
            and all(row['status'] == 'sent' for row in state['notifications']), 'domain queues drained')
        operations = json.loads(runtime.run_cli('app:operations-check', '--json'))
        assert operations['status'] == 'ok'
        watchdog = runtime.repo / 'scripts/operations-watchdog.py'
        heartbeat = runtime.root / 'operations-check.json'
        check = subprocess.run([runtime.runuser, '--user', 'www', '--', sys.executable, str(watchdog), 'check',
            '--state', str(heartbeat)], cwd=runtime.repo, env=runtime.env, capture_output=True, text=True, timeout=20)
        assert check.returncode == 0 and json.loads(check.stdout)['observation']['status'] == 'ok'
        watch = subprocess.run([sys.executable, str(watchdog), 'watch', '--state', str(heartbeat)], capture_output=True, text=True, timeout=10)
        assert watch.returncode == 0 and json.loads(watch.stdout)['status'] == 'ok'
        stale = json.loads(heartbeat.read_text())
        stale['finished_at'] = time.time() - 120
        stale_path = runtime.root / 'operations-stale.json'
        stale_path.write_text(json.dumps(stale))
        watched_stale = subprocess.run([sys.executable, str(watchdog), 'watch', '--state', str(stale_path)], capture_output=True, text=True, timeout=10)
        assert watched_stale.returncode == 2 and json.loads(watched_stale.stdout)['code'] == 'check_heartbeat_stale'
        verified = fixture('verify')
        assert verified['status'] == 'PASS' and not supplier.errors
        notification_count = len(verified['snapshot']['notifications'])
        assert len(mail.messages) == notification_count > 0
        assert all(message['recipient_count'] == 1 for message in mail.messages)
        assert len({message['message_id'] for message in mail.messages}) == notification_count
        processes = runtime.process_info()
        for name in ['default', 'maintenance-1', 'maintenance-2', 'receivable-check', 'lifecycle', 'catalog', 'scheduler']:
            assert runtime.processes[name].poll() is None, 'Resident process exited: ' + name
        php_members = [member for process in processes.values() for member in process['members']
            if member['exe'] == runtime.php]
        assert any(member['uid'] == runtime.www.pw_uid and 'queue:work' in member['argv'] for member in php_members)
        assert any(member['uid'] == runtime.www.pw_uid and 'schedule:work' in member['argv'] for member in php_members)
        result.update(status='PASS', business=verified, supplier_path_counts=dict(counts), smtp_messages=mail.messages,
            local_quotes_without_upstream=3, default_values_nonempty=True, domain_queues_before_consumers=domain_queues,
            processes=processes, operations=operations, watchdog={'healthy': 'ok', 'stale': 'critical'},
            real_external_payments=0, real_supplier_mutations=0, external_mail_deliveries=0,
            production_services_installed=False)
        runtime.write_result(result)
        print('PASS: real scheduler/maintenance/domain queues, SMTP receipt and independent health/watchdog.', flush=True)
    assert all(row['absent'] for row in runtime.cleanup_result['containers'].values())
    print('PASS: owned processes/containers cleaned; private result and cleanup evidence: ' + str(runtime.root), flush=True)


if __name__ == '__main__':
    main()
