<x-filament-panels::layout.index :livewire="$layoutPage">
    @livewire(\App\Filament\Pages\ClassicCatalog::class, ['accountId' => $accountId, 'catalog' => $catalog])
</x-filament-panels::layout.index>
