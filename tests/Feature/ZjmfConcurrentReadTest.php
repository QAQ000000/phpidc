<?php

declare(strict_types=1);

use App\Actions\CallUpstreamAction;
use App\Drivers\Upstream\ZjmfClient;
use App\Models\UpstreamApi;
use App\Services\UpstreamOperationBuffer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function concurrentZjmfClient(): ZjmfClient
{
    return new ZjmfClient(UpstreamApi::factory()->create(['base_url' => 'https://parallel.example.invalid',
        'encrypted_secret' => 'PRIVATE-API-PASSWORD']), app(UpstreamOperationBuffer::class));
}

it('shares authentication across concurrent reads and preserves decimal decoding and safe individual traces', function (): void {
    $client = concurrentZjmfClient();
    $level = DB::transactionLevel();
    Http::preventStrayRequests();
    Http::fake(function ($request) use ($level) {
        expect(DB::transactionLevel())->toBe($level);
        if (str_ends_with($request->url(), '/zjmf_api_login')) {
            return Http::response(['status' => 200, 'jwt' => 'PRIVATE-READ-JWT']);
        }
        expect($request->header('Authorization'))->toBe(['Bearer PRIVATE-READ-JWT'])
            ->and($request->header('Content-Type'))->toBe(['application/x-www-form-urlencoded'])
            ->and($request['is_api'])->toBe(1);

        return Http::response('{"status":200,"total":0.10,"password":"PRIVATE-HOST-PASSWORD"}');
    });
    $results = app(CallUpstreamAction::class)->execute(fn () => $client->readBatch([
        'user' => ['method' => 'GET', 'path' => '/user_info'],
        'stock' => ['method' => 'GET', 'path' => '/cart/stock_control', 'params' => ['pid' => '17']],
    ]));
    expect(array_keys($results))->toBe(['user', 'stock'])
        ->and($results['user']['total'])->toBe('0.10')->and($results['stock']['total'])->toBe('0.10');
    Http::assertSentCount(3);
    $logs = DB::table('upstream_operations')->get();
    expect($logs)->toHaveCount(3)->and($logs->pluck('correlation_key')->unique())->toHaveCount(1)
        ->and($logs->pluck('outcome')->unique()->all())->toBe(['ok'])
        ->and($logs->pluck('request_payload')->implode(' '))->not->toContain('PRIVATE-API-PASSWORD', 'PRIVATE-READ-JWT')
        ->and($logs->pluck('response_body')->implode(' '))->not->toContain('PRIVATE-HOST-PASSWORD', 'PRIVATE-READ-JWT');
});

it('refreshes one rejected batch token once and only replays rejected reads', function (): void {
    $client = concurrentZjmfClient();
    $logins = 0;
    Http::preventStrayRequests();
    Http::fake(function ($request) use (&$logins) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/zjmf_api_login') {
            return Http::response(['status' => 200, 'jwt' => 'jwt-'.++$logins]);
        }
        if ($path === '/user_info') {
            return Http::response(['status' => 200, 'user' => ['currency' => 1]]);
        }
        if ($request->header('Authorization') === ['Bearer jwt-1'] || $path === '/provision/chart/4253') {
            return Http::response(['status' => 405]);
        }

        return Http::response(['status' => 200, 'data' => ['product' => ['qty' => 1]]]);
    });
    $results = app(CallUpstreamAction::class)->execute(fn () => $client->readBatch([
        'user' => ['method' => 'GET', 'path' => '/user_info'],
        'stock' => ['method' => 'GET', 'path' => '/cart/stock_control', 'params' => ['pid' => '17']],
        'chart' => ['method' => 'GET', 'path' => '/provision/chart/4253', 'params' => ['type' => 'cpu']],
    ]));
    expect(array_keys($results))->toBe(['user', 'stock', 'chart'])
        ->and($results['user']['status'])->toBe(200)->and($results['stock']['status'])->toBe(200)
        ->and($results['chart']['status'])->toBe(405)->and($logins)->toBe(2);
    Http::assertSentCount(7);
    expect(DB::table('upstream_operations')->where('path', '/user_info')->count())->toBe(1)
        ->and(DB::table('upstream_operations')->where('path', '/cart/stock_control')->count())->toBe(2)
        ->and(DB::table('upstream_operations')->where('path', '/provision/chart/4253')->count())->toBe(2);
});

it('isolates connection non-json and non-200 failures without retrying or calling them ambiguous writes', function (): void {
    $client = concurrentZjmfClient();
    $attempts = 0;
    Http::preventStrayRequests();
    Http::fake(function ($request) use (&$attempts) {
        $attempts++;

        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/zjmf_api_login' => Http::response(['status' => 200, 'jwt' => 'private-jwt']),
            '/user_info' => Http::response(['status' => 200, 'user' => ['currency' => 1]]),
            '/cart/stock_control' => Http::response(['status' => 200], 502),
            '/provision/chart/4253' => Http::response('<html>not-json</html>'),
            '/provision/default' => Http::failedConnection('PRIVATE-CONNECTION-DETAILS'),
            default => throw new RuntimeException('Unexpected test request.'),
        };
    });
    $results = app(CallUpstreamAction::class)->execute(fn () => $client->readBatch([
        'user' => ['method' => 'GET', 'path' => '/user_info'],
        'stock' => ['method' => 'GET', 'path' => '/cart/stock_control'],
        'chart' => ['method' => 'GET', 'path' => '/provision/chart/4253'],
        'power' => ['method' => 'POST', 'path' => '/provision/default', 'params' => ['id' => '4253', 'func' => 'status']],
    ]));
    expect($results['user']['status'])->toBe(200);
    foreach (['stock', 'chart', 'power'] as $key) {
        expect($results[$key]['status'])->toBe(400)->and($results[$key]['_transport_failure'])->toBeTrue();
    }
    // Laravel records response pairs, excluding a failed connection; count every attempted exchange here.
    expect($attempts)->toBe(5)->and(DB::table('upstream_operations')->count())->toBe(5)
        ->and(DB::table('upstream_operations')->where('outcome', 'failed')->count())->toBe(3)
        ->and(DB::table('upstream_operations')->where('outcome', 'ambiguous')->count())->toBe(0)
        ->and(DB::table('upstream_operations')->pluck('response_body')->implode(' '))->not->toContain('PRIVATE-CONNECTION-DETAILS');
});

it('does not send protected reads after a shared authentication failure', function (): void {
    $client = concurrentZjmfClient();
    Http::preventStrayRequests();
    Http::fake(['*/zjmf_api_login' => Http::response(['status' => 400])]);
    $results = app(CallUpstreamAction::class)->execute(fn () => $client->readBatch([
        'user' => ['method' => 'GET', 'path' => '/user_info'],
        'stock' => ['method' => 'GET', 'path' => '/cart/stock_control'],
    ]));
    expect($results['user']['_transport_failure'])->toBeTrue()->and($results['stock']['_transport_failure'])->toBeTrue();
    Http::assertSentCount(1);
});

it('rejects a whole mixed batch containing any write or unverified path before authentication', function (array $invalid): void {
    $client = concurrentZjmfClient();
    Http::fake();
    expect(fn () => $client->readBatch(['valid' => ['method' => 'GET', 'path' => '/user_info'], 'invalid' => $invalid]))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with([
    'payment' => [['method' => 'POST', 'path' => '/apply_credit']],
    'cart creation' => [['method' => 'POST', 'path' => '/cart/add_to_shop']],
    'power write' => [['method' => 'POST', 'path' => '/provision/default', 'params' => ['func' => 'on']]],
    'vnc' => [['method' => 'POST', 'path' => '/provision/default', 'params' => ['func' => 'vnc']]],
    'unverified GET' => [['method' => 'GET', 'path' => '/apply_credit']],
    'redirect path' => [['method' => 'GET', 'path' => '/provision/chart/4253/../default']],
]);

it('propagates an unexpected concurrent failure instead of reporting unavailable evidence', function (): void {
    $client = concurrentZjmfClient();
    Http::preventStrayRequests();
    Http::fake(function ($request) {
        return match (parse_url($request->url(), PHP_URL_PATH)) {
            '/zjmf_api_login' => Http::response(['status' => 200, 'jwt' => 'jwt']),
            '/user_info' => throw new LogicException('Unexpected test defect.'),
            default => Http::response(['status' => 200]),
        };
    });
    expect(fn () => app(CallUpstreamAction::class)->execute(fn () => $client->readBatch([
        'user' => ['method' => 'GET', 'path' => '/user_info'],
    ])))->toThrow(LogicException::class, 'Unexpected test defect.');
});
