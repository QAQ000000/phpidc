<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Models\AffiliateRule;
use App\Models\AffiliateWithdrawal;
use App\Models\CmsArticle;
use App\Models\CmsCategory;
use App\Models\Coupon;
use App\Models\FulfillmentTask;
use App\Models\NotificationTemplate;
use App\Models\Product;
use App\Models\ReconciliationCase;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Js;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
    Queue::fake();
});

afterEach(function (): void {
    Http::assertNothingSent();
});

function b1UiActor(string ...$roles): User
{
    $actor = User::factory()->create();
    foreach ($roles as $role) {
        app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated B1 presentation acceptance');
    }

    return $actor;
}

function b1UiXPath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$html);

    return new DOMXPath($document);
}

function b1UiForm(DOMXPath $xpath, string $path): DOMElement
{
    $forms = $xpath->query('//main//form[@method="post"][@action="'.$path.'"]');
    expect($forms)->toHaveCount(1);
    $form = $forms->item(0);
    expect($form)->toBeInstanceOf(DOMElement::class);

    return $form;
}

function b1UiField(DOMXPath $xpath, DOMElement $form, string $name): DOMElement
{
    $fields = $xpath->query('.//*[@name="'.$name.'"]', $form);
    expect($fields)->toHaveCount(1);
    $field = $fields->item(0);
    expect($field)->toBeInstanceOf(DOMElement::class);

    return $field;
}

function b1UiValue(DOMXPath $xpath, DOMElement $form, string $name): string
{
    $field = b1UiField($xpath, $form, $name);
    if ($field->tagName === 'textarea') {
        return $field->textContent;
    }
    if ($field->tagName === 'select') {
        $selected = $xpath->query('.//option[@selected]', $field);
        expect($selected)->toHaveCount(1);
        $option = $selected->item(0);

        return $option->hasAttribute('value') ? $option->getAttribute('value') : $option->textContent;
    }

    return $field->getAttribute('value');
}

function b1UiCoupon(array $attributes = []): Coupon
{
    return Coupon::create(array_replace(['code' => 'B1-ORIGINAL', 'type' => 'fixed', 'value' => '5.00', 'min_order_amount' => '0.00',
        'applies_to' => 'both', 'per_user_limit' => 1, 'is_active' => true], $attributes));
}

function b1UiCategory(): CmsCategory
{
    return CmsCategory::create(['type' => 'help', 'name' => 'B1 Help', 'slug' => 'b1-help']);
}

it('keeps each B1 page local and hides all management forms from its viewing role', function (string $role, string $path): void {
    $actor = b1UiActor($role);
    $auditCount = DB::table('audit_logs')->count();
    $response = $this->actingAs($actor)->get($path)->assertOk();
    $xpath = b1UiXPath($response->getContent());
    expect($xpath->query('//main//form[@method="post"]'))->toHaveCount(0)
        ->and($xpath->query('//main//table'))->not->toHaveCount(0)
        ->and($xpath->query('//main//*[contains(concat(" ", normalize-space(@class), " "), " inventory-box ")]'))->toHaveCount(0)
        ->and($xpath->query('//nav[contains(concat(" ", normalize-space(@class), " "), " fi-sidebar-nav ")]'))->toHaveCount(1);
    expect(DB::table('audit_logs')->count())->toBe($auditCount);
    $this->assertDatabaseCount('financial_transactions', 0);
    $this->assertDatabaseCount('notification_events', 0);
})->with([
    ['promotion-viewer', '/admin/coupons'], ['affiliate-viewer', '/admin/affiliates'], ['finance-viewer', '/admin/payouts'],
    ['support-viewer', '/admin/support'], ['notification-viewer', '/admin/notifications'], ['download-viewer', '/admin/downloads'],
    ['cms-viewer', '/admin/content'],
]);

it('keeps configuration creation forms inside dialogs with their original POST targets and CSRF', function (string $role, string $path, string $target, string $marker): void {
    $response = $this->actingAs(b1UiActor($role))->get($path)->assertOk();
    $xpath = b1UiXPath($response->getContent());
    $form = b1UiForm($xpath, $target);
    expect($xpath->query('ancestor::dialog[@aria-labelledby]', $form))->toHaveCount(1)
        ->and(b1UiValue($xpath, $form, '_admin_form'))->toBe($marker)
        ->and(b1UiValue($xpath, $form, '_token'))->not->toBe('');
})->with([
    ['promotion-operator', '/admin/coupons', '/admin/operations/config/coupon', 'coupon'],
    ['promotion-operator', '/admin/coupons', '/admin/operations/coupons/grant', 'coupon-grant'],
    ['affiliate-operator', '/admin/affiliates', '/admin/operations/config/rule', 'affiliate-rule'],
    ['notification-operator', '/admin/notifications', '/admin/operations/config/template', 'notification-template'],
    ['download-operator', '/admin/downloads', '/admin/operations/downloads', 'download'],
]);

it('restores a failed coupon edit from its posted identity without converting it to creation or contaminating grants', function (): void {
    $coupon = b1UiCoupon();
    $actor = b1UiActor('promotion-operator');
    $product = Product::factory()->create();
    $payload = ['_admin_form' => 'coupon', 'id' => $coupon->id, 'code' => 'B1-RETRY', 'type' => 'percent', 'value' => 'broken',
        'min_order_amount' => '3.00', 'applies_to' => 'renew', 'per_user_limit' => '3', 'is_active' => '0',
        'product_scope' => 'selected', 'product_ids' => [(string) $product->id], 'claim_conditions' => ['require_kyc' => '1']];
    $this->actingAs($actor)->from('/admin/coupons')->post('/admin/operations/config/coupon', $payload)
        ->assertRedirect('/admin/coupons')->assertSessionHasErrors('value')->assertSessionMissing('_old_input.code');
    $xpath = b1UiXPath($this->get('/admin/coupons')->assertOk()->getContent());
    $form = b1UiForm($xpath, '/admin/operations/config/coupon');
    foreach (['id', 'type', 'value', 'min_order_amount', 'applies_to', 'per_user_limit', 'is_active'] as $field) {
        expect(b1UiValue($xpath, $form, $field))->toBe((string) $payload[$field]);
    }
    // Existing global dontFlash protects every `code` input, including coupon codes.
    expect(b1UiValue($xpath, $form, 'code'))->toBe('B1-ORIGINAL')
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $form))->toHaveCount(1);
    $grant = b1UiForm($xpath, '/admin/operations/coupons/grant');
    expect(b1UiValue($xpath, $grant, 'code'))->toBe('')
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $grant))->toHaveCount(0)
        ->and($coupon->fresh()->code)->toBe('B1-ORIGINAL');
    $this->assertDatabaseCount('coupons', 1);
});

it('restores a failed grant only in its own dialog and preserves the independent coupon editor', function (): void {
    $coupon = b1UiCoupon();
    $this->actingAs(b1UiActor('promotion-operator'))->from('/admin/coupons?edit='.$coupon->id)
        ->post('/admin/operations/coupons/grant', ['_admin_form' => 'coupon-grant', 'code' => 'B1-GRANT-RETRY', 'user_id' => '', 'reason' => 'Grant note'])
        ->assertSessionHasErrors('user_id')->assertSessionMissing('_old_input.code');
    $xpath = b1UiXPath($this->get('/admin/coupons?edit='.$coupon->id)->assertOk()->getContent());
    $grant = b1UiForm($xpath, '/admin/operations/coupons/grant');
    expect(b1UiValue($xpath, $grant, 'code'))->toBe('')
        ->and(b1UiValue($xpath, $grant, 'reason'))->toBe('Grant note')
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $grant))->toHaveCount(1);
    $editor = b1UiForm($xpath, '/admin/operations/config/coupon');
    expect(b1UiValue($xpath, $editor, 'code'))->toBe('B1-ORIGINAL')
        ->and(b1UiValue($xpath, $editor, 'reason'))->toBe('')
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $editor))->toHaveCount(0);
    $this->assertDatabaseCount('coupon_claims', 0);
});

it('does not copy failed coupon edits into a different explicitly selected record', function (): void {
    $first = b1UiCoupon();
    $second = b1UiCoupon(['code' => 'B1-SECOND']);
    $this->actingAs(b1UiActor('promotion-operator'))->from('/admin/coupons')->post('/admin/operations/config/coupon', [
        '_admin_form' => 'coupon', 'id' => $first->id, 'code' => 'B1-FAILED-FIRST', 'value' => 'invalid',
    ])->assertSessionHasErrors();
    $xpath = b1UiXPath($this->get('/admin/coupons?edit='.$second->id)->assertOk()->getContent());
    $form = b1UiForm($xpath, '/admin/operations/config/coupon');
    expect(b1UiValue($xpath, $form, 'id'))->toBe((string) $second->id)
        ->and(b1UiValue($xpath, $form, 'code'))->toBe('B1-SECOND');
});

it('restores failed affiliate rules with the original rule identity and status', function (): void {
    $rule = AffiliateRule::create(['name' => 'Original B1 rule', 'commission_rate' => '5.00']);
    $payload = ['_admin_form' => 'affiliate-rule', 'id' => $rule->id, 'name' => 'Retry B1 rule', 'commission_rate' => 'invalid',
        'priority' => '12', 'is_active' => '0', 'product_scope' => 'all'];
    $this->actingAs(b1UiActor('affiliate-operator'))->from('/admin/affiliates')->post('/admin/operations/config/rule', $payload)
        ->assertSessionHasErrors('commission_rate');
    $xpath = b1UiXPath($this->get('/admin/affiliates')->assertOk()->getContent());
    $form = b1UiForm($xpath, '/admin/operations/config/rule');
    foreach (['id', 'name', 'commission_rate', 'priority', 'is_active'] as $field) {
        expect(b1UiValue($xpath, $form, $field))->toBe((string) $payload[$field]);
    }
    expect($rule->fresh()->name)->toBe('Original B1 rule');
});

it('restores failed notification template choices while keeping raw delivery recipients and transport errors hidden', function (): void {
    $template = NotificationTemplate::create(['event_key' => 'invoice.paid', 'channel' => 'email', 'subject' => 'Original subject', 'body_markdown' => 'Original body']);
    DB::table('notification_logs')->insert(['user_id' => User::factory()->create()->id, 'event_key' => 'invoice.paid', 'channel' => 'email',
        'target' => 'PRIVATE-B1-RECIPIENT@example.invalid', 'status' => 'failed', 'error_message' => 'PRIVATE-B1-TRANSPORT-KEY',
        'created_at' => now(), 'updated_at' => now()]);
    $payload = ['_admin_form' => 'notification-template', 'id' => $template->id, 'event_key' => 'invoice.paid', 'channel' => 'telegram',
        'subject' => 'Retry subject', 'body_markdown' => '', 'is_enabled' => '0'];
    $this->actingAs(b1UiActor('notification-operator'))->from('/admin/notifications')->post('/admin/operations/config/template', $payload)
        ->assertSessionHasErrors('body_markdown');
    $response = $this->get('/admin/notifications')->assertOk()->assertDontSee('PRIVATE-B1-RECIPIENT')->assertDontSee('PRIVATE-B1-TRANSPORT');
    $xpath = b1UiXPath($response->getContent());
    $form = b1UiForm($xpath, '/admin/operations/config/template');
    foreach (['id', 'event_key', 'channel', 'subject', 'is_enabled'] as $field) {
        expect(b1UiValue($xpath, $form, $field))->toBe((string) $payload[$field]);
    }
    expect($template->fresh()->subject)->toBe('Original subject');
});

it('preserves an explicitly cleared SMS mapping after another template field fails validation', function (): void {
    $originalMapping = ['REMOVED-B1-SUPPLIER-MAPPING' => 'amount'];
    $template = NotificationTemplate::create(['event_key' => 'invoice.paid', 'channel' => 'sms', 'body_markdown' => 'Paid {{amount}}',
        'external_template_id' => 'approved-b1-template', 'variable_mapping' => $originalMapping]);
    $this->actingAs(b1UiActor('notification-operator'))->from('/admin/notifications')->post('/admin/operations/config/template', [
        '_admin_form' => 'notification-template', 'id' => $template->id, 'event_key' => 'invoice.paid', 'channel' => 'sms',
        'body_markdown' => '', 'external_template_id' => 'approved-b1-template', 'mapping_rows_present' => '1', 'is_enabled' => '1',
    ])->assertSessionHasErrors('body_markdown')->assertSessionMissing('_old_input.mapping_rows');
    $xpath = b1UiXPath($this->get('/admin/notifications')->assertOk()->getContent());
    $form = b1UiForm($xpath, '/admin/operations/config/template');
    expect(b1UiValue($xpath, $form, 'id'))->toBe((string) $template->id)
        ->and(b1UiValue($xpath, $form, 'channel'))->toBe('sms')
        ->and(b1UiValue($xpath, $form, 'mapping_rows_present'))->toBe('1')
        ->and($form->getAttribute('x-data'))->not->toContain('REMOVED-B1-SUPPLIER-MAPPING')
        ->and($form->getAttribute('x-data'))->toContain(Js::from([['name' => '', 'value' => '']])->toHtml())
        ->and($template->fresh()->variable_mapping)->toBe($originalMapping);
});

it('restores failed downloads without inventing a retained file upload', function (): void {
    $payload = ['_admin_form' => 'download', 'title' => 'Retry private file', 'category' => 'tools', 'description' => 'Retry description',
        'version' => 'v2', 'storage_type' => 'local', 'access_level' => 'authenticated', 'sort_order' => '3', 'is_active' => '0'];
    $this->actingAs(b1UiActor('download-operator'))->from('/admin/downloads')->post('/admin/operations/downloads', $payload)
        ->assertSessionHasErrors('file');
    $xpath = b1UiXPath($this->get('/admin/downloads')->assertOk()->getContent());
    $form = b1UiForm($xpath, '/admin/operations/downloads');
    foreach (['title', 'category', 'description', 'version', 'storage_type', 'access_level', 'sort_order', 'is_active'] as $field) {
        expect(b1UiValue($xpath, $form, $field))->toBe($payload[$field]);
    }
    expect(b1UiField($xpath, $form, 'file')->getAttribute('value'))->toBe('')
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $form))->toHaveCount(1);
    $this->assertDatabaseCount('download_resources', 0);
});

it('restores only the failed wallet review while keeping the original approval consent', function (): void {
    $owner = User::factory()->create(['affiliate_balance' => '20.00']);
    $first = AffiliateWithdrawal::create(['user_id' => $owner->id, 'destination' => 'wallet', 'amount' => '3.00']);
    $second = AffiliateWithdrawal::create(['user_id' => $owner->id, 'destination' => 'wallet', 'amount' => '4.00']);
    $this->actingAs(b1UiActor('finance-payout'))->from('/admin/payouts')->post('/admin/operations/payouts/'.$first->id,
        ['_admin_form' => 'payout', 'record_id' => $first->id, 'decision' => 'approve', 'reason' => 'B1 pending review'])
        ->assertSessionHasErrors('confirm');
    $xpath = b1UiXPath($this->get('/admin/payouts')->assertOk()->getContent());
    $form = b1UiForm($xpath, '/admin/operations/payouts/'.$first->id);
    expect(b1UiValue($xpath, $form, 'record_id'))->toBe((string) $first->id)
        ->and(b1UiValue($xpath, $form, 'reason'))->toBe('B1 pending review')
        ->and(b1UiValue($xpath, $form, 'decision'))->toBe('approve')
        ->and(b1UiField($xpath, $form, 'confirm')->hasAttribute('required'))->toBeTrue()
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $form))->toHaveCount(1);
    $other = b1UiForm($xpath, '/admin/operations/payouts/'.$second->id);
    expect(b1UiValue($xpath, $other, 'reason'))->toBe('')
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $other))->toHaveCount(0)
        ->and($first->fresh()->status)->toBe('pending')->and($owner->fresh()->credit)->toBe('0.00');
    $this->assertDatabaseCount('financial_transactions', 0);
});

it('restores a failed ticket response to its original conversation without posting a reply', function (): void {
    $owner = User::factory()->create();
    $ticket = Ticket::create(['user_id' => $owner->id, 'subject' => 'B1 conversation', 'status' => 'open']);
    $this->actingAs(b1UiActor('support-agent'))->from('/admin/support')->post('/admin/operations/tickets/'.$ticket->id,
        ['_admin_form' => 'ticket', 'record_id' => $ticket->id, 'operation' => 'reply', 'body' => 'B1 unsaved response', 'reason' => str_repeat('x', 256)])
        ->assertSessionHasErrors('reason');
    $xpath = b1UiXPath($this->get('/admin/support')->assertOk()->getContent());
    $form = b1UiForm($xpath, '/admin/operations/tickets/'.$ticket->id);
    expect(b1UiValue($xpath, $form, 'record_id'))->toBe((string) $ticket->id)
        ->and(b1UiValue($xpath, $form, 'body'))->toBe('B1 unsaved response')
        ->and($ticket->fresh()->status)->toBe('open');
    $this->assertDatabaseCount('ticket_replies', 0);
});

it('restores each CMS editor without contaminating the other content forms', function (string $kind): void {
    $category = b1UiCategory();
    $article = CmsArticle::create(['cms_category_id' => $category->id, 'title' => 'Original article', 'slug' => 'original-article',
        'body' => 'Original body', 'content_format' => 'markdown', 'is_published' => false]);
    $product = Product::factory()->create(['name' => 'Original product']);
    [$target, $payload, $field, $expected, $error] = match ($kind) {
        'article' => ['/admin/content/articles', ['_admin_form' => 'cms-article', 'id' => $article->id, 'category_id' => $category->id,
            'title' => 'Retry article', 'slug' => 'Invalid Slug', 'body' => 'Retry article body', 'content_format' => 'html', 'is_published' => '0'], 'title', 'Retry article', 'slug'],
        'category' => ['/admin/content/categories', ['_admin_form' => 'cms-category', 'id' => $category->id, 'type' => 'help',
            'name' => 'Retry category', 'slug' => 'Invalid Slug', 'is_hidden' => '1', 'sort_order' => '3'], 'name', 'Retry category', 'slug'],
        'product' => ['/admin/content/products/'.$product->id, ['_admin_form' => 'cms-product', 'id' => $product->id,
            'name' => 'Retry product', 'description' => '', 'is_hidden' => '1'], 'name', 'Retry product', 'description'],
    };
    $this->actingAs(b1UiActor('cms-publisher'))->from('/admin/content')->post($target, $payload)->assertSessionHasErrors($error);
    $xpath = b1UiXPath($this->get('/admin/content')->assertOk()->getContent());
    $form = b1UiForm($xpath, $target);
    expect(b1UiValue($xpath, $form, 'id'))->toBe((string) $payload['id'])
        ->and(b1UiValue($xpath, $form, $field))->toBe($expected)
        ->and($xpath->query('ancestor::dialog//*[@role="alert"]', $form))->toHaveCount(1);
    foreach ($xpath->query('//main//form[@method="post"]') as $other) {
        if ($other->isSameNode($form)) {
            continue;
        }
        expect($other->textContent)->not->toContain($expected);
        foreach ($xpath->query('.//input', $other) as $input) {
            expect($input->getAttribute('value'))->not->toBe($expected);
        }
        expect($xpath->query('ancestor::dialog//*[@role="alert"]', $other))->toHaveCount(0);
    }
    expect($article->fresh()->title)->toBe('Original article')->and($category->fresh()->name)->toBe('B1 Help')
        ->and($product->fresh()->name)->toBe('Original product');
})->with(['article', 'category', 'product']);

it('honors an explicitly selected CMS article after another article fails validation', function (): void {
    $category = b1UiCategory();
    $first = CmsArticle::create(['cms_category_id' => $category->id, 'title' => 'First B1 article', 'slug' => 'first-b1',
        'body' => 'Body', 'content_format' => 'markdown', 'is_published' => false]);
    $second = CmsArticle::create(['cms_category_id' => $category->id, 'title' => 'Second B1 article', 'slug' => 'second-b1',
        'body' => 'Body', 'content_format' => 'markdown', 'is_published' => false]);
    $this->actingAs(b1UiActor('cms-publisher'))->from('/admin/content')->post('/admin/content/articles', [
        '_admin_form' => 'cms-article', 'id' => $first->id, 'category_id' => $category->id, 'title' => 'Unsaved first article',
        'slug' => 'Invalid Slug', 'body' => 'Unsaved body', 'content_format' => 'markdown', 'is_published' => '0',
    ])->assertSessionHasErrors('slug');
    $xpath = b1UiXPath($this->get('/admin/content?article='.$second->id)->assertOk()->getContent());
    $form = b1UiForm($xpath, '/admin/content/articles');
    expect(b1UiValue($xpath, $form, 'id'))->toBe((string) $second->id)
        ->and(b1UiValue($xpath, $form, 'title'))->toBe('Second B1 article');
});

it('keeps a published article read-only for editors and keeps its immutable category explicit for publishers', function (): void {
    $category = b1UiCategory();
    $article = CmsArticle::create(['cms_category_id' => $category->id, 'title' => 'Published B1', 'slug' => 'published-b1', 'body' => 'Body',
        'content_format' => 'markdown', 'is_published' => true, 'published_at' => now()->subMinute()]);
    $response = $this->actingAs(b1UiActor('cms-editor'))->get('/admin/content?article='.$article->id)->assertOk();
    $xpath = b1UiXPath($response->getContent());
    expect($xpath->query('//main//form[@action="/admin/content/articles"]'))->toHaveCount(0)
        ->and($xpath->query('//main//form[@action="/admin/content/categories"]'))->toHaveCount(0);
    $response = $this->actingAs(b1UiActor('cms-publisher'))->get('/admin/content?article='.$article->id)->assertOk();
    $xpath = b1UiXPath($response->getContent());
    $form = b1UiForm($xpath, '/admin/content/articles');
    expect($xpath->query('.//select[@name="category_id"][@disabled]', $form))->toHaveCount(1)
        ->and($xpath->query('.//input[@type="hidden"][@name="category_id"][@value="'.$category->id.'"]', $form))->toHaveCount(1);
});

it('preserves both CMS pagination positions and their existing record links', function (): void {
    $category = b1UiCategory();
    foreach (range(1, 21) as $id) {
        CmsArticle::create(['cms_category_id' => $category->id, 'title' => 'Article '.$id, 'slug' => 'article-'.$id, 'body' => 'Body',
            'content_format' => 'markdown', 'is_published' => false]);
    }
    Product::factory()->count(21)->create();
    $response = $this->actingAs(b1UiActor('cms-publisher'))->get('/admin/content?page=2&products_page=2')->assertOk();
    $xpath = b1UiXPath($response->getContent());
    $links = $xpath->query('//main//nav[@aria-label="结果分页"]//a[@href]');
    expect($links->length)->toBeGreaterThan(1);
    foreach ($links as $link) {
        parse_str(parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
        expect($query)->toHaveKeys(['page', 'products_page']);
    }
    expect($xpath->query('//main//a[contains(@href,"article=")]'))->not->toHaveCount(0)
        ->and($xpath->query('//main//a[contains(@href,"product=")]'))->not->toHaveCount(0);
});

it('emphasizes positive dashboard attention while retaining normal zero metrics', function (bool $positive): void {
    if ($positive) {
        FulfillmentTask::factory()->create(['status' => 'failed']);
        Ticket::create(['user_id' => User::factory()->create()->id, 'subject' => 'B1 attention', 'status' => 'open']);
        ReconciliationCase::create(['user_id' => User::factory()->create()->id, 'kind' => 'payment_attention', 'status' => 'open',
            'source_type' => 'b1-ui-fixture', 'source_id' => 'b1-ui', 'source_fingerprint' => str_repeat('a', 64), 'initial_reason' => 'fixture']);
    }
    $response = $this->actingAs(b1UiActor('finance-viewer', 'task-viewer', 'support-viewer'))->get('/admin')->assertOk();
    $xpath = b1UiXPath($response->getContent());
    foreach (['payment_attention' => 'danger', 'failed_tasks' => 'danger', 'open_tickets' => 'warning'] as $metric => $tone) {
        $nodes = $xpath->query('//*[@data-dashboard-metric="'.$metric.'"]');
        expect($nodes)->toHaveCount(1);
        $node = $nodes->item(0);
        expect($node->getAttribute('data-dashboard-alert'))->toBe($positive ? $tone : '')
            ->and($node->textContent)->toBe($positive ? '1' : '0');
    }
})->with([true, false]);
