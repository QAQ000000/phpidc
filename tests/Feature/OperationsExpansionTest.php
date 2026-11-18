<?php

declare(strict_types=1);

use App\Actions\AccrueAffiliateCommissionAction;
use App\Actions\AffiliateSnapshotAction;
use App\Actions\AssignAdminRoleAction;
use App\Actions\ClaimCouponAction;
use App\Actions\ConfigureIdentityAction;
use App\Actions\CreateComboInvoiceAction;
use App\Actions\CreateLocalCardProductAction;
use App\Actions\ManageTicketAction;
use App\Actions\MatureAffiliateCommissionsAction;
use App\Actions\ProcessAffiliateWithdrawalAction;
use App\Actions\RefundInvoiceAction;
use App\Actions\RequestAffiliateWithdrawalAction;
use App\Actions\SaveDownloadResourceAction;
use App\Actions\SaveOperationsConfigurationAction;
use App\Actions\SendEventNotificationAction;
use App\Actions\SendLifecycleNotificationAction;
use App\Contracts\BotDriverInterface;
use App\Contracts\FulfillmentDriver;
use App\Contracts\RenewableResource;
use App\Contracts\SmsDriverInterface;
use App\Data\InstanceInfoResult;
use App\Data\QuoteResult;
use App\Enums\BillingCycle;
use App\Enums\InstanceStatus;
use App\Filament\Pages\AffiliateOperations;
use App\Filament\Pages\AffiliatePayouts;
use App\Filament\Pages\DownloadOperations;
use App\Filament\Pages\NotificationOperations;
use App\Filament\Pages\PromotionCoupons;
use App\Filament\Pages\SupportTickets;
use App\Jobs\SendEventNotificationJob;
use App\Jobs\SendNotificationJob;
use App\Models\AffiliateCommission;
use App\Models\AffiliateRule;
use App\Models\AffiliateWithdrawal;
use App\Models\Coupon;
use App\Models\DownloadResource;
use App\Models\FinancialTransaction;
use App\Models\Instance;
use App\Models\Invoice;
use App\Models\NotificationTemplate;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\User;
use App\Models\UserNotificationBinding;
use App\Services\AffiliateFunds;
use App\Services\DriverManager;
use App\Services\NotificationManager;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);
beforeEach(function (): void {
    $this->installApplicationForTest();
    Queue::fake();
    Http::preventStrayRequests();
});

function operationsActor(string ...$roles): User
{
    $actor = User::factory()->create();
    foreach ($roles as $role) {
        app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated operations role');
    }

    return $actor;
}

function operationsCoupon(array $attributes = []): Coupon
{
    return Coupon::create($attributes + ['code' => 'SAVE'.Str::ulid(), 'type' => 'percent', 'value' => '10.00', 'min_order_amount' => '0.00',
        'applies_to' => 'both', 'per_user_limit' => 1, 'is_active' => true]);
}

function operationsPurchase(User $user, ?Coupon $coupon = null, bool $wallet = true, string $price = '100.00'): Invoice
{
    $product = app(CreateLocalCardProductAction::class)->execute('Operations card', $price, '0.00', 'CNY', 'Isolated sale');

    return app(CreateComboInvoiceAction::class)->execute($user, $product, BillingCycle::ONETIME, [], $wallet, 'epay_wechat', ['request_id' => (string) Str::ulid(), 'coupon_code' => $coupon?->code]);
}

it('discounts before wallet freezing and records one usage with both amount identities', function (): void {
    $user = User::factory()->create(['credit' => '30.00']);
    $coupon = operationsCoupon();
    app(ClaimCouponAction::class)->execute($user, $coupon->code);
    app(ClaimCouponAction::class)->execute($user, $coupon->code);
    $invoice = operationsPurchase($user, $coupon);
    expect($invoice->total_amount)->toBe('90.00')->and($invoice->discount_amount)->toBe('10.00')
        ->and($invoice->credit_applied)->toBe('30.00')->and($invoice->gateway_amount)->toBe('60.00')
        ->and($invoice->line_items['subtotal'])->toBe('100.00')->and($invoice->line_items['total'])->toBe('90.00')
        ->and($coupon->fresh()->used_count)->toBe(1)->and($user->fresh()->credit)->toBe('0.00')->and($user->fresh()->frozen_credit)->toBe('30.00');
    $this->assertDatabaseCount('coupon_claims', 1);
    $this->assertDatabaseHas('coupon_usages', ['invoice_id' => $invoice->id, 'discount_amount' => '10.00']);
    $this->actingAs($user)->get('/console/coupons')->assertOk()->assertSee('10.00 CNY');
});

it('counts per user usages rather than claims and caps a fixed discount at subtotal', function (): void {
    $user = User::factory()->create(['credit' => '500.00']);
    $coupon = operationsCoupon(['type' => 'fixed', 'value' => '200.00', 'per_user_limit' => 2]);
    app(ClaimCouponAction::class)->execute($user, $coupon->code);
    $first = operationsPurchase($user, $coupon);
    $second = operationsPurchase($user, $coupon);
    expect($first->total_amount)->toBe('0.00')->and($second->total_amount)->toBe('0.00')->and($coupon->fresh()->used_count)->toBe(2)
        ->and($user->fresh()->credit)->toBe('500.00');
    expect(fn () => operationsPurchase($user, $coupon))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('coupon_usages', 2);
});

it('rejects unclaimed or ineligible coupons without touching wallets invoices or quota', function (string $condition): void {
    $user = User::factory()->create(['credit' => '50.00']);
    $coupon = operationsCoupon();
    if ($condition !== 'unclaimed') {
        app(ClaimCouponAction::class)->execute($user, $coupon->code);
    }
    $coupon->update(match ($condition) {
        'expired' => ['valid_until' => now()], 'future' => ['valid_from' => now()->addDay()], 'quota' => ['total_quota' => 0],
        'kyc' => ['claim_conditions' => ['require_kyc' => true]], 'minimum' => ['min_order_amount' => '101.00'],
        'product' => ['product_ids' => []], 'type' => ['applies_to' => 'renew'], default => [],
    });
    expect(fn () => operationsPurchase($user, $coupon))->toThrow(ValidationException::class);
    expect($user->fresh()->credit)->toBe('50.00')->and($user->fresh()->frozen_credit)->toBe('0.00')->and($coupon->fresh()->used_count)->toBe(0);
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
})->with(['unclaimed', 'expired', 'future', 'quota', 'kyc', 'minimum', 'product', 'type']);

it('rechecks verified channel conditions after a claim and refuses foreign currency coupons', function (): void {
    $user = User::factory()->create(['credit' => '50.00']);
    $coupon = operationsCoupon(['claim_conditions' => ['require_bound_channels' => ['telegram']]]);
    $binding = UserNotificationBinding::create(['user_id' => $user->id, 'channel' => 'telegram', 'target' => '123', 'verified_at' => now()]);
    app(ClaimCouponAction::class)->execute($user, $coupon->code);
    $binding->update(['verified_at' => null]);
    expect(fn () => operationsPurchase($user, $coupon))->toThrow(ValidationException::class);
    $binding->update(['verified_at' => now()]);
    $product = app(CreateLocalCardProductAction::class)->execute('USD card', '10.00', '0.00', 'USD', 'Isolated currency');
    expect(fn () => app(CreateComboInvoiceAction::class)->execute($user, $product, BillingCycle::ONETIME, [], true, null, ['request_id' => (string) Str::ulid(), 'coupon_code' => $coupon->code]))->toThrow(ValidationException::class);
});

it('keeps discounted refunds at the stored total and never restores coupon quota or future price', function (): void {
    $user = User::factory()->create(['credit' => '100.00']);
    $coupon = operationsCoupon();
    app(ClaimCouponAction::class)->execute($user, $coupon->code);
    $invoice = operationsPurchase($user, $coupon);
    expect($invoice->instance->recurring_amount)->toBe('100.00');
    app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', true, 'Refund discounted order', operationsActor('finance-operator'));
    expect($user->fresh()->credit)->toBe('100.00')->and($coupon->fresh()->used_count)->toBe(1);
    $this->assertDatabaseHas('financial_transactions', ['reference_key' => 'refund:'.$invoice->id, 'amount' => '90.00']);
});

it('rolls back coupon usage and wallet movements when mandatory ledger persistence fails', function (): void {
    $user = User::factory()->create(['credit' => '100.00']);
    $coupon = operationsCoupon();
    app(ClaimCouponAction::class)->execute($user, $coupon->code);
    DB::unprepared("CREATE TRIGGER reject_operations_ledger BEFORE INSERT ON financial_transactions BEGIN SELECT RAISE(ABORT, 'ledger unavailable'); END");
    expect(fn () => operationsPurchase($user, $coupon))->toThrow(QueryException::class);
    expect($user->fresh()->credit)->toBe('100.00')->and($coupon->fresh()->used_count)->toBe(0);
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('coupon_usages', 0);
});

it('attributes signed referral visits only to new registrations and rejects forged links', function (): void {
    $referrer = User::factory()->create();
    $url = URL::signedRoute('affiliate.referral', ['user' => $referrer->id], absolute: false);
    $this->get('/r/'.$referrer->id.'?signature=forged')->assertForbidden();
    $this->get($url)->assertRedirect('/register');
    $this->post('/register', ['name' => 'Referral buyer', 'email' => 'referral@example.test', 'password' => 'Twelve-char-password', 'password_confirmation' => 'Twelve-char-password', 'referred_by' => 999])->assertRedirect('/console');
    $buyer = User::where('email', 'referral@example.test')->firstOrFail();
    expect($buyer->referred_by)->toBe($referrer->id);
    $other = User::factory()->create();
    $this->get(URL::signedRoute('affiliate.referral', ['user' => $other->id], absolute: false))->assertRedirect('/register');
    expect($buyer->fresh()->referred_by)->toBe($referrer->id);
});

it('snapshots the winning rule and accrues on discounted total including wallet payment', function (): void {
    $referrer = User::factory()->create();
    $buyer = User::factory()->create(['credit' => '100.00', 'referred_by' => $referrer->id]);
    AffiliateRule::create(['name' => 'Lower', 'commission_rate' => '50.00', 'priority' => 1, 'is_active' => true]);
    $rule = AffiliateRule::create(['name' => 'Winner', 'commission_rate' => '20.00', 'priority' => 5, 'is_active' => true]);
    AffiliateRule::create(['name' => 'Later tie', 'commission_rate' => '90.00', 'priority' => 5, 'is_active' => true]);
    $coupon = operationsCoupon();
    app(ClaimCouponAction::class)->execute($buyer, $coupon->code);
    $invoice = operationsPurchase($buyer, $coupon);
    $rule->update(['commission_rate' => '99.00', 'is_active' => false]);
    $commission = app(AccrueAffiliateCommissionAction::class)->execute($invoice->id);
    app(AccrueAffiliateCommissionAction::class)->execute($invoice->id);
    expect($invoice->gateway_amount)->toBe('0.00')->and($commission->amount)->toBe('18.00')->and($commission->rate_snapshot)->toBe('20.00')
        ->and($commission->status)->toBe('pending')->and($referrer->fresh()->affiliate_balance)->toBe('0.00');
    app(MatureAffiliateCommissionsAction::class)->execute($commission->id);
    expect($referrer->fresh()->affiliate_balance)->toBe('0.00');
    $this->travel(8)->days();
    $this->artisan('affiliate:process')->assertSuccessful();
    app(MatureAffiliateCommissionsAction::class)->execute($commission->id);
    expect($referrer->fresh()->affiliate_balance)->toBe('18.00')->and($commission->fresh()->status)->toBe('available');
    $this->assertDatabaseCount('affiliate_commissions', 1);
    $this->assertDatabaseHas('financial_transactions', ['reference_key' => 'affiliate:'.$commission->id, 'amount' => '18.00']);
});

it('recovers missed accrual but does not accrue refunded or unsnapshotted invoices', function (): void {
    $referrer = User::factory()->create();
    $buyer = User::factory()->create(['credit' => '500.00', 'referred_by' => $referrer->id]);
    AffiliateRule::create(['name' => 'Global', 'commission_rate' => '10.00', 'priority' => 0, 'is_active' => true]);
    $paid = operationsPurchase($buyer);
    $refunded = operationsPurchase($buyer);
    app(RefundInvoiceAction::class)->execute($refunded, 'refund_wallet', true, 'Before accrual', operationsActor('finance-operator'));
    Invoice::factory()->paid()->create();
    $this->artisan('affiliate:process')->assertSuccessful();
    $this->assertDatabaseHas('affiliate_commissions', ['invoice_id' => $paid->id, 'amount' => '10.00']);
    $this->assertDatabaseCount('affiliate_commissions', 1);
});

it('occupies pending funds and approves one idempotent audited wallet payout', function (): void {
    $user = User::factory()->create(['credit' => '7.00', 'frozen_credit' => '3.00', 'affiliate_balance' => '20.00']);
    $id = (string) Str::ulid();
    $withdrawal = app(RequestAffiliateWithdrawalAction::class)->execute($user, '15.00', $id);
    expect(app(RequestAffiliateWithdrawalAction::class)->execute($user, '15.00', $id)->id)->toBe($withdrawal->id);
    expect(fn () => app(RequestAffiliateWithdrawalAction::class)->execute($user, '16.00', $id))->toThrow(ValidationException::class);
    expect(fn () => app(RequestAffiliateWithdrawalAction::class)->execute($user, '6.00', (string) Str::ulid()))->toThrow(ValidationException::class);
    expect($user->fresh()->affiliate_balance)->toBe('20.00')->and($user->fresh()->credit)->toBe('7.00');
    $operator = operationsActor('finance-payout');
    app(ProcessAffiliateWithdrawalAction::class)->execute($withdrawal, $operator, true, 'Verified CNY wallet transfer');
    app(ProcessAffiliateWithdrawalAction::class)->execute($withdrawal, $operator, true, 'Retry after timeout');
    expect($user->fresh()->affiliate_balance)->toBe('5.00')->and($user->fresh()->credit)->toBe('22.00')->and($user->fresh()->frozen_credit)->toBe('3.00');
    $this->assertDatabaseHas('financial_transactions', ['reference_key' => 'payout:'.$withdrawal->id, 'type' => 'affiliate_payout', 'amount' => '15.00']);
    expect(FinancialTransaction::where('reference_key', 'payout:'.$withdrawal->id)->count())->toBe(1);
    $this->assertDatabaseHas('audit_logs', ['action' => 'affiliate.withdrawal_approved', 'actor_id' => $operator->id, 'reason' => 'Verified CNY wallet transfer']);
    expect(fn () => app(ProcessAffiliateWithdrawalAction::class)->execute($withdrawal, $operator, false, 'Change final state'))->toThrow(ValidationException::class);
});

it('keeps payout authority separate from refund authority and requires an audit reason', function (): void {
    $user = User::factory()->create(['affiliate_balance' => '20.00']);
    $withdrawal = app(RequestAffiliateWithdrawalAction::class)->execute($user, '10.00', (string) Str::ulid());
    expect(fn () => app(ProcessAffiliateWithdrawalAction::class)->execute($withdrawal, operationsActor('finance-operator'), true, 'Refund role'))->toThrow(AuthorizationException::class);
    expect(fn () => app(ProcessAffiliateWithdrawalAction::class)->execute($withdrawal, operationsActor('finance-payout'), true, ''))->toThrow(ValidationException::class);
    expect($withdrawal->fresh()->status)->toBe('pending')->and($user->fresh()->credit)->toBe('0.00');
});

it('rejects external payouts and releases rejected pending occupancy without creating money', function (): void {
    $user = User::factory()->create(['affiliate_balance' => '20.00']);
    expect(fn () => app(RequestAffiliateWithdrawalAction::class)->execute($user, '10.00', (string) Str::ulid(), 'external'))->toThrow(ValidationException::class);
    $withdrawal = app(RequestAffiliateWithdrawalAction::class)->execute($user, '10.00', (string) Str::ulid());
    app(ProcessAffiliateWithdrawalAction::class)->execute($withdrawal, operationsActor('finance-payout'), false, 'Customer cancelled');
    expect(app(AffiliateFunds::class)->pending($user->id))->toBe('0.00')->and($user->fresh()->affiliate_balance)->toBe('20.00')->and($user->fresh()->credit)->toBe('0.00');
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('blocks pending payout approval after a real refund creates a receivable', function (): void {
    $referrer = User::factory()->create(['affiliate_balance' => '20.00']);
    $buyer = User::factory()->create();
    $invoice = Invoice::factory()->paid()->create(['user_id' => $buyer->id]);
    AffiliateCommission::factory()->create(['invoice_id' => $invoice->id, 'buyer_id' => $buyer->id, 'referrer_id' => $referrer->id, 'status' => 'available', 'amount' => '30.00']);
    $withdrawal = app(RequestAffiliateWithdrawalAction::class)->execute($referrer, '15.00', (string) Str::ulid());
    app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', true, 'Refund commission sale', operationsActor('finance-operator'));
    expect(app(AffiliateFunds::class)->hasReceivable($referrer->id))->toBeTrue();
    $referrer->forceFill(['affiliate_balance' => '100.00'])->save(); // New income does not discharge an old receivable.
    expect(fn () => app(RequestAffiliateWithdrawalAction::class)->execute($referrer, '1.00', (string) Str::ulid()))->toThrow(ValidationException::class);
    expect(fn () => app(ProcessAffiliateWithdrawalAction::class)->execute($withdrawal, operationsActor('finance-payout'), true, 'Balance alone insufficient evidence'))->toThrow(ValidationException::class);
    expect($withdrawal->fresh()->status)->toBe('pending')->and($referrer->fresh()->credit)->toBe('0.00');
});

it('rolls back both balances and status if payout auditing fails', function (): void {
    $user = User::factory()->create(['affiliate_balance' => '20.00']);
    $withdrawal = app(RequestAffiliateWithdrawalAction::class)->execute($user, '10.00', (string) Str::ulid());
    $operator = operationsActor('finance-payout');
    DB::unprepared("CREATE TRIGGER reject_operations_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
    expect(fn () => app(ProcessAffiliateWithdrawalAction::class)->execute($withdrawal, $operator, true, 'Mandatory audit'))->toThrow(QueryException::class);
    expect($user->fresh()->credit)->toBe('0.00')->and($user->fresh()->affiliate_balance)->toBe('20.00')->and($withdrawal->fresh()->status)->toBe('pending');
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('enforces ticket ownership service ownership state and server controlled staff attribution', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $foreign = Instance::factory()->create(['user_id' => $other->id]);
    $this->actingAs($user)->post('/console/tickets', ['subject' => 'Foreign service', 'body' => 'Test', 'instance_id' => $foreign->id])->assertForbidden();
    $this->post('/console/tickets', ['subject' => 'Question', 'body' => '<script>secret()</script>', 'user_id' => $other->id, 'is_admin' => true])->assertRedirect();
    $ticket = Ticket::firstOrFail();
    expect($ticket->user_id)->toBe($user->id);
    $this->assertDatabaseHas('ticket_replies', ['ticket_id' => $ticket->id, 'is_admin' => false, 'user_id' => $user->id]);
    $this->get('/console/tickets/'.$ticket->id)->assertOk()->assertSee('&lt;script&gt;secret()&lt;/script&gt;', false)->assertDontSee('<script>secret()</script>', false);
    $this->actingAs($other)->get('/console/tickets/'.$ticket->id)->assertForbidden();
    $this->post('/console/tickets/'.$ticket->id, ['operation' => 'close'])->assertForbidden();
    $agent = operationsActor('support-agent');
    app(ManageTicketAction::class)->update($agent, $ticket, 'reply', 'We are checking', true, 'Customer requested assistance');
    expect($ticket->fresh()->status)->toBe('answered');
    app(ManageTicketAction::class)->update($user, $ticket, 'close');
    expect(fn () => app(ManageTicketAction::class)->update($agent, $ticket, 'reply', 'Closed reply', true, 'Review'))->toThrow(ValidationException::class);
    app(ManageTicketAction::class)->update($user, $ticket, 'reopen');
    app(ManageTicketAction::class)->update($user, $ticket, 'reply', 'Followup');
    expect($ticket->fresh()->status)->toBe('open')->and($ticket->fresh()->closed_at)->toBeNull();
});

it('fans out safe notification variables and independently logs a failed channel and fallback simulation', function (): void {
    $user = User::factory()->create();
    UserNotificationBinding::create(['user_id' => $user->id, 'channel' => 'telegram', 'target' => '123', 'verified_at' => now()]);
    UserNotificationBinding::create(['user_id' => $user->id, 'channel' => 'email', 'target' => 'unverified@example.test']);
    app(NotificationManager::class)->queue($user->id, 'invoice.paid', ['amount' => '10.00', 'password' => 'DO-NOT-SEND']);
    $jobs = Queue::pushed(SendEventNotificationJob::class);
    expect($jobs)->toHaveCount(2);
    foreach ($jobs as $job) {
        expect($job->variables)->not->toHaveKey('password');
        $job->handle(app(SendEventNotificationAction::class));
    }
    $this->assertDatabaseHas('notification_logs', ['channel' => 'telegram', 'status' => 'failed']);
    $this->assertDatabaseHas('notification_logs', ['channel' => 'email', 'target' => $user->email, 'status' => 'simulated']);
    $this->assertDatabaseMissing('notification_logs', ['target' => 'unverified@example.test']);
});

it('honors disabled templates and revalidates subscriptions before sending queued jobs', function (): void {
    $user = User::factory()->create();
    $binding = UserNotificationBinding::create(['user_id' => $user->id, 'channel' => 'telegram', 'target' => '123', 'verified_at' => now()]);
    NotificationTemplate::create(['event_key' => 'ticket.reply', 'channel' => 'email', 'body_markdown' => 'Notice', 'is_enabled' => false]);
    app(NotificationManager::class)->queue($user->id, 'ticket.reply', ['ticket_id' => 5]);
    $binding->update(['verified_at' => null]);
    foreach (Queue::pushed(SendEventNotificationJob::class) as $job) {
        $job->handle(app(SendEventNotificationAction::class));
    }
    $this->assertDatabaseHas('notification_logs', ['channel' => 'telegram', 'status' => 'skipped']);
    $this->assertDatabaseHas('notification_logs', ['channel' => 'email', 'status' => 'disabled']);
    Http::assertNothingSent();
});

it('keeps configuration fields and template variables within approved allowlists', function (): void {
    $actor = operationsActor('promotion-operator', 'affiliate-operator', 'notification-operator');
    $action = app(SaveOperationsConfigurationAction::class);
    expect(fn () => $action->execute($actor, 'template', ['event_key' => 'invoice.paid', 'channel' => 'email', 'body_markdown' => '{{password}}', 'is_enabled' => true], 'No secrets'))->toThrow(ValidationException::class);
    expect(fn () => $action->execute($actor, 'rule', ['name' => 'Invalid', 'commission_rate' => '100.01', 'priority' => 1, 'is_active' => true], 'Invalid percent'))->toThrow(ValidationException::class);
    $coupon = $action->execute($actor, 'coupon', ['code' => 'ALLOWLIST', 'type' => 'percent', 'value' => '10.00', 'min_order_amount' => '0.00', 'applies_to' => 'both', 'per_user_limit' => 1, 'is_active' => true, 'used_count' => 900], 'Validated fields');
    expect($coupon->fresh()->used_count)->toBe(0);
    $this->assertDatabaseHas('audit_logs', ['action' => 'coupon.save', 'actor_id' => $actor->id]);
});

it('authorizes download levels against only the current users active instances', function (string $level, string $identity, bool $allowed): void {
    Storage::fake('downloads');
    Storage::disk('downloads')->put('private.txt', 'private-content');
    $resource = DownloadResource::create(['title' => 'Protected download', 'file_path' => 'private.txt', 'storage_type' => 'local', 'access_level' => $level, 'is_active' => true]);
    if ($identity !== 'guest') {
        $user = User::factory()->create();
        if (in_array($identity, ['active', 'suspended'], true)) {
            Instance::factory()->create(['user_id' => $user->id, 'status' => $identity === 'active' ? InstanceStatus::ACTIVE : InstanceStatus::SUSPENDED]);
        }
        Instance::factory()->create(['status' => InstanceStatus::ACTIVE]); // Someone else's active service is irrelevant.
        $this->actingAs($user);
    }
    $response = $this->get('/downloads/'.$resource->id);
    $allowed ? $response->assertOk()->assertDownload('private.txt') : $response->assertForbidden();
    expect($resource->fresh()->download_count)->toBe($allowed ? 1 : 0);
    $this->get('/downloads')->assertOk()->assertDontSee('private.txt')->assertSee('Protected download');
})->with([
    ['public', 'guest', true], ['authenticated', 'guest', false], ['authenticated', 'normal', true],
    ['active_customer', 'normal', false], ['active_customer', 'suspended', false], ['active_customer', 'active', true],
]);

it('stores private uploads with hash and only reveals an external URL after authorization', function (): void {
    Storage::fake('downloads');
    $operator = operationsActor('download-operator');
    $file = UploadedFile::fake()->createWithContent('client.zip', 'private-file');
    $resource = app(SaveDownloadResourceAction::class)->execute($operator, ['title' => 'Client', 'category' => 'tools', 'storage_type' => 'local', 'access_level' => 'authenticated', 'sort_order' => 0, 'is_active' => true], 'Publish private client', file: $file);
    Storage::disk('downloads')->assertExists($resource->file_path);
    expect($resource->sha256)->toBe(hash('sha256', 'private-file'))->and($resource->file_size)->toBe(12);
    $external = DownloadResource::create(['title' => 'External client', 'storage_type' => 'external_url', 'external_url' => 'https://example.test/private-token', 'access_level' => 'authenticated', 'is_active' => true]);
    $this->get('/downloads')->assertOk()->assertDontSee('private-token')->assertDontSee($resource->file_path);
    $this->get('/downloads/'.$external->id)->assertForbidden();
    $this->actingAs(User::factory()->create())->get('/downloads/'.$external->id)->assertRedirect('https://example.test/private-token');
    $external->update(['is_active' => false]);
    $this->get('/downloads/'.$external->id)->assertNotFound();
});

it('renders each operations panel for its viewer and rejects unauthorized writes', function (string $class, string $role, string $post, array $payload): void {
    $viewer = operationsActor($role);
    if (str_contains($post, '/payouts/')) {
        $withdrawal = AffiliateWithdrawal::create(['user_id' => $viewer->id, 'destination' => 'wallet', 'amount' => '1.00', 'status' => 'pending']);
        $post = '/admin/operations/payouts/'.$withdrawal->id;
    }
    $this->actingAs($viewer);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Livewire::test($class)->assertSuccessful();
    $this->post($post, $payload)->assertForbidden();
})->with([
    [PromotionCoupons::class, 'promotion-viewer', '/admin/operations/config/coupon', []],
    [AffiliateOperations::class, 'affiliate-viewer', '/admin/operations/config/rule', []],
    [AffiliatePayouts::class, 'finance-viewer', '/admin/operations/payouts/999', []],
    [SupportTickets::class, 'support-viewer', '/admin/operations/config/template', []],
    [NotificationOperations::class, 'notification-viewer', '/admin/operations/config/template', []],
    [DownloadOperations::class, 'download-viewer', '/admin/operations/downloads', []],
]);

it('applies a renewal coupon once without changing future recurring price or repricing the existing period', function (): void {
    $user = User::factory()->create(['credit' => '100.00']);
    $instance = Instance::factory()->create(['user_id' => $user->id, 'status' => InstanceStatus::ACTIVE, 'currency' => 'CNY', 'billing_cycle' => BillingCycle::MONTHLY, 'next_due_at' => now()->addDay(), 'recurring_amount' => '100.00']);
    $driver = Mockery::mock(FulfillmentDriver::class.', '.RenewableResource::class);
    $driver->shouldReceive('syncInfo')->andReturn(new InstanceInfoResult('Active', nextDueDate: $instance->next_due_at->toIso8601String()));
    $driver->shouldReceive('quoteRenewal')->once()->andReturn(new QuoteResult('80.00', '100.00'));
    $manager = Mockery::mock(DriverManager::class)->makePartial();
    $manager->shouldReceive('fulfillment')->andReturn($driver);
    app()->instance(DriverManager::class, $manager);
    $coupon = operationsCoupon(['applies_to' => 'renew']);
    app(ClaimCouponAction::class)->execute($user, $coupon->code);
    $invoice = app(CreateComboInvoiceAction::class)->renewal($user, $instance, true, null, null, $coupon->code);
    $repeated = app(CreateComboInvoiceAction::class)->renewal($user, $instance->fresh(), true, null, null, 'ANOTHER-CODE');
    expect($repeated->id)->toBe($invoice->id)->and($invoice->total_amount)->toBe('90.00')->and($invoice->discount_amount)->toBe('10.00')
        ->and($invoice->upstream_expected_cost)->toBe('80.00')->and($invoice->gateway_amount)->toBe('0.00')->and($instance->fresh()->recurring_amount)->toBe('100.00')
        ->and($coupon->fresh()->used_count)->toBe(1)->and($user->fresh()->credit)->toBe('10.00');
    $operation = json_decode(Crypt::decryptString($invoice->line_items['encrypted_operation']), true, flags: JSON_THROW_ON_ERROR);
    expect($operation['recurring_after'])->toBe('100.00');
});

it('renders populated operator forms and posts audited configuration changes', function (): void {
    Storage::fake('downloads');
    $operator = operationsActor('promotion-operator', 'affiliate-operator', 'support-agent', 'notification-operator', 'download-operator', 'finance-payout');
    $coupon = operationsCoupon();
    $rule = AffiliateRule::create(['name' => 'Rule to edit', 'commission_rate' => '5.00', 'priority' => 0, 'is_active' => true]);
    $template = NotificationTemplate::create(['event_key' => 'invoice.paid', 'channel' => 'email', 'body_markdown' => 'Paid {{amount}}', 'is_enabled' => true]);
    $resource = DownloadResource::create(['title' => 'Resource to edit', 'storage_type' => 'external_url', 'external_url' => 'https://example.test/client', 'access_level' => 'public', 'is_active' => true]);
    $ticket = app(ManageTicketAction::class)->create(User::factory()->create(), ['subject' => 'Ticket to answer', 'body' => 'Please help']);
    $this->actingAs($operator);
    foreach ([['coupons', $coupon], ['affiliates', $rule], ['notifications', $template], ['downloads', $resource], ['support', $ticket]] as [$page, $model]) {
        $this->get('/admin/'.$page.'?edit='.$model->id)->assertOk()->assertSee('reason', false);
    }
    $this->post('/admin/operations/config/coupon', ['id' => $coupon->id, 'code' => $coupon->code, 'type' => 'fixed', 'value' => '8.25', 'min_order_amount' => '0.00',
        'applies_to' => 'both', 'per_user_limit' => 2, 'is_active' => true, 'reason' => 'Adjust future coupon', 'product_ids_json' => 'null', 'claim_conditions_json' => '{}'])->assertRedirect();
    expect($coupon->fresh()->value)->toBe('8.25');
    $this->post('/admin/operations/coupons/grant', ['user_id' => $operator->id, 'code' => $coupon->code, 'reason' => 'Grant acceptance eligibility'])->assertRedirect();
    $this->assertDatabaseHas('audit_logs', ['action' => 'coupon.grant', 'actor_id' => $operator->id]);
    $this->get('/console/affiliate')->assertOk()->assertSee('转入本站钱包');
    $this->get('/console/tickets')->assertOk();
});

it('blocks approving funds lost to refund even when there is no receivable and leaves the request pending', function (): void {
    $user = User::factory()->create(['affiliate_balance' => '10.00']);
    $withdrawal = app(RequestAffiliateWithdrawalAction::class)->execute($user, '10.00', (string) Str::ulid());
    $user->forceFill(['affiliate_balance' => '2.00'])->save();
    expect(fn () => app(ProcessAffiliateWithdrawalAction::class)->execute($withdrawal, operationsActor('finance-payout'), true, 'Recheck balance'))->toThrow(ValidationException::class);
    expect($withdrawal->fresh()->status)->toBe('pending')->and($user->fresh()->credit)->toBe('0.00');
});

it('does not silently accept an approved payout with a missing accounting entry', function (): void {
    $user = User::factory()->create();
    $withdrawal = AffiliateWithdrawal::create(['user_id' => $user->id, 'destination' => 'wallet', 'amount' => '10.00', 'status' => 'approved']);
    expect(fn () => app(ProcessAffiliateWithdrawalAction::class)->execute($withdrawal, operationsActor('finance-payout'), true, 'Verify historical approval'))->toThrow(ValidationException::class);
    expect($user->fresh()->credit)->toBe('0.00');
});

it('rolls back download metadata audit and private upload together on audit failure', function (): void {
    Storage::fake('downloads');
    $operator = operationsActor('download-operator');
    DB::unprepared("CREATE TRIGGER reject_download_audit BEFORE INSERT ON audit_logs BEGIN SELECT RAISE(ABORT, 'audit unavailable'); END");
    expect(fn () => app(SaveDownloadResourceAction::class)->execute($operator, ['title' => 'Private tool', 'category' => 'tools', 'storage_type' => 'local', 'access_level' => 'public', 'sort_order' => 0, 'is_active' => true], 'Mandatory audit', file: UploadedFile::fake()->createWithContent('tool.zip', 'content')))->toThrow(QueryException::class);
    $this->assertDatabaseCount('download_resources', 0);
    expect(Storage::disk('downloads')->allFiles())->toBe([]);
});

it('preserves before and after monetary rule values in operational audit snapshots', function (): void {
    $actor = operationsActor('affiliate-operator');
    $action = app(SaveOperationsConfigurationAction::class);
    $rule = $action->execute($actor, 'rule', ['name' => 'Audited rule', 'commission_rate' => '10.00', 'priority' => 1, 'is_active' => true], 'Initial commission');
    $action->execute($actor, 'rule', ['name' => 'Audited rule', 'commission_rate' => '25.00', 'priority' => 2, 'is_active' => false], 'Suspend and adjust future rate', $rule->id);
    $audit = DB::table('audit_logs')->where('action', 'rule.save')->latest('id')->first();
    expect(json_decode($audit->before, true)['commission_rate'])->toBe('10.00')->and(json_decode($audit->after, true)['commission_rate'])->toBe('25.00');
});

it('maps audited SMS variables and records a sanitized thrown channel failure without blocking another channel', function (): void {
    $user = User::factory()->create();
    UserNotificationBinding::create(['user_id' => $user->id, 'channel' => 'telegram', 'target' => 'test-chat', 'verified_at' => now()]);
    UserNotificationBinding::create(['user_id' => $user->id, 'channel' => 'sms', 'target' => 'test-phone', 'verified_at' => now()]);
    NotificationTemplate::create(['event_key' => 'invoice.paid', 'channel' => 'sms', 'body_markdown' => 'Paid {{amount}}', 'external_template_id' => 'APPROVED-PAYMENT',
        'variable_mapping' => ['invoice' => 'invoice_no', 'cost' => 'amount'], 'is_enabled' => true]);
    app(ConfigureIdentityAction::class)->execute(['sms.driver' => 'idcsmart'], 'Isolated transport substitute');
    $bot = Mockery::mock(BotDriverInterface::class);
    $bot->shouldReceive('send')->once()->with('test-chat', Mockery::type('string'))->andThrow(new RuntimeException('PRIVATE-NOTIFICATION-TOKEN'));
    $sms = Mockery::mock(SmsDriverInterface::class);
    $sms->shouldReceive('sendTemplate')->once()->with('test-phone', 'APPROVED-PAYMENT', ['invoice' => 'INV-SAFE', 'cost' => '10.00'])->andReturn(true);
    $manager = Mockery::mock(DriverManager::class)->makePartial();
    $manager->shouldReceive('bot')->andReturn($bot);
    $manager->shouldReceive('sms')->andReturn($sms);
    app()->instance(DriverManager::class, $manager);
    app(NotificationManager::class)->queue($user->id, 'invoice.paid', ['invoice_no' => 'INV-SAFE', 'amount' => '10.00']);
    foreach (Queue::pushed(SendEventNotificationJob::class) as $job) {
        $job->handle(app(SendEventNotificationAction::class));
    }
    $this->assertDatabaseHas('notification_logs', ['channel' => 'sms', 'status' => 'sent']);
    $failed = DB::table('notification_logs')->where('channel', 'telegram')->first();
    expect($failed->status)->toBe('failed')->and($failed->error_message)->toBe('Transport failed: RuntimeException');
    $this->assertDatabaseMissing('notification_logs', ['error_message' => 'PRIVATE-NOTIFICATION-TOKEN']);
});

it('honors templates and revoked recipients when handling legacy serialized renewal jobs', function (): void {
    $user = User::factory()->create();
    $invoice = Invoice::factory()->create(['user_id' => $user->id]);
    $binding = UserNotificationBinding::create(['user_id' => $user->id, 'channel' => 'telegram', 'target' => 'retired-chat', 'verified_at' => now()]);
    $job = new SendNotificationJob($invoice->id, 'telegram', 'retired-chat');
    $binding->update(['verified_at' => null]);
    $job->handle(app(SendLifecycleNotificationAction::class));
    $this->assertDatabaseHas('notification_logs', ['channel' => 'telegram', 'status' => 'skipped', 'target' => 'retired-chat']);
    NotificationTemplate::create(['event_key' => 'renewal.invoice', 'channel' => 'email', 'body_markdown' => 'Disabled renewal', 'is_enabled' => false]);
    (new SendNotificationJob($invoice->id, 'email', $user->email, true))->handle(app(SendLifecycleNotificationAction::class));
    $this->assertDatabaseHas('notification_logs', ['channel' => 'email', 'event_key' => 'renewal.invoice', 'status' => 'disabled']);
    Http::assertNothingSent();
});

it('matches product rules before snapshotting and treats a winning zero rate as no commission', function (): void {
    $referrer = User::factory()->create();
    $buyer = User::factory()->create(['referred_by' => $referrer->id]);
    $product = Product::factory()->create();
    $other = Product::factory()->create();
    $excluded = AffiliateRule::create(['name' => 'Other product only', 'commission_rate' => '50.00', 'priority' => 100, 'is_active' => true]);
    DB::table('affiliate_rule_product')->insert(['affiliate_rule_id' => $excluded->id, 'product_id' => $other->id]);
    $global = AffiliateRule::create(['name' => 'Eligible global', 'commission_rate' => '12.00', 'priority' => 1, 'is_active' => true]);
    $snapshot = app(AffiliateSnapshotAction::class)->execute($buyer, $product);
    expect($snapshot['rule_id'])->toBe($global->id)->and($snapshot['rate'])->toBe('12.00');
    $global->update(['commission_rate' => '0.00']);
    expect(app(AffiliateSnapshotAction::class)->execute($buyer, $product))->toBeNull();
});
