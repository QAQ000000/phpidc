<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\ManageTicketAction;
use App\Actions\RefundInvoiceAction;
use App\Actions\RequestAffiliateWithdrawalAction;
use App\Enums\DriverType;
use App\Models\AffiliateCommission;
use App\Models\AffiliateRule;
use App\Models\CmsArticle;
use App\Models\CmsCategory;
use App\Models\Coupon;
use App\Models\DownloadResource;
use App\Models\Invoice;
use App\Models\NotificationTemplate;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

function routineAdminActor(string ...$roles): User
{
    $actor = User::factory()->create();
    foreach ($roles as $role) {
        app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated usability test');
    }

    return $actor;
}

function routineCouponInput(): array
{
    return ['code' => 'ROUTINE', 'type' => 'percent', 'value' => '10.00', 'min_order_amount' => '0.00',
        'applies_to' => 'both', 'per_user_limit' => 1, 'is_active' => '1'];
}

it('saves routine configuration without inventing a human reason and preserves supplied notes including zero', function (array $note, string $reason): void {
    $actor = routineAdminActor('promotion-operator');
    $this->actingAs($actor)->post('/admin/operations/config/coupon', routineCouponInput() + $note)
        ->assertSessionHasNoErrors()->assertRedirect();
    $coupon = Coupon::firstOrFail();
    $this->assertDatabaseHas('audit_logs', ['action' => 'coupon.save', 'subject_id' => $coupon->id, 'actor_id' => $actor->id, 'reason' => $reason]);
    expect($coupon->value)->toBe('10.00')->and($actor->fresh()->credit)->toBe('0.00');
    $this->assertDatabaseCount('invoices', 0);
    $this->assertDatabaseCount('financial_transactions', 0);
    Http::assertNothingSent();
})->with([
    'omitted' => [[], '创建优惠券'], 'empty' => [['reason' => ''], '创建优惠券'],
    'whitespace' => [['reason' => '   '], '创建优惠券'], 'zero' => [['reason' => '0'], '0'],
    'note' => [['reason' => '节日活动'], '节日活动'],
]);

it('saves CMS content and presentation with automatic audit descriptions while preserving publishing checks', function (): void {
    $actor = routineAdminActor('cms-publisher');
    $this->actingAs($actor)->post('/admin/content/categories', ['type' => 'help', 'slug' => 'guides', 'name' => '帮助'])
        ->assertSessionHasNoErrors()->assertRedirect();
    $category = CmsCategory::firstOrFail();
    $this->post('/admin/content/articles', ['category_id' => $category->id, 'title' => '使用说明', 'slug' => 'getting-started',
        'body' => '正文', 'content_format' => 'markdown', 'is_published' => '0'])->assertSessionHasNoErrors()->assertRedirect();
    $article = CmsArticle::firstOrFail();
    $product = Product::factory()->create();
    $this->post('/admin/content/products/'.$product->id, ['name' => '商品展示', 'description' => '更新说明', 'is_hidden' => '1'])
        ->assertSessionHasNoErrors()->assertRedirect();
    foreach (['cms.category_save' => ['创建栏目', $category->id], 'cms.article_save' => ['创建文章', $article->id],
        'product.presentation_update' => ['修改商品展示', $product->id]] as $action => [$reason, $id]) {
        $this->assertDatabaseHas('audit_logs', ['action' => $action, 'subject_id' => $id, 'actor_id' => $actor->id, 'reason' => $reason]);
    }
    expect($article->is_published)->toBeFalse()->and($product->fresh()->is_hidden)->toBeTrue();
    $editor = routineAdminActor('cms-editor');
    $this->actingAs($editor)->post('/admin/content/articles', ['id' => $article->id, 'category_id' => $category->id,
        'title' => '试图发布', 'slug' => $article->slug, 'body' => '正文', 'content_format' => 'markdown', 'is_published' => '1'])->assertForbidden();
    expect($article->fresh()->is_published)->toBeFalse();
});

it('replies closes and reopens a staff ticket without duplicate reason input and keeps the actual reply author', function (): void {
    $customer = User::factory()->create();
    $ticket = app(ManageTicketAction::class)->create($customer, ['subject' => 'Help', 'body' => 'Question']);
    $actor = routineAdminActor('support-agent');
    $this->actingAs($actor)->post('/admin/operations/tickets/'.$ticket->id, ['operation' => 'reply', 'body' => 'Answer'])
        ->assertSessionHasNoErrors()->assertRedirect();
    expect($ticket->fresh()->status)->toBe('answered');
    $this->assertDatabaseHas('ticket_replies', ['ticket_id' => $ticket->id, 'user_id' => $actor->id, 'body' => 'Answer', 'is_admin' => true]);
    $this->post('/admin/operations/tickets/'.$ticket->id, ['operation' => 'close'])->assertSessionHasNoErrors()->assertRedirect();
    expect($ticket->fresh()->status)->toBe('closed');
    $this->post('/admin/operations/tickets/'.$ticket->id, ['operation' => 'reply', 'body' => 'Not reopened'])->assertSessionHasErrors('operation');
    $this->assertDatabaseCount('ticket_replies', 2);
    $this->post('/admin/operations/tickets/'.$ticket->id, ['operation' => 'reopen'])->assertSessionHasNoErrors()->assertRedirect();
    expect($ticket->fresh()->status)->toBe('open');
    foreach (['reply' => '客服回复工单', 'close' => '关闭工单', 'reopen' => '重开工单'] as $operation => $reason) {
        $this->assertDatabaseHas('audit_logs', ['action' => 'ticket.'.$operation, 'subject_id' => $ticket->id, 'actor_id' => $actor->id, 'reason' => $reason]);
    }
    Http::assertNothingSent();
});

it('imports encrypted cards without a batch note and keeps deduplication and plaintext secrecy', function (): void {
    $actor = routineAdminActor('inventory-operator');
    $product = Product::factory()->create(['driver_type' => DriverType::REDEMPTION]);
    $payload = ['product_id' => $product->id, 'codes' => "PRIVATE-ONE\nPRIVATE-TWO\nPRIVATE-ONE"];
    $this->actingAs($actor)->post('/admin/inventory/import', $payload)->assertSessionHasNoErrors()->assertRedirect();
    $this->post('/admin/inventory/import', $payload + ['reason' => '0'])->assertSessionHasNoErrors()->assertRedirect()->assertSessionMissing('_old_input.codes');
    $this->assertDatabaseCount('redemption_codes', 2);
    $this->assertDatabaseHas('audit_logs', ['action' => 'cards.import', 'subject_id' => $product->id, 'actor_id' => $actor->id, 'reason' => '导入卡密库存']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'cards.import', 'subject_id' => $product->id, 'reason' => '0']);
    expect(DB::table('redemption_codes')->pluck('code')->implode(' '))->not->toContain('PRIVATE-ONE');
    $this->get('/admin/inventory/cards')->assertOk()->assertDontSee('PRIVATE-ONE');
});

it('uses selected products and typed claim conditions without JSON and can return to all products', function (): void {
    $actor = routineAdminActor('promotion-operator', 'affiliate-operator');
    $product = Product::factory()->create(['name' => '可搜索商品']);
    $this->actingAs($actor)->post('/admin/operations/config/coupon', routineCouponInput() + ['product_scope' => 'selected',
        'product_ids' => [(string) $product->id], 'claim_conditions' => ['registered_days_gt' => '0', 'registered_days_lt' => '',
            'require_kyc' => '0', 'require_bound_channels' => ['email']]])->assertSessionHasNoErrors()->assertRedirect();
    $coupon = Coupon::firstOrFail();
    expect($coupon->product_ids)->toBe([(string) $product->id])->and($coupon->claim_conditions)
        ->toBe(['registered_days_gt' => '0', 'require_kyc' => '0', 'require_bound_channels' => ['email']]);
    $this->post('/admin/operations/config/coupon', routineCouponInput() + ['id' => $coupon->id, 'product_scope' => 'all',
        'claim_conditions' => ['registered_days_gt' => '', 'require_kyc' => '0']])->assertSessionHasNoErrors()->assertRedirect();
    expect($coupon->fresh()->product_ids)->toBeNull()->and($coupon->fresh()->claim_conditions)->toBe(['require_kyc' => '0']);
    $this->post('/admin/operations/config/rule', ['name' => 'Selected rule', 'commission_rate' => '5.00', 'priority' => 0, 'is_active' => '1',
        'product_scope' => 'selected', 'product_ids' => [$product->id]])->assertSessionHasNoErrors()->assertRedirect();
    $rule = AffiliateRule::firstOrFail();
    $this->assertDatabaseHas('affiliate_rule_product', ['affiliate_rule_id' => $rule->id, 'product_id' => $product->id]);
    $this->assertDatabaseHas('audit_logs', ['action' => 'rule.save', 'subject_id' => $rule->id, 'reason' => '创建佣金规则']);
    $this->get('/admin/coupons?edit='.$coupon->id)->assertOk()->assertSee('搜索商品')->assertSee('可搜索商品')->assertDontSee('product_ids_json', false)->assertDontSee('claim_conditions_json', false);
    $this->get('/admin/affiliates?edit='.$rule->id)->assertOk()->assertSee('product_ids[]', false)->assertDontSee('product_ids_json', false);
});

it('rejects unknown product or condition values without saving configuration', function (array $extra, string $field): void {
    $actor = routineAdminActor('promotion-operator');
    $this->actingAs($actor)->post('/admin/operations/config/coupon', routineCouponInput() + $extra)->assertSessionHasErrors($field);
    $this->assertDatabaseCount('coupons', 0);
})->with([
    'unknown product' => [['product_scope' => 'selected', 'product_ids' => [99999]], 'product_ids.0'],
    'unsupported condition' => [['claim_conditions' => ['require_bound_channels' => ['custom']]], 'claim_conditions.require_bound_channels.0'],
]);

it('preserves an empty coupon restriction and never widens an empty selected commission rule to all products', function (): void {
    $actor = routineAdminActor('promotion-operator', 'affiliate-operator');
    $this->actingAs($actor)->post('/admin/operations/config/coupon', routineCouponInput() + ['product_scope' => 'selected'])
        ->assertSessionHasNoErrors()->assertRedirect();
    expect(Coupon::firstOrFail()->product_ids)->toBe([]);
    $this->post('/admin/operations/config/rule', ['name' => 'Missing products', 'commission_rate' => '5.00', 'priority' => 0,
        'is_active' => '1', 'product_scope' => 'selected'])->assertSessionHasErrors('product_ids');
    $this->assertDatabaseCount('affiliate_rules', 0);
});

it('saves SMS variable choices without JSON and preserves the template whitelist', function (): void {
    $actor = routineAdminActor('notification-operator');
    $payload = ['event_key' => 'invoice.paid', 'channel' => 'sms', 'body_markdown' => 'Paid {{amount}}', 'is_enabled' => '1',
        'external_template_id' => 'approved-template', 'mapping_rows_present' => '1',
        'mapping_rows' => [['name' => 'p1', 'value' => 'amount'], ['name' => 'p2', 'value' => 'invoice_no'], ['name' => '', 'value' => '']]];
    $this->actingAs($actor)->post('/admin/operations/config/template', $payload)->assertSessionHasNoErrors()->assertRedirect();
    $template = NotificationTemplate::firstOrFail();
    expect($template->variable_mapping)->toBe(['p1' => 'amount', 'p2' => 'invoice_no']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'template.save', 'subject_id' => $template->id, 'actor_id' => $actor->id, 'reason' => '创建通知模板']);
    $this->post('/admin/operations/config/template', array_replace($payload, ['id' => $template->id, 'mapping_rows' => [['name' => 'p1', 'value' => 'password']]]))
        ->assertSessionHasErrors('mapping_rows.0.value');
    expect($template->fresh()->variable_mapping)->toBe(['p1' => 'amount', 'p2' => 'invoice_no']);
    $this->get('/admin/notifications?edit='.$template->id)->assertOk()->assertSee('添加变量映射')->assertSee('channel === \'sms\'', false)->assertDontSee('variable_mapping_json', false);
    Http::assertNothingSent();
});

it('saves external downloads without a reason and renders storage specific inputs', function (): void {
    $actor = routineAdminActor('download-operator');
    $this->actingAs($actor)->post('/admin/operations/downloads', ['title' => '下载资料', 'category' => 'tools', 'storage_type' => 'external_url',
        'external_url' => 'https://example.test/file.zip', 'access_level' => 'authenticated', 'sort_order' => 0, 'is_active' => '1', 'reason' => ''])
        ->assertSessionHasNoErrors()->assertRedirect();
    $resource = DownloadResource::firstOrFail();
    $this->assertDatabaseHas('audit_logs', ['action' => 'download.save', 'subject_id' => $resource->id, 'actor_id' => $actor->id, 'reason' => '创建下载资源']);
    $this->get('/admin/downloads?edit='.$resource->id)->assertOk()->assertSee('storage === \'local\'', false)->assertSee('storage === \'external_url\'', false);
    $this->get('/downloads/'.$resource->id)->assertRedirect('https://example.test/file.zip');
});

it('keeps note length limits and rejects routine writes by viewers before generating an audit reason', function (): void {
    $actor = routineAdminActor('promotion-operator');
    $this->actingAs($actor)->post('/admin/operations/config/coupon', routineCouponInput() + ['reason' => str_repeat('x', 256)])->assertSessionHasErrors('reason');
    $viewer = routineAdminActor('promotion-viewer');
    $this->actingAs($viewer)->post('/admin/operations/config/coupon', routineCouponInput())->assertForbidden();
    $this->assertDatabaseCount('coupons', 0);
    expect(DB::table('audit_logs')->where('action', 'coupon.save')->count())->toBe(0);
});

it('approves a confirmed wallet payout without a handwritten reason and preserves exact idempotent accounting', function (): void {
    $user = User::factory()->create(['credit' => '7.00', 'frozen_credit' => '3.00', 'affiliate_balance' => '20.00']);
    $withdrawal = app(RequestAffiliateWithdrawalAction::class)->execute($user, '10.00', (string) Str::ulid());
    $actor = routineAdminActor('finance-payout');
    $url = '/admin/operations/payouts/'.$withdrawal->id;
    $this->actingAs($actor)->post($url, ['decision' => 'approve'])->assertSessionHasErrors('confirm');
    expect($withdrawal->fresh()->status)->toBe('pending');
    $this->post($url, ['decision' => 'approve', 'confirm' => '1'])->assertSessionHasNoErrors()->assertRedirect();
    $this->post($url, ['decision' => 'approve', 'confirm' => '1'])->assertSessionHasNoErrors()->assertRedirect();
    expect($user->fresh()->credit)->toBe('17.00')->and($user->fresh()->frozen_credit)->toBe('3.00')
        ->and($user->fresh()->affiliate_balance)->toBe('10.00')->and($withdrawal->fresh()->status)->toBe('approved');
    $this->assertDatabaseHas('financial_transactions', ['reference_key' => 'payout:'.$withdrawal->id, 'user_id' => $user->id,
        'type' => 'affiliate_payout', 'amount' => '10.00', 'balance_after' => '17.00', 'frozen_after' => '3.00']);
    expect(DB::table('financial_transactions')->where('reference_key', 'payout:'.$withdrawal->id)->count())->toBe(1);
    $this->assertDatabaseHas('audit_logs', ['action' => 'affiliate.withdrawal_approved', 'subject_id' => $withdrawal->id,
        'actor_id' => $actor->id, 'reason' => '批准佣金转入钱包']);
});

it('still requires an explanation to reject a wallet payout and blocks approval when funds or receivables fail', function (string $state): void {
    $user = User::factory()->create(['credit' => '7.00', 'affiliate_balance' => '20.00']);
    $withdrawal = app(RequestAffiliateWithdrawalAction::class)->execute($user, '10.00', (string) Str::ulid());
    $actor = routineAdminActor('finance-payout');
    $url = '/admin/operations/payouts/'.$withdrawal->id;
    $this->actingAs($actor)->post($url, ['decision' => 'reject', 'confirm' => '1'])->assertSessionHasErrors('reason');
    if ($state === 'insufficient') {
        $user->forceFill(['affiliate_balance' => '2.00'])->save();
    } else {
        $buyer = User::factory()->create();
        $invoice = Invoice::factory()->paid()->create(['user_id' => $buyer->id]);
        AffiliateCommission::factory()->create(['invoice_id' => $invoice->id, 'buyer_id' => $buyer->id,
            'referrer_id' => $user->id, 'status' => 'available', 'amount' => '30.00']);
        app(RefundInvoiceAction::class)->execute($invoice, 'refund_wallet', true, 'Refund creates an actual receivable', routineAdminActor('finance-operator'));
        $user->forceFill(['affiliate_balance' => '100.00'])->save();
    }
    $before = $user->fresh()->getRawOriginal();
    $this->post($url, ['decision' => 'approve', 'confirm' => '1'])->assertSessionHasErrors();
    expect($user->fresh()->getRawOriginal())->toBe($before)->and($withdrawal->fresh()->status)->toBe('pending')
        ->and(DB::table('financial_transactions')->where('reference_key', 'payout:'.$withdrawal->id)->count())->toBe(0);
})->with(['insufficient', 'receivable']);
