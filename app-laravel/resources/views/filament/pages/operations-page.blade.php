<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section heading="Queue and schedule health">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-sm text-gray-500">Queued jobs</div>
                    <div class="mt-2 text-3xl font-semibold text-gray-950">{{ $this->queuedJobCount() }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-sm text-gray-500">Failed jobs</div>
                    <div class="mt-2 text-3xl font-semibold text-gray-950">{{ $this->failedJobCount() }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="text-sm text-gray-500">Running syncs</div>
                    <div class="mt-2 text-3xl font-semibold text-gray-950">{{ $this->runningSyncCount() }}</div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-4 md:col-span-2">
                    <div class="text-sm text-gray-500">Managed schedule entries</div>
                    <div class="mt-2 space-y-2 text-sm text-gray-700">
                        @foreach ($this->scheduleEntries() as $entry)
                            <div class="flex items-center justify-between gap-3 rounded-lg bg-gray-50 px-3 py-2">
                                <span class="font-medium text-gray-900">{{ $entry['id'] }}</span>
                                <span>{{ $entry['cadence'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </x-filament::section>

        {{ $this->table }}

        <div class="grid gap-6 xl:grid-cols-2">
            <x-filament::section heading="Recent sync runs">
                <div class="space-y-3">
                    @forelse ($this->recentSyncRuns() as $run)
                        <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm">
                            <div class="flex items-center justify-between gap-3">
                                <span class="font-medium text-gray-950">{{ $run->source_id }}</span>
                                <span class="{{ $run->status === 'success' ? 'text-success-700' : 'text-danger-700' }}">{{ ucfirst($run->status) }}</span>
                            </div>
                            <div class="mt-1 text-gray-500">Started {{ $run->started_at?->diffForHumans() ?? 'n/a' }}</div>
                            <div class="mt-2 grid gap-1 text-xs text-gray-600 sm:grid-cols-2">
                                @foreach (($run->counts_json ?? []) as $key => $value)
                                    <div><span class="font-medium">{{ str_replace('_', ' ', $key) }}:</span> {{ $value }}</div>
                                @endforeach
                            </div>
                            @if ($run->error_message)
                                <div class="mt-2 whitespace-pre-wrap text-danger-700">{{ $run->error_message }}</div>
                            @endif
                        </div>
                    @empty
                        <div class="rounded-xl border border-dashed border-gray-300 bg-white p-4 text-sm text-gray-500">No sync runs recorded.</div>
                    @endforelse
                </div>
            </x-filament::section>

            <x-filament::section heading="Recent errors">
                <div class="space-y-3">
                    @forelse ($this->recentErrors() as $error)
                        <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm">
                            <div class="flex items-center justify-between gap-3">
                                <span class="font-medium text-gray-950">{{ $error->channel }}</span>
                                <span class="text-gray-500">{{ $error->occurred_at?->diffForHumans() ?? 'n/a' }}</span>
                            </div>
                            <div class="mt-1 whitespace-pre-wrap text-gray-700">{{ $error->message }}</div>
                        </div>
                    @empty
                        <div class="rounded-xl border border-dashed border-gray-300 bg-white p-4 text-sm text-gray-500">No recent errors recorded.</div>
                    @endforelse
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>