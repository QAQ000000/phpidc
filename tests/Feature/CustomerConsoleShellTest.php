<?php

declare(strict_types=1);

use App\Actions\ConfigureIdentityAction;
use App\Enums\DriverType;
use App\Models\Product;
use App\Models\UpstreamApi;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Queue::fake();
    Http::preventStrayRequests();
});

afterEach(function (): void {
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

function consoleShellXpath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

    return new DOMXPath($document);
}

it('gives guests a usable authentication form without the private console navigation', function (string $path): void {
    app(ConfigureIdentityAction::class)->execute(['mail.transport' => 'smtp', 'mail.host' => 'smtp.example.test',
        'mail.port' => 465, 'mail.from_address' => 'console@example.test'], 'Isolated guest form display');
    $response = $this->get($path)->assertOk();
    $xpath = consoleShellXpath($response->getContent());
    expect($xpath->query('//aside[@id="console-sidebar"]'))->toHaveCount(0)
        ->and($xpath->query('//main[@id="console-main"]//form[@method="post"]//input[@name="_token"]'))->toHaveCount(1)
        ->and($xpath->query('//link[contains(@href,"customer-console.css")]'))->toHaveCount(1);
    $this->assertDatabaseCount('invoices', 0);
})->with(['/login', '/register', '/forgot-password']);

it('keeps one client navigation and highlights the actual owner page', function (string $path, string $active): void {
    $response = $this->actingAs(User::factory()->create())->get($path)->assertOk();
    $xpath = consoleShellXpath($response->getContent());
    expect($xpath->query('//aside[@id="console-sidebar"]'))->toHaveCount(1)
        ->and($xpath->query('//nav[@aria-label="客户功能"]'))->toHaveCount(1)
        ->and($xpath->query('//nav[@aria-label="客户功能"]//a[@aria-current="page"]'))->toHaveCount(1)
        ->and($xpath->query('//nav[@aria-label="客户功能"]//a[@aria-current="page"]')->item(0)->getAttribute('href'))->toBe($active)
        ->and($xpath->query('//main//aside[contains(@class,"sidebar")]'))->toHaveCount(0)
        ->and($xpath->query('//form[@action="/logout"][@method="post"]//input[@name="_token"]'))->toHaveCount(1);
})->with([
    ['/console', '/console'],
    ['/console/invoices', '/console/invoices'],
    ['/console/instances', '/console/instances'],
    ['/console/wallet', '/console/wallet'],
    ['/console/account', '/console/account'],
    ['/console/tickets', '/console/tickets'],
    ['/downloads', '/downloads'],
]);

it('retains direct local purchases and classic configuration without loading a supplier on the dashboard', function (): void {
    $owner = User::factory()->create(['name' => '<script>customerName()</script>']);
    $local = Product::factory()->create(['name' => 'Local card product', 'local_prices' => ['onetime' => ['price' => '10.10', 'setup_fee' => '0.20', 'enabled' => true]],
        'provisioning_rules' => ['hostname_mode' => 'custom', 'password_min_len' => 8, 'private_supplier_rule' => 'PRIVATE-RULE']]);
    $account = UpstreamApi::factory()->create(['encrypted_secret' => 'PRIVATE-UPSTREAM-KEY']);
    $classic = Product::factory()->create(['driver_type' => DriverType::ZJMF_API, 'upstream_api_id' => $account->id]);
    Product::factory()->hidden()->create(['name' => 'PRIVATE-HIDDEN-PRODUCT']);
    $response = $this->actingAs($owner)->get('/console')->assertOk()->assertSee('&lt;script&gt;customerName()&lt;/script&gt;', false)
        ->assertDontSee('<script>customerName()</script>', false)->assertDontSee('PRIVATE-')->assertSee('10.10')->assertSee('0.20')
        ->assertSee('本地预估')->assertSee('/console/products/'.$classic->id.'/configure', false);
    $xpath = consoleShellXpath($response->getContent());
    $form = '//form[@method="post"][@action="/checkout"][input[@name="product_id"][@value="'.$local->id.'"]]';
    expect($xpath->query($form.'//input[@name="_token"]'))->toHaveCount(1)
        ->and($xpath->query($form.'//input[@name="billing_cycle"][@value="onetime"]'))->toHaveCount(1)
        ->and($xpath->query($form.'//input[@name="hostname"][@required]'))->toHaveCount(1)
        ->and($xpath->query($form.'//input[@name="password"][@type="password"][@required]'))->toHaveCount(1)
        ->and($xpath->query($form.'//input[@name="use_credit"][@value="1"]'))->toHaveCount(1)
        ->and($xpath->query($form.'//input[@name="coupon_code"]'))->toHaveCount(1)
        ->and($xpath->query($form.'//select[@name="gateway"]'))->toHaveCount(1)
        ->and(Str::isUlid($xpath->query($form.'//input[@name="request_id"]')->item(0)->getAttribute('value')))->toBeTrue();
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
});
