<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Http\Controllers\InstanceMappingReviewController;
use App\Models\Instance;
use App\Models\InstanceMappingReview as InstanceMappingReviewRecord;
use App\Models\Product;
use Filament\Pages\Page;
use Filament\Pages\PageConfiguration;
use Filament\Panel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Locked;

class InstanceMappingReview extends Page
{
    protected static ?string $slug = 'upstream-instances/{instance}/mapping-review';

    protected static ?string $title = '服务配置映射核准';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.instance-mapping-review';

    #[Locked]
    public int $instanceId;

    #[Locked]
    public ?array $preview = null;

    public static function canAccess(): bool
    {
        $actor = Auth::user();

        return $actor !== null && ! $actor->trashed() && $actor->anonymized_at === null
            && $actor->can('upstream.view') && $actor->can('upstream.mapping-review');
    }

    public static function routes(Panel $panel, ?PageConfiguration $configuration = null): void
    {
        // Keep the controller's check response and preview data under the panel's native middleware.
        Route::get(static::getRoutePath($panel), [InstanceMappingReviewController::class, 'show'])
            ->middleware(static::getRouteMiddleware($panel))->name(static::getRelativeRouteName($panel));
        Route::post(static::getRoutePath($panel).'/check', [InstanceMappingReviewController::class, 'check'])
            ->middleware(static::getRouteMiddleware($panel))->middleware('throttle:mapping-review')
            ->name(static::getRelativeRouteName($panel).'.check');
    }

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'instance-mapping-review';
    }

    public function mount(int $instanceId, ?array $preview = null): void
    {
        $this->instanceId = $instanceId;
        $this->preview = $preview;
    }

    public function getHeading(): string
    {
        return '服务 #'.$this->instanceId.' 配置映射核准';
    }

    public function getBreadcrumbs(): array
    {
        return ['/admin/upstream-instances' => '上游服务', '配置映射核准'];
    }

    protected function getViewData(): array
    {
        $instance = Instance::findOrFail($this->instanceId);

        return ['instance' => $instance, 'preview' => $this->preview,
            'products' => Product::where('upstream_api_id', $instance->upstream_api_id)
                ->where('upstream_pid', $instance->active_config['upstream_pid'] ?? null)->where('driver_type', $instance->driver_type)->orderBy('id')->get(),
            'reviews' => InstanceMappingReviewRecord::where('instance_id', $instance->id)->latest('id')->limit(20)->get()];
    }
}
