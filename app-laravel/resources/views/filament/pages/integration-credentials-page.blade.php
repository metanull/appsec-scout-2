<x-filament-panels::page>
    <div class="fi-page-content grid gap-y-6">

        <div class="flex flex-wrap justify-end gap-3">
            <x-filament::button
                wire:click="testAllConfiguredIntegrations"
                color="gray"
                size="sm"
                icon="heroicon-o-signal"
            >
                Test all configured
            </x-filament::button>
            <x-filament::button
                wire:click="saveAllCredentials"
                size="sm"
                icon="heroicon-o-arrow-down-tray"
            >
                Save all changes
            </x-filament::button>
        </div>

        @foreach ($this->integrations() as $integration)
            @php $testResult = $this->testResults[$integration['id']] ?? null; @endphp

            <x-filament::section>
                <x-slot name="heading">
                    <div class="flex items-center gap-3">
                        <span>{{ $integration['display_name'] }}</span>
                        @if ($testResult !== null)
                            @if ($testResult['ok'])
                                <x-filament::badge color="success" size="sm">Connected</x-filament::badge>
                            @else
                                <x-filament::badge color="danger" size="sm">Failed</x-filament::badge>
                            @endif
                        @endif
                    </div>
                </x-slot>

                <x-slot name="headerEnd">
                    <x-filament::button
                        wire:click="testIntegration('{{ $integration['id'] }}')"
                        color="gray"
                        size="xs"
                        icon="heroicon-o-signal"
                    >
                        Test
                    </x-filament::button>
                </x-slot>

                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($integration['credential_fields'] as $field)
                        @php
                            $stateKey = $field->stateKey();
                            $fieldId = $integration['id'] . '_' . $stateKey;
                            $hasStored = $this->hasStored[$stateKey] ?? false;
                            $shouldReplace = $this->replace[$stateKey] ?? false;
                        @endphp

                        <div class="fi-fo-field-wrp flex flex-col gap-y-1.5">
                            <label for="{{ $fieldId }}" class="fi-fo-field-wrp-label inline-flex items-center gap-x-1 text-sm font-medium leading-6 text-gray-950 dark:text-white">
                                <span>{{ $field->label }}</span>
                                @if ($field->required)
                                    <span class="text-danger-600 dark:text-danger-400" aria-hidden="true">*</span>
                                @endif
                            </label>

                            <x-filament::input.wrapper>
                                @if ($field->isSecret && $hasStored && ! $shouldReplace)
                                    <div class="flex items-center gap-3 px-3 py-2">
                                        <x-filament::badge color="success" size="sm">Stored</x-filament::badge>
                                        <x-filament::button
                                            wire:click="$set('replace.{{ $stateKey }}', true)"
                                            size="xs"
                                            color="gray"
                                            outlined
                                        >
                                            Replace
                                        </x-filament::button>
                                    </div>
                                @elseif ($field->isSecret)
                                    <x-filament::input
                                        id="{{ $fieldId }}"
                                        type="password"
                                        wire:model.live="values.{{ $stateKey }}"
                                        :placeholder="$hasStored ? 'Enter new value to replace stored secret' : ''"
                                        @if ($field->required)
                                            required
                                        @endif
                                        data-lpignore="true"
                                        data-1p-ignore="true"
                                        data-bwignore="true"
                                    />
                                    @if ($hasStored)
                                        <x-filament::button
                                            wire:click="$set('replace.{{ $stateKey }}', false)"
                                            size="xs"
                                            color="gray"
                                            outlined
                                        >
                                            Cancel replacement
                                        </x-filament::button>
                                    @endif
                                @else
                                    <x-filament::input
                                        id="{{ $fieldId }}"
                                        type="text"
                                        wire:model.live="values.{{ $stateKey }}"
                                        @if ($field->required)
                                            required
                                        @endif
                                        data-lpignore="true"
                                        data-1p-ignore="true"
                                        data-bwignore="true"
                                    />
                                @endif
                            </x-filament::input.wrapper>

                            @if (filled($field->description))
                                <p class="fi-fo-field-wrp-helper-text text-sm text-gray-500 dark:text-gray-400">{{ $field->description }}</p>
                            @endif

                            @error('values.' . $stateKey)
                                <p class="fi-fo-field-wrp-error-message text-sm text-danger-600 dark:text-danger-400">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach

                    <div class="sm:col-span-2 fi-fo-field-wrp flex flex-col gap-y-1.5">
                        <x-filament::input.wrapper label="Description">
                            <x-filament::input
                                type="text"
                                wire:model.live="descriptions.{{ $integration['id'] }}"
                                data-lpignore="true"
                                data-1p-ignore="true"
                                data-bwignore="true"
                            />
                        </x-filament::input.wrapper>
                    </div>
                </div>
            </x-filament::section>
        @endforeach

    </div>
</x-filament-panels::page>
