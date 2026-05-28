<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section heading="Audit record">
            <dl class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 text-sm">
                <div>
                    <dt class="font-medium text-gray-500">Timestamp</dt>
                    <dd class="mt-1 text-gray-950">{{ $this->record->created_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-500">Actor</dt>
                    <dd class="mt-1">
                        <span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">
                            {{ $this->record->actor_kind }}
                        </span>
                    </dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-500">Action</dt>
                    <dd class="mt-1 font-mono text-gray-950">{{ $this->record->action }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-500">User</dt>
                    <dd class="mt-1">
                        @if ($this->record->user_id !== null)
                            @php $userUrl = $this->getUserUrl(); @endphp
                            @if ($userUrl)
                                <a href="{{ $userUrl }}" class="text-primary-600 underline hover:text-primary-500">
                                    User #{{ $this->record->user_id }}
                                </a>
                            @else
                                <span class="text-gray-700">User #{{ $this->record->user_id }} (deleted)</span>
                            @endif
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-500">IP address</dt>
                    <dd class="mt-1 font-mono text-gray-950">{{ $this->record->ip ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="font-medium text-gray-500">Subject</dt>
                    <dd class="mt-1">
                        @if ($this->record->subject_type !== null || $this->record->subject_id !== null)
                            @php $subjectUrl = $this->getSubjectUrl(); @endphp
                            @if ($subjectUrl)
                                <a href="{{ $subjectUrl }}" class="text-primary-600 underline hover:text-primary-500">
                                    {{ $this->record->subject_type }} #{{ $this->record->subject_id }}
                                </a>
                            @else
                                <span class="text-gray-700">{{ $this->record->subject_type }} #{{ $this->record->subject_id }}</span>
                            @endif
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </dd>
                </div>
            </dl>
        </x-filament::section>

        <x-filament::section heading="Payload">
            @php $payload = $this->getRedactedPayload(); @endphp
            @if ($payload !== '—')
                <pre class="max-h-96 overflow-auto rounded-lg bg-gray-950 px-4 py-3 text-xs text-gray-100 whitespace-pre-wrap">{{ $payload }}</pre>
            @else
                <div class="text-sm text-gray-500">No payload recorded.</div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
