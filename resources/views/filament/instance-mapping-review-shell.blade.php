<x-filament-panels::layout.index :livewire="$layoutPage">
    @livewire(\App\Filament\Pages\InstanceMappingReview::class, ['instanceId' => $instance->id, 'preview' => $preview])
</x-filament-panels::layout.index>
