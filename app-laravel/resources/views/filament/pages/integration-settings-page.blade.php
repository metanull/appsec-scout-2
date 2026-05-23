<x-filament-panels::page>
    <div class="space-y-6">
        <div>
            <h2 class="text-lg font-semibold text-gray-950">Integrations</h2>
            <p class="text-sm text-gray-500">Manage enablement, polling cadence, service users, and connection tests for every known source and tracker.</p>
        </div>

        <x-filament::section>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="px-3 py-2 font-medium">Kind</th>
                            <th class="px-3 py-2 font-medium">Integration</th>
                            <th class="px-3 py-2 font-medium">Enabled</th>
                            <th class="px-3 py-2 font-medium">Interval</th>
                            <th class="px-3 py-2 font-medium">Service user</th>
                            <th class="px-3 py-2 font-medium">Started</th>
                            <th class="px-3 py-2 font-medium">Last sync</th>
                            <th class="px-3 py-2 font-medium">Status</th>
                            <th class="px-3 py-2 font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 align-top text-gray-800">
                        @foreach ($this->integrations() as $integration)
                            <tr>
                                <td class="px-3 py-3 uppercase tracking-wide text-gray-500">{{ $integration['kind'] }}</td>
                                <td class="px-3 py-3">
                                    <div class="font-medium text-gray-950">{{ $integration['display_name'] }}</div>
                                    <div class="text-xs text-gray-500">{{ $integration['id'] }}</div>
                                </td>
                                <td class="px-3 py-3">
                                    <label class="inline-flex items-center gap-2">
                                        <input type="checkbox" wire:model.live="settings.{{ $integration['key'] }}.enabled" class="rounded border-gray-300">
                                        <span>{{ $integration['enabled'] ? 'Yes' : 'No' }}</span>
                                    </label>
                                </td>
                                <td class="px-3 py-3">
                                    <div class="flex items-center gap-2">
                                        <input
                                            type="number"
                                            min="1"
                                            wire:model.live="settings.{{ $integration['key'] }}.fetch_interval_minutes"
                                            class="w-24 rounded-lg border-gray-300 text-sm shadow-sm"
                                        >
                                        <span class="text-gray-500">minutes</span>
                                    </div>
                                </td>
                                <td class="px-3 py-3">
                                    <select wire:model.live="settings.{{ $integration['key'] }}.service_user_id" class="w-full rounded-lg border-gray-300 text-sm shadow-sm">
                                        <option value="">System credentials</option>
                                        @foreach ($this->serviceUserOptions() as $id => $name)
                                            <option value="{{ $id }}">{{ $name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="px-3 py-3 text-gray-600">
                                    {{ $integration['sync_started_at']?->diffForHumans() ?? '-' }}
                                </td>
                                <td class="px-3 py-3 text-gray-600">
                                    {{ $integration['last_synced_at']?->diffForHumans() ?? 'Never' }}
                                </td>
                                <td class="px-3 py-3">
                                    @if ($integration['last_sync_status'] !== null)
                                        <div class="font-medium {{ $integration['last_sync_status'] === 'success' ? 'text-success-700' : ($integration['last_sync_status'] === 'in_progress' ? 'text-warning-700' : 'text-danger-700') }}">
                                            {{ str($integration['last_sync_status'])->replace('_', ' ')->title() }}
                                        </div>
                                    @else
                                        <div class="text-gray-500">Not run</div>
                                    @endif

                                    @if ($this->statusMessageSummary($integration['last_sync_message']))
                                        <div class="mt-1 max-w-sm break-words text-xs text-gray-500">{{ $this->statusMessageSummary($integration['last_sync_message']) }}</div>
                                    @endif

                                    @if (($this->testResults[$integration['key']] ?? null) !== null)
                                        <div class="mt-2 max-w-sm break-words text-xs {{ $this->testResults[$integration['key']]['ok'] ? 'text-success-700' : 'text-danger-700' }}">
                                            {{ $this->testResults[$integration['key']]['ok'] ? 'Connection test succeeded.' : 'Connection test failed: ' . ($this->statusMessageSummary($this->testResults[$integration['key']]['error'] ?? null) ?? 'Unknown error.') }}
                                        </div>
                                    @endif
                                </td>
                                <td class="px-3 py-3">
                                    <div class="flex flex-wrap gap-2">
                                        <x-filament::button size="sm" wire:click="saveIntegration('{{ $integration['key'] }}')">
                                            Save
                                        </x-filament::button>
                                        <x-filament::button color="gray" size="sm" wire:click="testIntegration('{{ $integration['key'] }}')">
                                            Test connection
                                        </x-filament::button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>