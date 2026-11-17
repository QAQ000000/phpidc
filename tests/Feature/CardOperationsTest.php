<?php

declare(strict_types=1);

use App\Actions\ConfigureEpayAction;
use App\Actions\CreateLocalCardProductAction;
use App\Models\Product;
use App\Models\RedemptionCode;
use App\Services\SettingService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);
beforeEach(fn () => $this->installApplicationForTest());

it('creates a local card product and imports encrypted stock idempotently through audited commands', function (): void {
    $this->artisan('cards:create-product', ['name' => 'Local card', 'price' => '12.34', '--reason' => 'Initial catalog setup'])
        ->assertSuccessful();
    $product = Product::sole();
    $file = storage_path('test-keys.txt');
    file_put_contents($file, "CARD-ONE\nCARD-TWO\nCARD-ONE\n");
    $this->artisan('cards:import', ['product' => $product->id, 'file' => $file, '--reason' => 'Initial inventory'])
        ->expectsOutput('Imported 2 new cards.')->assertSuccessful();
    $this->artisan('cards:import', ['product' => $product->id, 'file' => $file, '--reason' => 'Repeat inventory check'])
        ->expectsOutput('Imported 0 new cards.')->assertSuccessful();
    expect(RedemptionCode::count())->toBe(2)
        ->and($product->local_prices['onetime']['price'])->toBe('12.34')
        ->and(DB::table('redemption_codes')->value('code'))->not->toContain('CARD-ONE');
    $this->assertDatabaseHas('audit_logs', ['action' => 'cards.import', 'reason' => 'Initial inventory', 'actor_type' => 'system']);
    expect(DB::table('audit_logs')->pluck('after')->implode(' '))->not->toContain('CARD-ONE');
});

it('requires operator reasons and encrypts gateway keys without putting them in audit logs', function (): void {
    expect(fn () => app(CreateLocalCardProductAction::class)->execute('Card', '10.00', '0.00', 'CNY', ''))
        ->toThrow(DomainException::class);
    $settings = app(SettingService::class);
    expect($settings->get('payment.epay_key'))->toBeNull();
    app(ConfigureEpayAction::class)->execute('https://epay.test', 'merchant-1', 'SECRET-GATEWAY-KEY', 'Payment setup');
    expect($settings->get('payment.epay_key'))->toBe('SECRET-GATEWAY-KEY')
        ->and(DB::table('system_settings')->where('key', 'epay_key')->value('value'))->not->toContain('SECRET-GATEWAY-KEY')
        ->and(DB::table('audit_logs')->value('after'))->not->toContain('SECRET-GATEWAY-KEY');
});

it('registers all recovery schedules with overlap and single-server protection', function (): void {
    $events = app(Schedule::class)->events();
    $commands = [];
    foreach ($events as $event) {
        if (str_contains($event->command ?? '', 'invoices:expire') || str_contains($event->command ?? '', 'fulfillment:dispatch-stale') || str_contains($event->command ?? '', 'cards:reap-reservations')) {
            expect($event->withoutOverlapping)->toBeTrue()->and($event->onOneServer)->toBeTrue()->and($event->expression)->toBe('* * * * *');
            $commands[] = $event->command;
        }
    }
    expect($commands)->toHaveCount(3);
    $this->artisan('invoices:expire')->assertSuccessful();
    $this->artisan('fulfillment:dispatch-stale')->assertSuccessful();
    $this->artisan('cards:reap-reservations')->assertSuccessful();
});
