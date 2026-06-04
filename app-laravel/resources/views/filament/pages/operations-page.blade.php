<x-filament-panels::page>
    <div class="fi-page-content grid gap-y-6">
        <div class="fi-section rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-700 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-200">
            {{ $this->reconciliationLastRunSummary() }}
        </div>

        @foreach ($this->getWidgets() as $widget)
            @livewire($widget)
        @endforeach

        {{ $this->table }}
    </div>
</x-filament-panels::page>
