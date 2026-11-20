<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\CreateComboInvoiceAction;
use App\Actions\RecordOfflineInvoiceReceiptAction;
use App\Actions\ReencryptSecretsAction;
use App\Enums\BillingCycle;
use App\Models\FulfillmentTask;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\OfflineInvoiceReceipt;
use App\Models\Product;
use App\Models\RedemptionCode;
use App\Models\UpstreamApi;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\InstallationState;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    File::ensureDirectoryExists(storage_path('framework'));
    $this->rotationOldKey = config('app.key');
    $this->rotationNewKey = 'base64:'.base64_encode(random_bytes(32));
    $state = app(InstallationState::class);
    $state->write('installed.lock', $state->seal(['format' => 1, 'admin_id' => 1], $this->rotationOldKey));
    File::put(storage_path('framework/down'), '{}');
    config(['cache.default' => 'array']);
});

it('rotates every durable ciphertext and card HMAC without changing business state or balances', function (): void {
    $account = UpstreamApi::factory()->create();
    $instance = Instance::factory()->create(['encrypted_credentials' => ['password' => 'instance-password']]);
    $invoice = Invoice::factory()->create(['line_items' => ['purchase' => ['encrypted_params' => Crypt::encryptString('purchase')],
        'encrypted_operation' => Crypt::encryptString('invoice-operation'), 'price' => '12.00']]);
    $card = RedemptionCode::factory()->create(['code' => 'plain-card']);
    $task = FulfillmentTask::factory()->create(['payload' => ['encrypted_purchase' => Crypt::encryptString('task-purchase'),
        'encrypted_operation' => Crypt::encryptString('task-operation'), 'remote_delivery' => ['encrypted_result' => Crypt::encryptString('issued-card')]]]);
    DB::table('system_settings')->insert(['group' => 'payment', 'key' => 'epay_key', 'is_encrypted' => true, 'value' => Crypt::encryptString('payment-key')]);
    Cache::put('setting:payment:epay_key', ['value' => 'stale-ciphertext', 'is_encrypted' => true]);
    $jwtKey = 'zjmf:jwt:'.hash('sha256', $account->id.'|'.$account->base_url.'|'.$account->username.'|'.$account->encrypted_secret);
    Cache::put($jwtKey, 'old-encrypted-jwt');
    Cache::put('unrelated-key', 'preserve');
    $balances = DB::table('users')->orderBy('id')->get(['credit', 'frozen_credit', 'affiliate_balance'])->toJson();
    app(ReencryptSecretsAction::class)->execute($this->rotationOldKey, $this->rotationNewKey, 'Isolated key rotation', true);
    $new = app(InstallationState::class)->encrypter($this->rotationNewKey);
    expect($new->decryptString(DB::table('upstream_apis')->where('id', $account->id)->value('encrypted_secret')))->toBe('isolated-api-secret')
        ->and(json_decode($new->decryptString(DB::table('instances')->where('id', $instance->id)->value('encrypted_credentials')), true))->toBe(['password' => 'instance-password'])
        ->and($new->decryptString(DB::table('redemption_codes')->where('id', $card->id)->value('code')))->toBe('plain-card')
        ->and(DB::table('redemption_codes')->where('id', $card->id)->value('code_hash'))->toBe(hash_hmac('sha256', 'plain-card', $this->rotationNewKey));
    $items = json_decode(DB::table('invoices')->where('id', $invoice->id)->value('line_items'), true);
    $payload = json_decode(DB::table('fulfillment_tasks')->where('id', $task->id)->value('payload'), true);
    expect($new->decryptString($items['purchase']['encrypted_params']))->toBe('purchase')
        ->and($new->decryptString($items['encrypted_operation']))->toBe('invoice-operation')
        ->and($items['price'])->toBe('12.00')
        ->and($new->decryptString($payload['encrypted_purchase']))->toBe('task-purchase')
        ->and($new->decryptString($payload['encrypted_operation']))->toBe('task-operation')
        ->and($new->decryptString($payload['remote_delivery']['encrypted_result']))->toBe('issued-card')
        ->and($new->decryptString(DB::table('system_settings')->where('group', 'payment')->value('value')))->toBe('payment-key')
        ->and(Cache::has('setting:payment:epay_key'))->toBeFalse()->and(Cache::has($jwtKey))->toBeFalse()
        ->and(Cache::get('unrelated-key'))->toBe('preserve')
        ->and(DB::table('users')->orderBy('id')->get(['credit', 'frozen_credit', 'affiliate_balance'])->toJson())->toBe($balances)
        ->and(DB::table('financial_transactions')->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('action', 'security.reencrypt')->count())->toBe(1);
    app(InstallationState::class)->assertKey($this->rotationNewKey, requireSeal: true);
    expect(fn () => app(InstallationState::class)->assertKey($this->rotationOldKey))->toThrow(DecryptException::class);
});

it('rolls back all ciphertext and hashes on corrupt data and permits a repaired retry', function (): void {
    $card = RedemptionCode::factory()->create(['code' => 'preserve-card']);
    $before = DB::table('redemption_codes')->first();
    $task = FulfillmentTask::factory()->create(['payload' => ['encrypted_operation' => 'corrupt']]);
    expect(fn () => app(ReencryptSecretsAction::class)->execute($this->rotationOldKey, $this->rotationNewKey, 'Repair test', true))->toThrow(DecryptException::class);
    expect(DB::table('redemption_codes')->where('id', $card->id)->value('code'))->toBe($before->code)
        ->and(DB::table('redemption_codes')->where('id', $card->id)->value('code_hash'))->toBe($before->code_hash)
        ->and(DB::table('audit_logs')->where('action', 'security.reencrypt')->count())->toBe(0)
        ->and(is_file(storage_path('key-rotation.pending')))->toBeTrue();
    app(InstallationState::class)->assertKey($this->rotationOldKey, requireSeal: true);
    $task->update(['payload' => ['encrypted_operation' => Crypt::encryptString('repaired')]]);
    app(ReencryptSecretsAction::class)->execute($this->rotationOldKey, $this->rotationNewKey, 'Retry repaired data', true);
    app(InstallationState::class)->assertKey($this->rotationNewKey, requireSeal: true);
    expect(is_file(storage_path('key-rotation.pending')))->toBeFalse();
});

it('recovers a committed rotation whose installation lock publication failed without double rotation', function (): void {
    RedemptionCode::factory()->create(['code' => 'recovery-card']);
    $state = Mockery::mock(InstallationState::class)->makePartial();
    $state->shouldReceive('write')->with('installed.lock', Mockery::type('array'))->once()->andThrow(new RuntimeException('Simulated publish interruption'));
    $this->app->instance(InstallationState::class, $state);
    expect(fn () => app(ReencryptSecretsAction::class)->execute($this->rotationOldKey, $this->rotationNewKey, 'Interrupted rotation', true))->toThrow(RuntimeException::class, 'Simulated');
    $cipher = DB::table('redemption_codes')->value('code');
    $this->app->instance(InstallationState::class, new InstallationState);
    app(ReencryptSecretsAction::class)->execute($this->rotationOldKey, $this->rotationNewKey, 'Recover seal', true);
    expect(DB::table('redemption_codes')->value('code'))->toBe($cipher)
        ->and(DB::table('audit_logs')->where('action', 'security.reencrypt')->count())->toBe(1);
    app(InstallationState::class)->assertKey($this->rotationNewKey, requireSeal: true);
});

it('requires maintenance worker stoppage and a reason for rotation', function (): void {
    expect(fn () => app(ReencryptSecretsAction::class)->execute($this->rotationOldKey, $this->rotationNewKey, '', false))->toThrow(DomainException::class);
    File::delete(storage_path('framework/down'));
    expect(fn () => app(ReencryptSecretsAction::class)->execute($this->rotationOldKey, $this->rotationNewKey, 'Maintenance required', true))->toThrow(DomainException::class);
});

it('finishes interrupted journal cleanup with the newly configured sealed key', function (): void {
    $state = Mockery::mock(InstallationState::class)->makePartial();
    $state->shouldReceive('remove')->with('key-rotation.pending')->once()->andThrow(new RuntimeException('Simulated cleanup failure'));
    $this->app->instance(InstallationState::class, $state);
    expect(fn () => app(ReencryptSecretsAction::class)->execute($this->rotationOldKey, $this->rotationNewKey, 'Cleanup recovery', true))->toThrow(RuntimeException::class, 'Simulated');
    expect(is_file(storage_path('key-rotation.pending')))->toBeTrue();
    config(['app.key' => $this->rotationNewKey]);
    $this->app->instance(InstallationState::class, new InstallationState);
    app(ReencryptSecretsAction::class)->execute($this->rotationNewKey, $this->rotationNewKey, 'Finish cleanup', true);
    expect(is_file(storage_path('key-rotation.pending')))->toBeFalse()
        ->and(DB::table('audit_logs')->where('action', 'security.reencrypt')->count())->toBe(1);
});

it('refuses mismatched keys and unfinished rotations in provider boot before any database access', function (): void {
    DB::listen(fn () => throw new RuntimeException('Boot contacted database'));
    $provider = new AppServiceProvider($this->app);
    config(['app.key' => $this->rotationNewKey]);
    expect(fn () => $provider->boot())->toThrow(DecryptException::class);
    config(['app.key' => $this->rotationOldKey]);
    app(InstallationState::class)->write('key-rotation.pending', ['id' => 'pending']);
    expect(fn () => $provider->boot())->toThrow(RuntimeException::class, 'Key rotation is incomplete');
});

it('refuses an unsealed installation lock at production boot', function (): void {
    file_put_contents(storage_path('installed.lock'), 'legacy hand-written lock');
    $this->app->detectEnvironment(fn () => 'production');
    expect(fn () => (new AppServiceProvider($this->app))->boot())->toThrow(DomainException::class, '密钥校验');
});

it('rotates offline collection evidence while preserving its source and replay identity', function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $owner = User::factory()->create(['credit' => '0.00']);
    $actor = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($actor, 'finance-offline-receipt', 'Rotation fixture');
    $product = Product::factory()->create(['local_prices' => ['onetime' => ['price' => '10.00', 'enabled' => true]]]);
    $invoice = app(CreateComboInvoiceAction::class)->execute($owner, $product, BillingCycle::ONETIME, [], false, 'epay_wechat',
        ['request_id' => (string) Str::ulid()]);
    $input = ['request_id' => (string) Str::ulid(), 'amount' => '10.00', 'external_channel' => 'bank_transfer',
        'external_reference' => 'ROTATION-PROOF', 'received_at' => now()->subMinute()->toDateTimeString(), 'confirm' => '1', 'evidence' => 'C3-SECRET-RECEIPT'];
    $receipt = app(RecordOfflineInvoiceReceiptAction::class)->execute($invoice, $actor, $input);
    $oldCipher = DB::table('offline_invoice_receipts')->where('id', $receipt->id)->value('encrypted_evidence');
    $ledger = DB::table('financial_transactions')->orderBy('id')->get()->toJson();
    $claim = DB::table('external_collection_receipts')->orderBy('id')->get()->toJson();
    app(ReencryptSecretsAction::class)->execute($this->rotationOldKey, $this->rotationNewKey, 'Offline receipt rotation', true);
    $newCipher = DB::table('offline_invoice_receipts')->where('id', $receipt->id)->value('encrypted_evidence');
    expect($newCipher)->not->toBe($oldCipher)
        ->and(app(InstallationState::class)->encrypter($this->rotationNewKey)->decryptString($newCipher))->toBe('C3-SECRET-RECEIPT')
        ->and(DB::table('financial_transactions')->orderBy('id')->get()->toJson())->toBe($ledger)
        ->and(DB::table('external_collection_receipts')->orderBy('id')->get()->toJson())->toBe($claim);
    config(['app.key' => $this->rotationNewKey]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
    expect(app(RecordOfflineInvoiceReceiptAction::class)->execute($invoice, $actor, $input)->id)->toBe($receipt->id)
        ->and(OfflineInvoiceReceipt::count())->toBe(1);
});
