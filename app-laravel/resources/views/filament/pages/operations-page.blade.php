<x-filament-panels::page>
    <div class="fi-page-content grid gap-y-6">
        @foreach ($this->getWidgets() as $widget)
            @livewire($widget)
        @endforeach

        {{ $this->table }}
    </div>
</x-filament-panels::page>
