<?php

declare(strict_types=1);

use App\Actions\ProvisionInstanceAction;
use App\Drivers\SelfHosted\RedemptionCodeDriver;
use App\Enums\InstanceStatus;
use App\Models\Instance;
use App\Models\Product;
use App\Models\RedemptionCode;
use Illuminate\Support\Facades\DB;

it('delivers the exact same encrypted card key idempotently on repeated redemption provisioning', function (): void {
    $product = Product::factory()->create(['driver_type' => 'redemption']);
    $code1 = RedemptionCode::factory()->create(['product_id' => $product->id, 'status' => 'available']);
    RedemptionCode::factory()->create(['product_id' => $product->id, 'status' => 'available']);
    $instance = Instance::factory()->create(['product_id' => $product->id, 'driver_type' => 'redemption']);

    $provision = app(ProvisionInstanceAction::class);
    $first = $provision->execute($instance, []);
    $second = $provision->execute($instance->fresh(), []);

    expect($first->success)->toBeTrue()
        ->and($second->credentials['card_key'])->toBe($first->credentials['card_key'])
        ->and(RedemptionCode::where('instance_id', $instance->id)->count())->toBe(1)
        ->and($code1->fresh()->status)->toBe('sold')
        ->and($instance->fresh()->encrypted_credentials['card_key'])->toBe($first->credentials['card_key']);
});

it('encrypts inventory and delivered credentials while keeping the driver read-only', function (): void {
    $instance = Instance::factory()->create();
    $code = RedemptionCode::factory()->create([
        'product_id' => $instance->product_id, 'code' => 'SECRET-CARD', 'instance_id' => $instance->id,
        'status' => 'reserved', 'reserved_until' => now()->addMinutes(15),
    ]);
    $before = $code->fresh()->getRawOriginal();
    $result = app(RedemptionCodeDriver::class)->provision($instance, []);
    expect($result->credentials['card_key'])->toBe('SECRET-CARD')
        ->and($code->fresh()->getRawOriginal())->toBe($before)
        ->and($instance->fresh()->encrypted_credentials)->toBeNull();

    app(ProvisionInstanceAction::class)->execute($instance, []);
    expect(DB::table('redemption_codes')->value('code'))->not->toContain('SECRET-CARD')
        ->and(DB::table('instances')->value('encrypted_credentials'))->not->toContain('SECRET-CARD')
        ->and($instance->fresh()->status)->toBe(InstanceStatus::ACTIVE);
    $this->assertDatabaseHas('audit_logs', ['action' => 'instance.provision', 'subject_id' => $instance->id]);
});

it('does not consume another card when a reservation already exists or the instance is cancelled', function (): void {
    $instance = Instance::factory()->create();
    $code = RedemptionCode::factory()->create([
        'product_id' => $instance->product_id, 'instance_id' => $instance->id,
        'status' => 'reserved', 'reserved_until' => now()->subMinute(),
    ]);
    $spare = RedemptionCode::factory()->create(['product_id' => $instance->product_id]);
    expect(app(ProvisionInstanceAction::class)->execute($instance, [])->success)->toBeTrue()
        ->and($spare->fresh()->status)->toBe('available')
        ->and($code->fresh()->status)->toBe('sold');
    $instance->update(['status' => InstanceStatus::CANCELLED]);
    expect(app(ProvisionInstanceAction::class)->execute($instance, [])->success)->toBeFalse()
        ->and($code->fresh()->status)->toBe('sold');
});

it('reports empty stock as retryable without activating an instance', function (): void {
    $instance = Instance::factory()->create();
    $result = app(ProvisionInstanceAction::class)->execute($instance, []);
    expect($result->success)->toBeFalse()->and($result->retryable)->toBeTrue()
        ->and($instance->fresh()->status)->toBe(InstanceStatus::PENDING);
});
