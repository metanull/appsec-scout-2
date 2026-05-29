<x-filament-panels::page>
    <div class="space-y-6">
        <div>
            <h2 class="text-lg font-semibold text-gray-950">{{ $this->heading() }}</h2>
            <p class="text-sm text-gray-500">{{ $this->subheading() }}</p>
        </div>

        <div class="flex flex-wrap justify-end gap-3">
            <x-filament::button wire:click="testAllConfiguredIntegrations" color="gray" size="sm" icon="heroicon-o-signal">
                Test all configured
            </x-filament::button>
            <x-filament::button wire:click="saveAllCredentials" size="sm" icon="heroicon-o-arrow-down-tray">
                Save all changes
            </x-filament::button>
        </div>

        @foreach ($this->integrations() as $integration)
            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center justify-between gap-3">
                        <span>{{ $integration['display_name'] }}</span>
                        <div class="flex items-center gap-2">
                            @if (($this->testResults[$integration['id']] ?? null) !== null)
                                @if ($this->testResults[$integration['id']]['ok'])
                                    <x-filament::badge color="success" size="sm">Connected</x-filament::badge>
                                @else
                                    <x-filament::badge color="danger" size="sm" :tooltip="$this->testResults[$integration['id']]['error'] ?? 'Unknown error'">Failed</x-filament::badge>
                                @endif
                            @endif
                            <x-filament::button wire:click="testIntegration('{{ $integration['id'] }}')" color="gray" size="xs">
                                Test
                            </x-filament::button>
                        </div>
                    </div>
                </x-slot>

                <div class="space-y-4">
                    @foreach ($integration['credential_fields'] as $field)
                        @php
                            $stateKey = $field->stateKey();
                            $hasStored = $this->hasStored[$stateKey] ?? false;
                            $shouldReplace = $this->replace[$stateKey] ?? false;
                        @endphp

                        <div class="space-y-1">
                            <label class="block text-sm font-medium text-gray-700">
                                {{ $field->label }}
                                @if ($field->required)
                                    <span class="text-danger-500">*</span>
                                @endif
                            </label>

                            @if ($field->description)
                                <p class="text-xs text-gray-500">{{ $field->description }}</p>
                            @endif

                            @if ($field->isSecret)
                                @if ($hasStored && ! $shouldReplace)
                                    <div class="flex items-center gap-3">
                                        <x-filament::badge color="success" size="sm">Stored</x-filament::badge>
                                        <button
                                            type="button"
                                            wire:click="$set('replace.{{ $stateKey }}', true)"
                                            class="text-sm text-primary-600 hover:text-primary-700"
                                        >
                                            Replace
                                        </button>
                                    </div>
                                @else
                                    <input
                                        type="password"
                                        wire:model="values.{{ $stateKey }}"
                                        placeholder="{{ $hasStored ? 'Enter new value to replace stored secret' : '' }}"
                                        class="w-full rounded-lg border-gray-300 text-sm shadow-sm"
                                    >
                                    @if ($hasStored)
                                        <button
                                            type="button"
                                            wire:click="$set('replace.{{ $stateKey }}', false)"
                                            class="text-xs text-gray-500 hover:text-gray-700"
                                        >
                                            Cancel replacement
                                        </button>
                                    @endif
                                @endif
                            @else
                                <input
                                    type="text"
                                    wire:model="values.{{ $stateKey }}"
                                    class="w-full rounded-lg border-gray-300 text-sm shadow-sm"
                                >
                            @endif

                            @error('values.' . $stateKey)
                                <div class="text-sm text-danger-600">{{ $message }}</div>
                            @enderror
                        </div>
                    @endforeach

                    <div class="space-y-1">
                        <label class="block text-sm font-medium text-gray-700">Description</label>
                        <textarea
                            wire:model="descriptions.{{ $integration['id'] }}"
                            rows="2"
                            class="w-full rounded-lg border-gray-300 text-sm shadow-sm"
                        ></textarea>
                    </div>

                    <div class="flex justify-end">
                        <x-filament::button wire:click="saveIntegration('{{ $integration['id'] }}')" size="sm">
                            Save credentials
                        </x-filament::button>
                    </div>
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>