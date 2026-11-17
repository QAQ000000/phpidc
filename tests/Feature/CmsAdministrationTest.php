<?php

declare(strict_types=1);

use App\Actions\AssignCmsRoleAction;
use App\Actions\AssignInventoryRoleAction;
use App\Actions\SaveCmsArticleAction;
use App\Actions\SaveCmsCategoryAction;
use App\Actions\UpdateProductPresentationAction;
use App\Models\CmsArticle;
use App\Models\CmsCategory;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Concerns\InstallsApplication;

uses(InstallsApplication::class);
beforeEach(fn () => $this->installApplicationForTest());

function cmsOperator(string $role): User
{
    $user = User::factory()->create();
    app(AssignCmsRoleAction::class)->execute($user, $role, 'CMS acceptance');

    return $user;
}

function cmsDraftData(array $changes = []): array
{
    return array_replace(['title' => 'Release', 'slug' => 'release', 'body' => "## Useful\n\nDocumentation.",
        'content_format' => 'markdown', 'is_published' => '0', 'reason' => 'Editorial acceptance'], $changes);
}

it('separates CMS panel access and writes from inventory permissions', function (): void {
    $this->get('/admin/content')->assertRedirect('/admin/login');
    $inventory = User::factory()->create();
    app(AssignInventoryRoleAction::class)->execute($inventory, 'inventory-operator', 'Inventory test');
    $this->actingAs($inventory)->get('/admin/content')->assertForbidden();
    $this->post('/admin/content/categories', ['type' => 'news', 'slug' => 'updates', 'name' => 'Updates', 'reason' => 'No CMS permission'])->assertForbidden();
    $viewer = cmsOperator('cms-viewer');
    $this->actingAs($viewer)->get('/admin/content')->assertOk()->assertSee('商城与内容管理')->assertDontSee('保存文章');
    $this->post('/admin/content/categories', ['type' => 'news', 'slug' => 'updates', 'name' => 'Updates', 'reason' => 'Read only'])->assertForbidden();
    expect(CmsCategory::count())->toBe(0);
});

it('allows editors to save drafts but forbids every path that changes public content', function (): void {
    $editor = cmsOperator('cms-editor');
    $category = CmsCategory::create(['type' => 'help', 'slug' => 'guides', 'name' => 'Guides']);
    $this->actingAs($editor)->post('/admin/content/articles', cmsDraftData(['category_id' => $category->id]))->assertRedirect();
    $article = CmsArticle::sole();
    $this->get($article->publicPath())->assertNotFound();
    $this->post('/admin/content/articles', cmsDraftData(['category_id' => $category->id, 'id' => $article->id, 'is_published' => '1']))->assertForbidden();
    $article->update(['is_published' => true, 'published_at' => now()->addHour()]);
    $this->post('/admin/content/articles', cmsDraftData(['category_id' => $category->id, 'id' => $article->id, 'body' => 'Unauthorized replacement']))->assertForbidden();
    $product = Product::factory()->create();
    expect(fn () => app(UpdateProductPresentationAction::class)->execute($product, ['description' => 'Unsafe'], 'Editor only', $editor))->toThrow(AuthorizationException::class);
    expect(fn () => app(SaveCmsCategoryAction::class)->execute(['type' => 'help', 'slug' => 'new', 'name' => 'Unsafe'], 'Editor only', $editor))->toThrow(AuthorizationException::class);
    expect($article->fresh()->body)->toContain('Documentation.');
});

it('publishes a scheduled article at the same boundary on all public exports and records its operator', function (): void {
    $publisher = cmsOperator('cms-publisher');
    $this->actingAs($publisher)->post('/admin/content/categories', ['type' => 'news', 'slug' => 'updates', 'name' => 'Updates', 'is_hidden' => '0', 'reason' => 'Create category'])->assertRedirect();
    $category = CmsCategory::sole();
    $boundary = now()->addHour()->startOfSecond();
    $this->post('/admin/content/articles', cmsDraftData(['category_id' => $category->id, 'is_published' => '1', 'published_at' => $boundary->toDateTimeString(),
        'title' => 'SCHEDULED-NEWS', 'seo_title' => 'SEO scheduled release', 'seo_description' => 'Scheduled description']))->assertRedirect();
    $article = CmsArticle::sole();
    $this->get('/admin/content/articles/'.$article->id.'/preview')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertSee('SCHEDULED-NEWS');
    foreach ([$article->publicPath(), $article->publicPath().'.md'] as $path) {
        $this->get($path)->assertNotFound();
    }
    foreach (['/news', '/sitemap.xml', '/llms.txt'] as $path) {
        $this->get($path)->assertOk()->assertDontSee('SCHEDULED-NEWS')->assertDontSee('/updates/release');
    }
    $this->travelTo($boundary);
    $this->get($article->publicPath())->assertOk()->assertSee('SEO scheduled release')->assertSee('Scheduled description');
    $this->get($article->publicPath().'.md')->assertOk()->assertSee('SCHEDULED-NEWS');
    $this->get('/sitemap.xml')->assertOk()->assertSee('/news/updates/release');
    $this->get('/llms.txt')->assertOk()->assertSee('/news/updates/release.md');
    $this->assertDatabaseHas('audit_logs', ['action' => 'cms.article_save', 'actor_type' => 'user', 'actor_id' => $publisher->id]);
});

it('updates content by ID when a slug changes and rejects duplicate or cross-category replacement', function (): void {
    $publisher = cmsOperator('cms-publisher');
    $category = CmsCategory::create(['type' => 'help', 'slug' => 'guides', 'name' => 'Guides']);
    $other = CmsCategory::create(['type' => 'help', 'slug' => 'other', 'name' => 'Other']);
    $article = app(SaveCmsArticleAction::class)->execute($category, cmsDraftData(), 'Initial draft');
    $this->actingAs($publisher)->post('/admin/content/articles', cmsDraftData(['category_id' => $category->id, 'id' => $article->id, 'slug' => 'renamed']))->assertRedirect();
    expect(CmsArticle::count())->toBe(1)->and($article->fresh()->slug)->toBe('renamed');
    $this->post('/admin/content/articles', cmsDraftData(['category_id' => $category->id, 'slug' => 'renamed', 'title' => 'OVERWRITE']))->assertSessionHasErrors('slug');
    $this->post('/admin/content/articles', cmsDraftData(['category_id' => $other->id, 'id' => $article->id]))->assertNotFound();
    expect($article->fresh()->title)->toBe('Release');
    $this->post('/admin/content/categories', ['id' => $category->id, 'type' => 'help', 'slug' => 'new-guides', 'name' => 'Updated guides', 'reason' => 'Rename category'])->assertRedirect();
    expect(CmsCategory::count())->toBe(2)->and($category->fresh()->slug)->toBe('new-guides');
});

it('sanitizes internal previews and protects them from anonymous visitors and indexing', function (): void {
    $category = CmsCategory::create(['type' => 'help', 'slug' => 'guides', 'name' => 'Guides']);
    $article = app(SaveCmsArticleAction::class)->execute($category, cmsDraftData(['body' => '<script>BAD</script><h2>SAFE</h2>', 'content_format' => 'html']), 'Unsafe markup input');
    $this->get('/admin/content/articles/'.$article->id.'/preview')->assertUnauthorized();
    $viewer = cmsOperator('cms-viewer');
    $this->actingAs($viewer)->get('/admin/content/articles/'.$article->id.'/preview')->assertOk()->assertSee('SAFE')->assertDontSee('<script', false);
    expect($article->body)->not->toContain('<script');
});

it('saves product presentation without changing pricing or resource bindings and supports role revocation', function (): void {
    $publisher = cmsOperator('cms-publisher');
    $product = Product::factory()->create(['is_hidden' => false]);
    $before = $product->only(['driver_type', 'upstream_api_id', 'local_prices']);
    $this->actingAs($publisher)->post('/admin/content/products/'.$product->id, ['name' => 'New presentation', 'description' => 'Product summary', 'is_hidden' => '1', 'reason' => 'Withdraw product'])->assertRedirect();
    expect($product->fresh()->only(array_keys($before)))->toBe($before)->and($product->fresh()->is_hidden)->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'product.presentation_update', 'actor_id' => $publisher->id]);
    $this->artisan('cms:role', ['user' => $publisher->id, 'role' => 'cms-publisher', '--revoke' => true, '--reason' => 'End acceptance'])->assertSuccessful();
    $this->actingAs($publisher->fresh())->get('/admin/content')->assertForbidden();
});
