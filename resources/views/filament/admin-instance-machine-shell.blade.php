<x-filament-panels::layout.index :livewire="$layoutPage">
    @livewire(\App\Filament\Pages\AdminInstanceMachine::class, ['instanceId' => $instanceId, 'controls' => $controls])
</x-filament-panels::layout.index>
