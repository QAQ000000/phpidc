"""Verify review fixes in a disposable offline MySQL 8; retain scripts and evidence in /tmp."""

import base64
import json
import os
import pathlib
import subprocess
import tempfile
import time
import uuid

repo = pathlib.Path(__file__).resolve().parents[1]
image = 'mysql:8.0.35'
subprocess.run(['docker', 'image', 'inspect', image], check=True, capture_output=True, timeout=10)
root = pathlib.Path(tempfile.mkdtemp(prefix='laravel13idc-review-identity-cms-'))
socket_dir = root / 'socket'
socket_dir.mkdir(mode=0o777)
socket_dir.chmod(0o777)
storage = root / 'storage'
for folder in ['framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private']:
    (storage / folder).mkdir(parents=True)
env = dict(os.environ, APP_ENV='testing', APP_DEBUG='false',
           APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(),
           APP_CONFIG_CACHE=str(root / 'config.php'), APP_ROUTES_CACHE=str(root / 'routes.php'),
           LARAVEL_STORAGE_PATH=str(storage), VIEW_COMPILED_PATH=str(storage / 'framework/views'),
           DB_CONNECTION='mysql', DB_URL='', DB_HOST='localhost', DB_PORT='3306',
           DB_SOCKET=str(socket_dir / 'mysqld.sock'), DB_DATABASE='backend_review_only',
           DB_USERNAME='root', DB_PASSWORD='', CACHE_STORE='array', SESSION_DRIVER='array', QUEUE_CONNECTION='sync',
           LOG_CHANNEL='single', BCRYPT_ROUNDS='4', REVIEW_REPO=str(repo), REVIEW_ROOT=str(root))
container = 'laravel13idc-review-identity-cms-' + uuid.uuid4().hex[:12]
print('Evidence directory: ' + str(root), flush=True)

bootstrap = r'''<?php
declare(strict_types=1);
require getenv('REVIEW_REPO').'/vendor/autoload.php';
$app = require getenv('REVIEW_REPO').'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'backend_review_only'
    || config('database.connections.mysql.unix_socket') !== getenv('REVIEW_ROOT').'/socket/mysqld.sock') {
    throw new RuntimeException('Only the private offline review database is authorized.');
}
function reviewRequire(bool $condition, string $message): void {
    if (! $condition) { throw new RuntimeException($message); }
}
'''

(root / 'bootstrap.php').write_text(bootstrap)
(root / 'probe.php').write_text(r'''<?php
$pdo = new PDO('mysql:unix_socket='.getenv('DB_SOCKET').';dbname=backend_review_only', 'root', '');
echo $pdo->query('SELECT VERSION()')->fetchColumn();
''')
(root / 'setup.php').write_text(r'''<?php
require __DIR__.'/bootstrap.php';
use App\Actions\ManageTicketAction;
use App\Actions\SaveCmsCategoryAction;
use App\Actions\SaveCmsArticleAction;
use App\Models\CmsCategory;
use App\Models\CmsArticle;
use App\Models\User;
use App\Support\CustomerEmailLookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Recreate only the two new tables with records already present, exercising both backfill chunks.
$migration = require getenv('REVIEW_REPO').'/database/migrations/2026_11_18_000003_add_cms_slug_aliases.php';
$migration->down();
for ($n = 1; $n <= 105; $n++) {
    $category = CmsCategory::create(['type' => $n % 2 === 0 ? 'news' : 'help', 'slug' => 'existing-category-'.$n,
        'name' => 'Pre-migration category '.$n, 'is_hidden' => $n % 3 === 0]);
    CmsArticle::create(['cms_category_id' => $category->id, 'slug' => 'existing-article-'.$n, 'title' => 'Pre-migration article',
        'body' => 'Historical content', 'content_format' => 'markdown', 'is_published' => $n % 3 !== 0, 'published_at' => now()]);
}
$migration->up();
reviewRequire(DB::table('cms_category_slug_aliases')->count() === 105, 'Category backfill count');
reviewRequire(DB::table('cms_article_slug_aliases')->count() === 105, 'Article backfill count');
reviewRequire(DB::table('cms_category_slug_aliases')->where('slug', 'existing-category-105')->value('cms_category_id') === $category->id, 'Last category backfill chunk');
reviewRequire(DB::table('cms_article_slug_aliases')->where('slug', 'existing-article-105')->value('cms_category_id') === $category->id, 'Last article backfill chunk');
$evidence = ['migration_backfill' => ['categories' => 105, 'articles' => 105, 'includes_hidden_and_draft' => true]];

$password = password_hash('Isolated-review-only', PASSWORD_BCRYPT, ['cost' => 4]);
for ($batch = 0; $batch < 10; $batch++) {
    $users = [];
    for ($n = 0; $n < 100; $n++) {
        $users[] = ['name' => 'Review fixture', 'email' => 'bulk-'.($batch * 100 + $n).'@example.test', 'password' => $password,
            'created_at' => now(), 'updated_at' => now()];
    }
    DB::table('users')->insert($users);
}
$target = User::factory()->create(['email' => 'Recovery@Example.Test']);
$other = User::factory()->create(['email' => 'Unrelated@Example.Test']);
$lookup = app(CustomerEmailLookup::class);
$query = $lookup->matching('recovery@example.test');
$evidence['email_index'] = array_map(fn ($row) => (array) $row, DB::select('EXPLAIN '.$query->toSql(), $query->getBindings()));
reviewRequire($evidence['email_index'][0]['key'] === 'users_email_unique', 'Email lookup must use existing unique index');
reviewRequire((int) $evidence['email_index'][0]['rows'] === 1, 'Indexed email lookup must inspect one candidate');
$evidence['old_lower_scan'] = array_map(fn ($row) => (array) $row, DB::select('EXPLAIN SELECT id FROM users WHERE LOWER(email) = ? AND deleted_at IS NULL', ['recovery@example.test']));
$peer = new PDO('mysql:unix_socket='.getenv('DB_SOCKET').';dbname=backend_review_only;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$peer->exec('SET SESSION innodb_lock_wait_timeout = 1');
DB::enableQueryLog();
DB::beginTransaction();
try {
    $locked = $lookup->lockUnique('RECOVERY@example.test');
    reviewRequire($locked?->id === $target->id, 'Recovery must lock only the matching identity');
    $evidence['email_lock_queries'] = DB::getQueryLog();
    $started = microtime(true);
    $peer->prepare('UPDATE users SET name = ? WHERE id = ?')->execute(['Unrelated update succeeds', $other->id]);
    $evidence['unrelated_update_ms'] = round((microtime(true) - $started) * 1000, 2);
    $started = microtime(true);
    try {
        $peer->prepare('UPDATE users SET name = ? WHERE id = ?')->execute(['Must remain locked', $target->id]);
        throw new RuntimeException('Matching identity was not locked');
    } catch (PDOException $error) {
        reviewRequire((int) $error->errorInfo[1] === 1205, 'Matching identity must encounter lock timeout');
        $evidence['matched_update'] = ['mysql_error' => (int) $error->errorInfo[1], 'elapsed_ms' => round((microtime(true) - $started) * 1000, 2)];
    }
} finally {
    DB::rollBack();
    DB::disableQueryLog();
}
$body = str_repeat('😀', 16383).'abc';
$ticket = app(ManageTicketAction::class)->create($other, ['subject' => 'Exact TEXT boundary', 'body' => $body]);
$row = DB::selectOne('SELECT OCTET_LENGTH(body) AS bytes, CHAR_LENGTH(body) AS characters, body FROM ticket_replies WHERE ticket_id = ?', [$ticket->id]);
reviewRequire((int) $row->bytes === 65535 && $row->body === $body, 'Exact 65535-byte ticket must be stored intact');
$evidence['ticket_text'] = ['bytes' => (int) $row->bytes, 'characters' => (int) $row->characters, 'exact_match' => true];
$before = DB::table('ticket_replies')->count();
try {
    app(ManageTicketAction::class)->create($other, ['subject' => 'One byte too large', 'body' => str_repeat('😀', 16384)]);
    throw new RuntimeException('65536-byte ticket must reject');
} catch (ValidationException $error) {
    reviewRequire(isset($error->errors()['body']), 'Oversized ticket requires body error');
    reviewRequire(DB::table('ticket_replies')->count() === $before, 'Rejected oversized ticket wrote history');
    $evidence['ticket_text']['65536_byte_rejected_without_write'] = true;
}
$categories = [];
foreach (['a', 'b'] as $slot) {
    $categories[$slot] = app(SaveCmsCategoryAction::class)->execute(['type' => 'help', 'slug' => 'race-category-'.$slot, 'name' => 'Race '.$slot], 'Isolated concurrency fixture')->id;
}
$shared = app(SaveCmsCategoryAction::class)->execute(['type' => 'help', 'slug' => 'race-articles', 'name' => 'Article races'], 'Isolated concurrency fixture');
$articles = [];
foreach (['a', 'b'] as $slot) {
    $articles[$slot] = app(SaveCmsArticleAction::class)->execute($shared, ['slug' => 'race-article-'.$slot, 'title' => 'Race '.$slot,
        'body' => 'Public documentation', 'content_format' => 'markdown', 'is_published' => true, 'published_at' => now()->subMinute()], 'Isolated concurrency fixture')->id;
}
$evidence['fixtures'] = ['categories' => $categories, 'articles' => $articles, 'article_category_id' => $shared->id];
file_put_contents(getenv('REVIEW_ROOT').'/setup.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
''')
(root / 'worker.php').write_text(r'''<?php
require __DIR__.'/bootstrap.php';
use App\Actions\SaveCmsCategoryAction;
use App\Actions\SaveCmsArticleAction;
use App\Models\CmsArticle;
use App\Models\CmsCategory;
use Illuminate\Validation\ValidationException;
[$script, $kind, $id, $slot] = $argv;
file_put_contents(__DIR__.'/'.$kind.'-'.$slot.'.ready', 'ready');
$deadline = microtime(true) + 20;
while (! file_exists(__DIR__.'/'.$kind.'.start')) {
    if (microtime(true) > $deadline) { throw new RuntimeException('Concurrent worker barrier timed out'); }
    usleep(10000);
}
$started = microtime(true);
try {
    if ($kind === 'category') {
        app(SaveCmsCategoryAction::class)->execute(['type' => 'help', 'slug' => 'contended-category', 'name' => 'Concurrent winner'], 'Concurrent category rename', categoryId: (int) $id);
    } else {
        $article = CmsArticle::findOrFail($id);
        app(SaveCmsArticleAction::class)->execute(CmsCategory::findOrFail($article->cms_category_id), ['slug' => 'contended-article', 'title' => 'Concurrent winner',
            'body' => 'Concurrent content', 'content_format' => 'markdown', 'is_published' => true, 'published_at' => now()->subMinute()], 'Concurrent article rename', articleId: (int) $id);
    }
    $result = ['outcome' => 'saved'];
} catch (ValidationException $error) {
    reviewRequire(isset($error->errors()['slug']), 'Concurrent loser must receive a slug validation error');
    $result = ['outcome' => 'rejected', 'fields' => array_keys($error->errors())];
}
$result += ['kind' => $kind, 'slot' => $slot, 'id' => (int) $id, 'elapsed_ms' => round((microtime(true) - $started) * 1000, 2)];
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
''')
(root / 'final-check.php').write_text(r'''<?php
require __DIR__.'/bootstrap.php';
use App\Actions\SaveCmsCategoryAction;
use App\Actions\SaveCmsArticleAction;
use App\Models\CmsCategory;
use App\Models\CmsArticle;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
$fixtures = json_decode(file_get_contents(__DIR__.'/setup.json'), true, flags: JSON_THROW_ON_ERROR)['fixtures'];
$result = [];
foreach (['category', 'article'] as $kind) {
    $model = $kind === 'category' ? CmsCategory::class : CmsArticle::class;
    $table = $kind === 'category' ? 'cms_category_slug_aliases' : 'cms_article_slug_aliases';
    $ownerColumn = 'cms_'.$kind.'_id';
    $ids = $fixtures[$kind === 'category' ? 'categories' : 'articles'];
    $winners = $model::whereIn('id', array_values($ids))->where('slug', 'contended-'.$kind)->get();
    reviewRequire($winners->count() === 1, 'Exactly one object may claim a contended '.$kind.' name');
    $winner = $winners->sole();
    $winnerSlot = array_search($winner->id, $ids, true);
    $loserId = $ids[$winnerSlot === 'a' ? 'b' : 'a'];
    $oldName = 'race-'.$kind.'-'.$winnerSlot;
    foreach ($ids as $slot => $id) {
        reviewRequire((int) DB::table($table)->where($ownerColumn, $id)->where('slug', 'race-'.$kind.'-'.$slot)->value($ownerColumn) === $id, 'Original name ownership changed');
    }
    try {
        if ($kind === 'category') {
            app(SaveCmsCategoryAction::class)->execute(['type' => 'help', 'slug' => $oldName, 'name' => 'Forbidden alias theft'], 'Historical category name theft', categoryId: $loserId);
        } else {
            app(SaveCmsArticleAction::class)->execute(CmsCategory::findOrFail($fixtures['article_category_id']), ['slug' => $oldName, 'title' => 'Forbidden alias theft',
                'body' => 'Not saved', 'content_format' => 'markdown', 'is_published' => true], 'Historical article name theft', articleId: $loserId);
        }
        throw new RuntimeException('Another object stole a historical name');
    } catch (ValidationException $error) {
        reviewRequire(isset($error->errors()['slug']), 'Historical name claim must fail on slug');
    }
    $duplicate = $kind === 'category' ? ['type' => 'help', 'cms_category_id' => $loserId] : ['cms_category_id' => $fixtures['article_category_id'], 'cms_article_id' => $loserId];
    try {
        DB::transaction(fn () => DB::table($table)->insert($duplicate + ['slug' => 'contended-'.$kind, 'created_at' => now(), 'updated_at' => now()]));
        throw new RuntimeException('Alias namespace lacks database uniqueness');
    } catch (UniqueConstraintViolationException $error) {
        reviewRequire((int) $error->errorInfo[1] === 1062, 'Namespace index must reject duplicate with MySQL 1062');
        $result[$kind] = ['one_winner_id' => $winner->id, 'historical_name_steal_rejected' => true, 'unique_index_error' => (int) $error->errorInfo[1]];
    }
}
file_put_contents(__DIR__.'/final-check.json', json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
''')


def run_logged(command, name, timeout=180):
    result = subprocess.run(command, env=env, cwd=repo, capture_output=True, text=True, timeout=timeout)
    (root / name).write_text(result.stdout + result.stderr)
    print(result.stdout + result.stderr, end='', flush=True)
    if result.returncode:
        raise RuntimeError('Verification failed; see ' + str(root / name))
    return result.stdout


try:
    subprocess.run(['docker', 'run', '--detach', '--pull=never', '--name', container,
                    '--label', 'laravel13idc.review=identity-cms', '--network', 'none',
                    '--tmpfs', '/var/lib/mysql:rw,size=768m',
                    '--mount', 'type=bind,source=' + str(socket_dir) + ',target=/var/run/mysqld',
                    '--env', 'MYSQL_ALLOW_EMPTY_PASSWORD=yes', '--env', 'MYSQL_DATABASE=backend_review_only',
                    image, '--performance-schema=OFF', '--innodb-buffer-pool-size=32M', '--mysqlx=OFF', '--skip-log-bin'],
                   capture_output=True, check=True, timeout=20)
    deadline = time.monotonic() + 90
    while True:
        ready = subprocess.run(['php', str(root / 'probe.php')], env=env, capture_output=True, text=True, timeout=5)
        stable = subprocess.run(['docker', 'exec', container, 'cat', '/proc/1/comm'], capture_output=True, text=True, timeout=5)
        if ready.returncode == 0 and stable.stdout.strip() == 'mysqld':
            break
        if time.monotonic() >= deadline:
            raise RuntimeError('Private MySQL did not become ready; no existing database was used')
        time.sleep(0.5)
    print('Connected to dedicated offline MySQL ' + ready.stdout.strip(), flush=True)
    run_logged(['php', 'vendor/bin/pest', '--compact', '--colors=never', 'tests/Feature/ReviewCmsSlugAliasesTest.php',
                'tests/Feature/ReviewIdentitySupportFixesTest.php'], 'pest.log')
    run_logged(['php', 'artisan', 'migrate:fresh', '--force', '--no-interaction'], 'isolated-migration.log')
    run_logged(['php', str(root / 'setup.php')], 'setup.log')
    fixtures = json.loads((root / 'setup.json').read_text())['fixtures']
    concurrency = {}
    for kind, plural in [('category', 'categories'), ('article', 'articles')]:
        workers = {slot: subprocess.Popen(['php', str(root / 'worker.php'), kind, str(identity), slot],
                                         cwd=repo, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
                   for slot, identity in fixtures[plural].items()}
        deadline = time.monotonic() + 15
        while not all((root / (kind + '-' + slot + '.ready')).exists() for slot in workers):
            if time.monotonic() >= deadline or any(worker.poll() is not None for worker in workers.values()):
                raise RuntimeError('Concurrent worker did not reach its barrier')
            time.sleep(0.01)
        (root / (kind + '.start')).write_text('start')
        results = []
        for slot, worker in workers.items():
            output, error = worker.communicate(timeout=30)
            (root / (kind + '-' + slot + '.log')).write_text(output + error)
            if worker.returncode:
                raise RuntimeError('Concurrent rename failed; see ' + str(root / (kind + '-' + slot + '.log')))
            results.append(json.loads(output))
        if sorted(result['outcome'] for result in results) != ['rejected', 'saved']:
            raise RuntimeError('Expected exactly one successful ' + kind + ' rename: ' + repr(results))
        concurrency[kind] = results
    (root / 'concurrency.json').write_text(json.dumps(concurrency, indent=2))
    print(json.dumps(concurrency, indent=2), flush=True)
    run_logged(['php', str(root / 'final-check.php')], 'final-check.log')
    print('PASS: new MySQL regressions, backfill, indexed email lookup and scoped locks, TEXT bytes, concurrent slug claims.', flush=True)
finally:
    logs = subprocess.run(['docker', 'logs', container], capture_output=True, text=True, timeout=10)
    (root / 'mysql-container.log').write_text(logs.stdout + logs.stderr)
    subprocess.run(['docker', 'rm', '--force', container], capture_output=True, timeout=20)
    print('Dedicated container removed; retained evidence: ' + str(root), flush=True)
