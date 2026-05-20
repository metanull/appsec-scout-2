<x-filament-panels::page>
    <form wire:submit="pushSelected" class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-lg font-semibold text-gray-950">Pending upstream sync</h2>
                <p class="text-sm text-gray-500">Review local state and severity changes before pushing them to their sources.</p>
            </div>

            <x-filament::button type="submit" size="sm">
                Push to source
            </x-filament::button>
        </div>

        @forelse ($this->groupedEvents() as $sourceId => $rows)
            <x-filament::section :heading="'Source: ' . $sourceId">
                <div class="space-y-4">
                    @foreach ($rows as $row)
                        @php
                            /** @var \App\Models\SecurityEvent $event */
                            $event = $row['event'];
                            $pendingState = $event->pending_state?->value;
                            $pendingSeverity = $event->pending_severity?->value;
                        @endphp

                        <label class="block rounded-xl border p-4">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div class="flex gap-3">
                                    <input type="checkbox" wire:model="selectedEventIds" value="{{ $event->id }}" class="mt-1 rounded border-gray-300">
                                    <div class="space-y-2">
                                        <div class="font-medium text-gray-950">{{ $event->title }}</div>
                                        <div class="flex flex-wrap gap-2 text-xs">
                                            <x-filament::badge color="gray">Current state: {{ $event->state->value }}</x-filament::badge>
                                            @if ($pendingState)
                                                <x-filament::badge color="warning">Pending state: {{ $pendingState }}</x-filament::badge>
                                            @endif
                                            <x-filament::badge color="gray">Current severity: {{ $event->severity->value }}</x-filament::badge>
                                            @if ($pendingSeverity)
                                                <x-filament::badge color="warning">Pending severity: {{ $pendingSeverity }}</x-filament::badge>
                                            @endif
                                        </div>
                                        <div class="grid gap-2 text-sm text-gray-600 md:grid-cols-2">
                                            <div>
                                                <div class="font-medium text-gray-700">Diff</div>
                                                <div>State: {{ $event->state->value }} @if ($pendingState) <span class="text-warning-600">&rarr; {{ $pendingState }}</span> @else <span class="text-gray-400">unchanged</span> @endif</div>
                                                <div>Severity: {{ $event->severity->value }} @if ($pendingSeverity) <span class="text-warning-600">&rarr; {{ $pendingSeverity }}</span> @else <span class="text-gray-400">unchanged</span> @endif</div>
                                            </div>
                                            <div>
                                                <div class="font-medium text-gray-700">Review context</div>
                                                <div>Last editor: {{ $row['last_editor_name'] ?? 'Unknown' }}</div>
                                                <div>Last edited: {{ optional($row['last_edited_at'])->toDateTimeString() ?? 'n/a' }}</div>
                                                <div>Comment: {{ \Illuminate\Support\Str::limit((string) ($event->pending_comment ?? ''), 120) ?: 'n/a' }}</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </label>
                    @endforeach
                </div>
            </x-filament::section>
        @empty
            <x-filament::section heading="Pending upstream sync">
                <div class="text-sm text-gray-500">There are no pending alert changes to review.</div>
            </x-filament::section>
        @endforelse
    </form>
</x-filament-panels::page>