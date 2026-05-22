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

        <x-filament::section heading="Operations actions">
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="space-y-3 rounded-xl border border-gray-200 bg-white p-4">
                    <div class="font-medium text-gray-950">Run now</div>
                    <div class="flex flex-wrap gap-3">
                        <x-filament::button wire:click="dispatchDueIntegrationsNow">Dispatch due integrations</x-filament::button>
                        <x-filament::button color="gray" wire:click="pruneAuditLogsNow">Prune audit logs</x-filament::button>
                        <x-filament::button color="gray" wire:click="pruneErrorLogsNow">Prune error logs</x-filament::button>
                        <x-filament::button color="gray" wire:click="updateTrivyDbNow">Update Trivy DB</x-filament::button>
                    </div>
                </div>

                <div class="space-y-3 rounded-xl border border-gray-200 bg-white p-4">
                    <div class="font-medium text-gray-950">Targeted dispatch</div>
                    <div class="grid gap-3 md:grid-cols-2">
                        <div class="space-y-2">
                            <label class="block text-sm font-medium text-gray-700">Source fetch</label>
                            <select wire:model.live="selectedSourceId" class="w-full rounded-lg border-gray-300 text-sm shadow-sm">
                                @foreach ($this->sourceOptions() as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                            <x-filament::button size="sm" wire:click="dispatchSelectedSource">Queue source fetch</x-filament::button>
                        </div>

                        <div class="space-y-2">
                            <label class="block text-sm font-medium text-gray-700">Tracker refresh</label>
                            <select wire:model.live="selectedTrackerId" class="w-full rounded-lg border-gray-300 text-sm shadow-sm">
                                @foreach ($this->trackerOptions() as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                            <x-filament::button size="sm" wire:click="dispatchSelectedTracker">Queue tracker refresh</x-filament::button>
                        </div>
                    </div>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section heading="Failed jobs">
            <div class="space-y-3">
                @forelse ($this->recentFailedJobs() as $failedJob)
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <div class="font-medium text-gray-950">Failed job #{{ $failedJob['id'] }}</div>
                                <div class="text-sm text-gray-500">Queue: {{ $failedJob['queue'] }} · Failed at {{ $failedJob['failed_at'] }}</div>
                            </div>
                            <div class="flex gap-2">
                                <x-filament::button size="sm" wire:click="retryFailedJob('{{ $failedJob['uuid'] }}')">Retry</x-filament::button>
                                <x-filament::button size="sm" color="danger" wire:click="forgetFailedJob('{{ $failedJob['uuid'] }}')">Forget</x-filament::button>
                            </div>
                        </div>
                        <pre class="mt-3 overflow-x-auto rounded-lg bg-gray-950 px-3 py-2 text-xs text-gray-100">{{ $failedJob['payload_preview'] }}</pre>
                    </div>
                @empty
                    <div class="rounded-xl border border-dashed border-gray-300 bg-white p-4 text-sm text-gray-500">No failed jobs recorded.</div>
                @endforelse
            </div>
        </x-filament::section>

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
                            @if ($run->error_message)
                                <div class="mt-2 text-danger-700">{{ $run->error_message }}</div>
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
                            <div class="mt-1 text-gray-700">{{ $error->message }}</div>
                        </div>
                    @empty
                        <div class="rounded-xl border border-dashed border-gray-300 bg-white p-4 text-sm text-gray-500">No recent errors recorded.</div>
                    @endforelse
                </div>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>