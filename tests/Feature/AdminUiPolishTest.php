<?php

declare(strict_types=1);

use App\Actions\AssignAdminRoleAction;
use App\Actions\SaveCmsArticleAction;
use App\Actions\SaveCmsCategoryAction;
use App\Actions\UpdateProductPresentationAction;
use App\Models\CmsCategory;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);

beforeEach(function (): void {
    $this->installApplicationForTest();
    Http::preventStrayRequests();
    Queue::fake();
});

afterEach(fn () => Http::assertNothingSent());

function uiPolishActor(string $role): User
{
    $actor = User::factory()->create();
    app(AssignAdminRoleAction::class)->execute($actor, $role, 'Isolated UI polish acceptance');

    return $actor;
}

function uiPolishCmsInput(string $kind): array
{
    $category = CmsCategory::create(['type' => 'help', 'slug' => 'polish-guides', 'name' => 'Original guide category']);

    return match ($kind) {
        'article' => ['/admin/content/articles', ['category_id' => $category->id, 'title' => 'Polished article', 'slug' => 'polished-article',
            'body' => 'Article body', 'content_format' => 'markdown', 'is_published' => '0'], 'cms.article_save', $category],
        'category' => ['/admin/content/categories', ['id' => $category->id, 'type' => 'help', 'slug' => 'polish-guides',
            'name' => 'Polished guide category'], 'cms.category_save', $category],
        'product' => ['/admin/content/products/'.($product = Product::factory()->create(['name' => 'Original product']))->id,
            ['name' => 'Polished product', 'description' => 'Polished product description'], 'product.presentation_update', $product],
    };
}

it('enforces the CMS audit-note storage boundary before any HTTP content write', function (string $kind, int $length): void {
    [$path, $input, $action, $model] = uiPolishCmsInput($kind);
    $actor = uiPolishActor('cms-publisher');
    $before = $model->fresh()->getAttributes();
    $auditCount = DB::table('audit_logs')->count();
    $note = str_repeat('审', $length);
    $response = $this->actingAs($actor)->post($path, $input + ['reason' => $note]);

    if ($length === 256) {
        $response->assertRedirect()->assertSessionHasErrors('reason');
        expect(DB::table('audit_logs')->count())->toBe($auditCount)
            ->and($model->fresh()->getAttributes())->toBe($before);
        $this->assertDatabaseCount('cms_articles', 0);
    } else {
        $response->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('audit_logs', ['action' => $action, 'reason' => $note, 'actor_id' => $actor->id]);
    }
})->with(['article', 'category', 'product'])->with([255, 256]);

it('enforces the same CMS note boundary for direct Action callers and persists the validated trimmed note', function (string $kind): void {
    [, $input, $action, $model] = uiPolishCmsInput($kind);
    $actor = uiPolishActor('cms-publisher');
    $save = fn (string $note) => match ($kind) {
        'article' => app(SaveCmsArticleAction::class)->execute($model, $input, $note, $actor),
        'category' => app(SaveCmsCategoryAction::class)->execute($input, $note, $actor, $model->id),
        'product' => app(UpdateProductPresentationAction::class)->execute($model, $input, $note, $actor),
    };
    $auditCount = DB::table('audit_logs')->count();
    expect(fn () => $save(str_repeat('审', 256)))->toThrow(ValidationException::class)
        ->and(DB::table('audit_logs')->count())->toBe($auditCount);
    $note = str_repeat('审', 255);
    $save(' '.$note.' ');
    $this->assertDatabaseHas('audit_logs', ['action' => $action, 'reason' => $note]);
})->with(['article', 'category', 'product']);

it('describes an unlinked ticket and its status consistently in the conversation heading', function (): void {
    $ticket = Ticket::create(['user_id' => User::factory()->create()->id, 'subject' => 'Unlinked conversation', 'status' => 'open']);
    $this->actingAs(uiPolishActor('support-viewer'))->get('/admin/support?edit='.$ticket->id)
        ->assertOk()->assertSee('未关联服务 · 待回复')->assertDontSee('服务 #—')->assertDontSee(' · open');
});

it('localizes delivery channels and results while retaining delivery-log privacy', function (): void {
    $owner = User::factory()->create();
    foreach (['sent', 'failed', 'simulated'] as $status) {
        DB::table('notification_logs')->insert(['user_id' => $owner->id, 'event_key' => 'invoice.paid', 'channel' => 'email',
            'target' => 'PRIVATE-POLISH-RECIPIENT@example.invalid', 'status' => $status,
            'error_message' => 'PRIVATE-POLISH-TRANSPORT', 'created_at' => now(), 'updated_at' => now()]);
    }
    $response = $this->actingAs(uiPolishActor('notification-viewer'))->get('/admin/notifications')->assertOk()
        ->assertDontSee('PRIVATE-POLISH');
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
    $xpath = new DOMXPath($document);
    $rows = $xpath->query('//div[@role="region"][@aria-label="投递日志"]//tbody/tr');
    expect($rows)->toHaveCount(3);
    foreach ($rows as $row) {
        expect($row->textContent)->toContain('邮件')->not->toContain('email', 'simulated', 'failed', 'sent');
    }
});

it('returns a Chinese HTTPS validation message for an invalid external download without writing a resource', function (): void {
    $response = $this->actingAs(uiPolishActor('download-operator'))->from('/admin/downloads')
        ->post('/admin/operations/downloads', ['_admin_form' => 'download', 'category' => 'guides', 'title' => 'Retry external guide',
            'storage_type' => 'external_url', 'external_url' => 'http://files.example.invalid/guide.pdf',
            'access_level' => 'authenticated', 'sort_order' => '0', 'is_active' => '1']);
    $response->assertRedirect('/admin/downloads')
        ->assertSessionHasErrors(['external_url' => '外部下载地址必须是有效的 HTTPS 链接。']);
    $this->get('/admin/downloads')->assertOk()->assertSee('外部下载地址必须是有效的 HTTPS 链接。');
    $this->assertDatabaseCount('download_resources', 0);
});
