<x-filament-panels::page>
    <div class="space-y-6">
        <div>
            <h2 class="text-lg font-semibold text-gray-950">{{ $this->heading() }}</h2>
            <p class="text-sm text-gray-500">{{ $this->subheading() }}</p>
        </div>

        @foreach ($this->integrations() as $integration)
            <x-filament::section :heading="$integration['display_name']">
                <div class="space-y-4">
                    @foreach ($integration['required_credential_keys'] as $field)
                        @php
                            $stateKey = $field['state_key'];
                            $hasStored = $this->hasStored[$stateKey] ?? false;
                            $shouldReplace = $this->replace[$stateKey] ?? false;
                        @endphp

                        <div class="space-y-2">
                            <label class="block text-sm font-medium text-gray-700">{{ $field['key'] }}</label>

                            @if ($hasStored && ! $shouldReplace)
                                <input
                                    type="text"
                                    value="••••••••"
                                    readonly
                                    class="w-full rounded-lg border-gray-300 bg-gray-50 text-sm shadow-sm"
                                >
                                <label class="flex items-center gap-2 text-sm text-gray-600">
                                    <input type="checkbox" wire:model.live="replace.{{ $stateKey }}" class="rounded border-gray-300">
                                    Replace value
                                </label>
                            @else
                                <input
                                    type="{{ $field['is_secret'] ? 'password' : 'text' }}"
                                    wire:model="values.{{ $stateKey }}"
                                    class="w-full rounded-lg border-gray-300 text-sm shadow-sm"
                                >
                            @endif

                            @error('values.' . $stateKey)
                                <div class="text-sm text-danger-600">{{ $message }}</div>
                            @enderror
                        </div>
                    @endforeach

                    <div class="space-y-2">
                        <label class="block text-sm font-medium text-gray-700">Description</label>
                        <textarea
                            wire:model="descriptions.{{ $integration['id'] }}"
                            rows="3"
                            class="w-full rounded-lg border-gray-300 text-sm shadow-sm"
                        ></textarea>
                    </div>

                    @if (($this->testResults[$integration['id']] ?? null) !== null)
                        <div class="rounded-lg border p-3 text-sm {{ $this->testResults[$integration['id']]['ok'] ? 'border-success-200 text-success-700' : 'border-danger-200 text-danger-700' }}">
                            @if ($this->testResults[$integration['id']]['ok'])
                                Connection test succeeded.
                            @else
                                Connection test failed: {{ $this->testResults[$integration['id']]['error'] ?? 'Unknown error.' }}
                            @endif
                        </div>
                    @endif

                    <div class="flex flex-wrap justify-end gap-3">
                        <x-filament::button wire:click="testIntegration('{{ $integration['id'] }}')" color="gray" size="sm">
                            Test connection
                        </x-filament::button>
                        <x-filament::button wire:click="saveIntegration('{{ $integration['id'] }}')" size="sm">
                            Save credentials
                        </x-filament::button>
                    </div>
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>