"""Opt-in live acceptance: create one dedicated zero-balance user, issue and redeem exactly one unit.

Requires explicit operator authorization. A new state directory is mandatory. On failure, preserve it
and reconcile the existing task; never rerun with a new directory to mask an ambiguous issuance.
Administrator credentials are read with getpass and stored only encrypted in the isolated Laravel DB.
"""
import argparse
import base64
import decimal
import getpass
import json
import os
import pathlib
import secrets
import shutil
import sqlite3
import subprocess
import urllib.error
import urllib.request

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--url', required=True)
parser.add_argument('--state-dir', required=True)
parser.add_argument('--allow-live-unit', action='store_true')
args = parser.parse_args()
if not args.allow_live_unit:
    parser.error('Explicit authorization and --allow-live-unit are required')
root = pathlib.Path(args.state_dir).resolve()
root.mkdir(mode=0o700, exist_ok=False)
repo = pathlib.Path(__file__).resolve().parents[1]
base = args.url.rstrip('/')
run = 'cms-sub2api-' + secrets.token_hex(8)
evidence = {'run_id': run, 'base_url': base, 'authorized_units': 1, 'stage': 'starting'}

def save(**values):
    evidence.update(values)
    (root / 'evidence.json').write_text(json.dumps(evidence, indent=2))

key = getpass.getpass('Sub2API administrator key (hidden): ')
password = secrets.token_urlsafe(30)
email = run + '@example.test'

def api(method, path, payload=None, token=None):
    headers = {'Accept': 'application/json', 'Content-Type': 'application/json', 'User-Agent': 'Laravel13idc-Sub2API-Integration/1.0'}
    headers.update({'Authorization': 'Bearer ' + token} if token else {'x-api-key': key})
    data = None if payload is None else json.dumps(payload).encode()
    request = urllib.request.Request(base + path, data=data, method=method, headers=headers)
    try:
        with urllib.request.urlopen(request, timeout=35) as response:
            return response.status, json.loads(response.read(), parse_float=decimal.Decimal)
    except urllib.error.HTTPError as error:
        raw = error.read()
        try:
            return error.code, json.loads(raw, parse_float=decimal.Decimal)
        except ValueError:
            return error.code, {'code': -1}

# No retries for any real mutation. Each durable stage is written before it begins.
try:
    status, data = api('GET', '/api/v1/admin/redeem-codes/stats')
    if status != 200 or data.get('code') != 0:
        raise RuntimeError('Read-only connection check failed; HTTP ' + str(status))
    save(stage='creating_test_user', test_user_email=email)
    status, data = api('POST', '/api/v1/admin/users', {'email': email, 'password': password,
        'username': run, 'role': 'user', 'balance': 0, 'concurrency': 1, 'allowed_groups': [],
        'restrict_public_groups': True, 'notes': 'Authorized Laravel CMS one-unit redemption acceptance: ' + run})
    if status != 200 or data.get('code') != 0:
        raise RuntimeError('Test-user creation failed; HTTP ' + str(status))
    user = data['data']
    user_id = user['id']
    assert decimal.Decimal(str(user['balance'])) == 0
    save(stage='logging_in_test_user', test_user_id=user_id)
    status, data = api('POST', '/api/v1/auth/login', {'email': email, 'password': password})
    if status != 200 or data.get('code') != 0 or not data.get('data', {}).get('access_token'):
        raise RuntimeError('Normal customer login failed; no code has been issued. HTTP ' + str(status))
    token = data['data']['access_token']
    status, profile = api('GET', '/api/v1/user/profile', token=token)
    assert status == 200 and profile['code'] == 0
    before = decimal.Decimal(str(profile['data']['balance']))
    assert before == 0
    for folder in ['framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private']:
        (root / folder).mkdir(parents=True, exist_ok=True)
    db = root / 'acceptance.sqlite'
    db.touch(mode=0o600)
    (root / 'installed.lock').write_text('isolated live protocol acceptance')
    env = dict(os.environ, SUB2API_TEST_REPO=str(repo), SUB2API_TEST_URL=base,
        APP_ENV='local', APP_DEBUG='false', APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(),
        APP_CONFIG_CACHE=str(root / 'config.php'), APP_ROUTES_CACHE=str(root / 'routes.php'),
        DB_CONNECTION='sqlite', DB_DATABASE=str(db), DB_URL='', DB_QUEUE_CONNECTION='sqlite', DB_QUEUE_TABLE='jobs',
        LARAVEL_STORAGE_PATH=str(root), VIEW_COMPILED_PATH=str(root / 'framework/views'),
        CACHE_STORE='file', SESSION_DRIVER='file', QUEUE_CONNECTION='database', LOG_CHANNEL='single')
    # Keep the recovery key private until the operator archives or removes this isolated run.
    recovery = root / 'recovery-env.json'
    recovery.touch(mode=0o600)
    recovery.write_text(json.dumps({k: env[k] for k in ['APP_KEY', 'DB_DATABASE', 'LARAVEL_STORAGE_PATH', 'APP_CONFIG_CACHE', 'APP_ROUTES_CACHE']}))

    def cli(*arguments):
        result = subprocess.run(['php', 'artisan', *arguments], cwd=repo, env=env, capture_output=True, text=True, timeout=60)
        if result.returncode:
            raise RuntimeError('Isolated artisan command failed: ' + arguments[0])
        return result.stdout

    cli('migrate', '--force')
    fixture = root / 'fixture.php'
    fixture.write_text(r'''<?php
require getenv('SUB2API_TEST_REPO').'/vendor/autoload.php';
$app=require getenv('SUB2API_TEST_REPO').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (($argv[1] ?? '') === 'setup') {
    $key=stream_get_contents(STDIN);
    $account=$app->make(App\Actions\ConfigureSub2ApiAction::class)->execute('Authorized live test',getenv('SUB2API_TEST_URL'),$key,'Authorized one-unit integration acceptance');
    $product=$app->make(App\Actions\CreateSub2ApiProductAction::class)->execute($account,'Live one-unit balance code','1.00','1','CNY','Authorized one-unit integration acceptance');
    $buyer=App\Models\User::factory()->create(['credit'=>'2.00']);
    $invoice=$app->make(App\Actions\CreateComboInvoiceAction::class)->execute($buyer,$product,App\Enums\BillingCycle::ONETIME,[],true);
    echo json_encode(['invoice_id'=>$invoice->id,'buyer_id'=>$buyer->id,'task_id'=>App\Models\FulfillmentTask::sole()->id]);
} else {
    $task=App\Models\FulfillmentTask::sole();
    $app->make(App\Actions\ExecuteFulfillmentTaskAction::class)->execute($task->id);
    $instance=$task->instance->fresh();
    echo json_encode(['status'=>$task->fresh()->status->value,'instance_status'=>$instance->status->value,
        'upstream_id'=>$instance->upstream_instance_id,'credentials'=>$instance->encrypted_credentials,
        'wallet'=>$task->user->credit]);
}
''')
    result = subprocess.run(['php', str(fixture), 'setup'], cwd=repo, env=env, input=key, capture_output=True, text=True, timeout=30)
    if result.returncode:
        raise RuntimeError('Isolated Laravel setup failed; no remote generation was attempted')
    local = json.loads(result.stdout)
    save(stage='before_single_generation', local=local)
    cli('queue:work', 'database', '--stop-when-empty', '--tries=1', '--timeout=180')
    with sqlite3.connect(db) as connection:
        task_status, journal = connection.execute('select status,payload from fulfillment_tasks').fetchone()
        if task_status != 'success':
            save(stage='issuance_requires_reconciliation', task_status=task_status, request_intent={k:v for k,v in json.loads(journal).get('remote_delivery', {}).items() if k != 'encrypted_result'})
            raise RuntimeError('Remote issuance was not delivered; preserve the original task and reconcile it without issuing another code')
    result = subprocess.run(['php', str(fixture), 'inspect'], cwd=repo, env=env, capture_output=True, text=True, timeout=35)
    if result.returncode:
        raise RuntimeError('Cannot inspect the delivered result; preserve isolated state')
    delivery = json.loads(result.stdout)
    assert delivery['status'] == 'success' and delivery['instance_status'] == 'Active'
    assert delivery['wallet'] == '1.00' and delivery['credentials']['face_value'] == '1.00'
    code_id = int(delivery['upstream_id'])
    card = delivery['credentials']['card_key']
    save(stage='before_redemption', upstream_code_id=code_id, balance_before=str(before))
    status, record = api('GET', '/api/v1/admin/redeem-codes/' + str(code_id))
    assert status == 200 and record['code'] == 0 and record['data']['id'] == code_id
    assert record['data']['status'] == 'unused' and decimal.Decimal(str(record['data']['value'])) == 1
    status, redeemed = api('POST', '/api/v1/redeem', {'code': card}, token)
    if status != 200 or redeemed.get('code') != 0:
        raise RuntimeError('Normal customer redemption failed; do not generate a replacement code')
    status, profile = api('GET', '/api/v1/user/profile', token=token)
    after = decimal.Decimal(str(profile['data']['balance']))
    assert status == 200 and profile['code'] == 0 and after - before == 1
    save(stage='redeemed', balance_after=str(after))
    status, duplicate = api('POST', '/api/v1/redeem', {'code': card}, token)
    assert status >= 400 and duplicate.get('code') != 0
    status, profile = api('GET', '/api/v1/user/profile', token=token)
    assert status == 200 and decimal.Decimal(str(profile['data']['balance'])) == after
    status, record = api('GET', '/api/v1/admin/redeem-codes/' + str(code_id))
    assert status == 200 and record['data']['status'] == 'used'
    with sqlite3.connect(db) as connection:
        assert connection.execute('select count(*) from fulfillment_tasks').fetchone()[0] == 1
        assert connection.execute("select count(*) from redemption_codes where status='sold'").fetchone()[0] == 1
        assert connection.execute('select count(*) from financial_transactions where reference_key=?', ('settle:' + str(local['invoice_id']),)).fetchone()[0] == 1
        operations = connection.execute('select method,path,http_status,outcome,request_payload,response_body from upstream_operations').fetchall()
        assert len(operations) == 1 and operations[0][0] == 'POST' and operations[0][2] == 200
    for path in [db, root / 'logs/laravel.log']:
        if path.exists():
            contents = path.read_bytes()
            assert key.encode() not in contents and card.encode() not in contents
    save(stage='complete', duplicate_redemption_rejected=True, generated_codes=1, local_wallet_after='1.00',
         upstream_code_status='used', operation_rows=[list(row) for row in operations])
    # Keep sanitized evidence; remove temporary secrets and financial test fixtures after success.
    for artifact in root.iterdir():
        if artifact.name != 'evidence.json':
            shutil.rmtree(artifact) if artifact.is_dir() else artifact.unlink()
    print('PASS: one real generated code, one-unit normal customer redemption, repeated redemption rejected with no additional balance increase.')
    print('PASS: isolated Laravel wallet checkout and DB queue delivered once; local secrets encrypted and operation logs redacted.')
    print('EVIDENCE=' + str(root / 'evidence.json'))
    print('TEST_USER_ID=' + str(user_id) + ' UPSTREAM_CODE_ID=' + str(code_id))
except Exception as error:
    # Fixed diagnostics only: never echo upstream bodies, access tokens, card strings or administrator credentials.
    print('STOP: ' + type(error).__name__ + '; preserved state at ' + str(root))
    print('LAST_STAGE=' + evidence['stage'])
    raise SystemExit(1)
