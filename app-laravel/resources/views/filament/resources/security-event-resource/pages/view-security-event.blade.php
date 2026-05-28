<x-filament-panels::page>
    @php
        /** @var \App\Models\SecurityEvent $record */
        $record = $this->getRecord();
        $sections = $this->visibleSections();
        $metadata = is_array($record->metadata) ? $record->metadata : [];
        $validation = is_array($metadata['validationFingerprints'] ?? null) ? $metadata['validationFingerprints'] : [];
        $occurrenceRows = $this->occurrenceRows();
        $tags = is_array($metadata['tags'] ?? null) ? $metadata['tags'] : [];
        $auditRows = $this->auditRows();
        $workItemLinks = $this->workItemLinks();
        $attachments = $this->attachments();
        $sarifRows = $this->sarifRows();
        $linksByKind = $this->linkCatalogByKind();
        $rawEvidence = $this->rawEvidencePayload();
    @endphp

    <div class="space-y-6" wire:poll.30s>

        {{-- ── Alert Summary ────────────────────────────────────────────── --}}
        <x-filament::section heading="Alert Summary">
            <div class="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
                <div class="lg:col-span-2">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Title</div>
                    <div class="mt-1 font-semibold text-gray-900">{{ $record->title }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Type</div>
                    <div class="mt-1">
                        <x-filament::badge color="gray">{{ str($record->type->value)->replace('_', ' ')->title() }}</x-filament::badge>
                    </div>
                </div>
                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Severity</div>
                    <div class="mt-1">
                        <x-filament::badge color="{{ match($record->severity->value) { 'critical' => 'danger', 'high' => 'warning', 'medium' => 'info', 'low' => 'gray', default => 'secondary' } }}">
                            {{ ucfirst($record->severity->value) }}
                        </x-filament::badge>
                    </div>
                </div>
                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">State</div>
                    <div class="mt-1 flex flex-wrap items-center gap-1">
                        <x-filament::badge color="{{ match($record->state->value) { 'resolved' => 'success', 'dismissed' => 'gray', 'in_progress' => 'info', 'acknowledged' => 'warning', default => 'danger' } }}">
                            {{ str($record->state->value)->replace('_', ' ')->title() }}
                        </x-filament::badge>
                        @if ($record->is_dirty)
                            <x-filament::badge color="warning">Pending sync</x-filament::badge>
                        @endif
                    </div>
                </div>
                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Source</div>
                    <div class="mt-1">
                        <x-filament::badge>{{ $record->source_id }}</x-filament::badge>
                    </div>
                </div>
                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">First seen</div>
                    <div class="mt-1 text-sm text-gray-700">{{ optional($record->first_seen_at)->toDateTimeString() ?? 'n/a' }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Last seen</div>
                    <div class="mt-1 text-sm text-gray-700">{{ optional($record->last_seen_at)->toDateTimeString() ?? 'n/a' }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Fingerprint</div>
                    <div class="mt-1 break-all font-mono text-xs text-gray-600">{{ $record->fingerprint ?? 'n/a' }}</div>
                </div>
                @if ($record->rule_id)
                    <div>
                        <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Rule ID</div>
                        <div class="mt-1 font-mono text-sm text-gray-700">{{ $record->rule_id }}</div>
                    </div>
                @endif
                @if ($record->is_dirty && ($record->pending_state || $record->pending_severity))
                    <div class="sm:col-span-2 lg:col-span-3">
                        <div class="rounded border border-warning-300 bg-warning-50 px-4 py-3 text-sm text-warning-800">
                            <span class="font-semibold">Pending sync changes:</span>
                            @if ($record->pending_state) State → {{ str($record->pending_state->value)->replace('_', ' ')->title() }} @endif
                            @if ($record->pending_severity) Severity → {{ ucfirst($record->pending_severity->value) }} @endif
                            @if ($record->pending_comment) &mdash; <span class="italic">{{ Str::limit($record->pending_comment, 120) }}</span> @endif
                        </div>
                    </div>
                @endif
                @if ($tags !== [])
                    <div class="sm:col-span-2 lg:col-span-3">
                        <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Tags</div>
                        <div class="mt-1 flex flex-wrap gap-1">
                            @foreach ($tags as $tag)
                                <x-filament::badge color="gray">{{ $tag }}</x-filament::badge>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </x-filament::section>

        {{-- ── Links ────────────────────────────────────────────────────── --}}
        @if ($linksByKind !== [])
            <x-filament::section heading="Links" collapsible>
                <div class="space-y-3">
                    @foreach ($linksByKind as $kind => $kindLinks)
                        <div>
                            <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-500">
                                {{ \App\SecurityEvents\EventLinkCatalog::kindLabel($kind) }}
                            </div>
                            <div class="flex flex-wrap gap-3">
                                @foreach ($kindLinks as $link)
                                    <a
                                        href="{{ $link['url'] }}"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center gap-1 rounded border border-gray-200 bg-white px-3 py-1 text-sm text-primary-700 shadow-sm transition hover:border-primary-300 hover:bg-primary-50"
                                    >
                                        <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3 shrink-0" />
                                        {{ $link['label'] }}
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        {{-- ── Secret Details ───────────────────────────────────────────── --}}
        @if (in_array('secret', $sections, true))
            <x-filament::section heading="Secret Details">
                <div class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Detector</div>
                            <div class="mt-1 text-sm">{{ $metadata['detector'] ?? 'n/a' }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Truncated value</div>
                            <div class="mt-1 font-mono text-sm">{{ $metadata['truncatedSecret'] ?? 'n/a' }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Validation fingerprints</div>
                            <div class="mt-1 text-sm">{{ count($validation) }}</div>
                        </div>
                    </div>

                    @if ($occurrenceRows !== [])
                        <div>
                            <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">
                                Occurrences ({{ count($occurrenceRows) }})
                            </div>
                            <div class="overflow-x-auto rounded border border-gray-200">
                                <table class="min-w-full text-sm">
                                    <thead class="bg-gray-50 text-left text-xs text-gray-500">
                                        <tr>
                                            <th class="px-3 py-2 font-medium">File</th>
                                            <th class="px-3 py-2 font-medium">Lines</th>
                                            <th class="px-3 py-2 font-medium">Branch</th>
                                            <th class="px-3 py-2 font-medium">Commit</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($occurrenceRows as $occ)
                                            <tr class="hover:bg-gray-50">
                                                <td class="max-w-xs truncate px-3 py-2 font-mono text-xs">
                                                    @if ($occ['url'])
                                                        <a href="{{ $occ['url'] }}" target="_blank" rel="noopener noreferrer" class="text-primary-700 underline">{{ $occ['file_path'] }}</a>
                                                    @else
                                                        {{ $occ['file_path'] }}
                                                    @endif
                                                </td>
                                                <td class="px-3 py-2 font-mono text-xs text-gray-600">
                                                    {{ $occ['start_line'] }}@if ($occ['end_line'] !== $occ['start_line'] && $occ['end_line'] !== 'n/a')–{{ $occ['end_line'] }}@endif
                                                </td>
                                                <td class="px-3 py-2 text-xs text-gray-600">{{ $occ['branch'] ?: 'n/a' }}</td>
                                                <td class="px-3 py-2 font-mono text-xs text-gray-600">{{ $occ['commit'] ?: 'n/a' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @else
                        <div class="flex items-center gap-3">
                            <span class="text-sm text-gray-500">No occurrences loaded.</span>
                            <x-filament::button wire:click="loadSecretOccurrences" color="gray" size="sm">
                                Load occurrences
                            </x-filament::button>
                        </div>
                    @endif
                </div>
            </x-filament::section>
        @endif

        {{-- ── Dependency Details ───────────────────────────────────────── --}}
        @if (in_array('dependency', $sections, true))
            <x-filament::section heading="Dependency Details">
                @php $package = is_array($metadata['package'] ?? null) ? $metadata['package'] : []; @endphp
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Package</div>
                        <div class="mt-1 text-sm">{{ $package['name'] ?? 'n/a' }} {{ $package['version'] ?? '' }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Ecosystem</div>
                        <div class="mt-1 text-sm">{{ $package['ecosystem'] ?? 'n/a' }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-medium uppercase tracking-wide text-gray-500">CVE</div>
                        <div class="mt-1 text-sm">
                            @php $cveUrl = \App\SecurityEvents\SourceLinkHelper::cveLinkUrl($metadata['cve'] ?? null); @endphp
                            @if ($cveUrl)
                                <a class="text-primary-700 underline" href="{{ $cveUrl }}" target="_blank" rel="noopener">{{ strtoupper((string) ($metadata['cve'] ?? '')) }}</a>
                            @else
                                {{ $metadata['cve'] ?? 'n/a' }}
                            @endif
                        </div>
                    </div>
                    <div>
                        <div class="text-xs font-medium uppercase tracking-wide text-gray-500">CVSS</div>
                        <div class="mt-1 text-sm">{{ $metadata['cvss'] ?? 'n/a' }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Fixed in</div>
                        <div class="mt-1 text-sm">{{ $metadata['fixedInVersion'] ?? 'n/a' }}</div>
                    </div>
                </div>
            </x-filament::section>
        @endif

        {{-- ── Code Location ─────────────────────────────────────────────── --}}
        @if (in_array('code_location', $sections, true))
            <x-filament::section heading="Code Location">
                <div class="space-y-3">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <div class="text-xs font-medium uppercase tracking-wide text-gray-500">File</div>
                            <div class="mt-1 font-mono text-sm">
                                @if ($record->version_control_url)
                                    <a class="text-primary-700 underline" href="{{ $record->version_control_url }}" target="_blank" rel="noopener">{{ $record->file_path ?? 'n/a' }}</a>
                                @else
                                    {{ $record->file_path ?? 'n/a' }}
                                @endif
                            </div>
                        </div>
                        <div>
                            <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Lines</div>
                            <div class="mt-1 font-mono text-sm">
                                {{ $record->start_line ?? 'n/a' }}@if ($record->end_line && $record->end_line !== $record->start_line)–{{ $record->end_line }}@endif
                            </div>
                        </div>
                        <div>
                            <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Rule</div>
                            <div class="mt-1 font-mono text-sm">{{ $record->rule_id ?? 'n/a' }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-medium uppercase tracking-wide text-gray-500">CWE</div>
                            <div class="mt-1 text-sm">
                                @php $cweUrl = \App\SecurityEvents\SourceLinkHelper::cweLinkUrl($metadata['cwe'] ?? null); @endphp
                                @if ($cweUrl)
                                    <a class="text-primary-700 underline" href="{{ $cweUrl }}" target="_blank" rel="noopener">{{ $metadata['cwe'] }}</a>
                                @else
                                    {{ $metadata['cwe'] ?? 'n/a' }}
                                @endif
                            </div>
                        </div>
                        @if ($record->branch || $record->commit_sha)
                            <div>
                                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Branch</div>
                                <div class="mt-1 font-mono text-sm">{{ $record->branch ?? 'n/a' }}</div>
                            </div>
                            <div>
                                <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Commit</div>
                                <div class="mt-1 font-mono text-sm">{{ $record->commit_sha ? substr($record->commit_sha, 0, 12) : 'n/a' }}</div>
                            </div>
                        @endif
                    </div>
                    @if ($record->snippet)
                        <div>
                            <div class="mb-1 text-xs font-medium uppercase tracking-wide text-gray-500">Snippet</div>
                            <div class="overflow-x-auto rounded border border-gray-200 bg-white p-3 text-xs shadow-sm">
                                {!! $this->highlightSnippet($record->snippet, ($record->file_path ?? '') . ':' . ($record->start_line ?? '')) !!}
                            </div>
                        </div>
                    @endif
                </div>
            </x-filament::section>
        @endif

        {{-- ── Misconfiguration / Posture ────────────────────────────────── --}}
        @if (in_array('posture', $sections, true))
            <x-filament::section heading="Misconfiguration / Posture">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Resource Type</div>
                        <div class="mt-1 text-sm">{{ $metadata['resourceType'] ?? 'n/a' }}</div>
                    </div>
                    <div>
                        <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Recommendation</div>
                        <div class="mt-1 text-sm">{{ $metadata['recommendation'] ?? 'n/a' }}</div>
                    </div>
                    @if (! empty($metadata['documentationUrl']))
                        <div>
                            <div class="text-xs font-medium uppercase tracking-wide text-gray-500">Documentation</div>
                            <div class="mt-1 text-sm">
                                <a class="text-primary-700 underline" href="{{ $metadata['documentationUrl'] }}" target="_blank" rel="noopener">Open docs</a>
                            </div>
                        </div>
                    @endif
                </div>
            </x-filament::section>
        @endif

        {{-- ── Remediation ───────────────────────────────────────────────── --}}
        <x-filament::section heading="Remediation">
            <div class="prose max-w-none dark:prose-invert">{!! $this->remediationHtml() !!}</div>
        </x-filament::section>

        {{-- ── Comments ──────────────────────────────────────────────────── --}}
        <x-filament::section heading="Comments">
            <div class="space-y-4">
                @can('alerts.edit')
                    <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-4">
                        <label class="mb-2 block text-sm font-medium text-gray-700" for="new-comment-body">Add local comment</label>
                        <textarea
                            id="new-comment-body"
                            wire:model="newCommentBody"
                            rows="3"
                            class="w-full rounded-lg border-gray-300 text-sm shadow-sm"
                            placeholder="Minimum 10 characters…"
                        ></textarea>
                        @error('newCommentBody')
                            <div class="mt-1 text-xs text-danger-600">{{ $message }}</div>
                        @enderror
                        <div class="mt-2 flex justify-end">
                            <x-filament::button wire:click="addComment" size="sm">Add comment</x-filament::button>
                        </div>
                    </div>
                @endcan

                @forelse ($this->comments() as $comment)
                    <div class="rounded-lg border border-gray-200 bg-white p-3 shadow-sm">
                        <div class="mb-2 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                            <span class="text-gray-400">{{ optional($comment->created_at)->toDateTimeString() }}</span>
                            @if ($comment->upstream_comment_id)
                                <x-filament::badge color="gray">From source</x-filament::badge>
                            @elseif ($comment->author)
                                <x-filament::badge color="info">{{ $comment->author->name }}</x-filament::badge>
                            @else
                                <x-filament::badge color="gray">Local</x-filament::badge>
                            @endif
                        </div>

                        @if ($editingCommentId === $comment->id)
                            <textarea
                                wire:model="editingCommentBody"
                                rows="3"
                                class="w-full rounded-lg border-gray-300 text-sm shadow-sm"
                            ></textarea>
                            @error('editingCommentBody')
                                <div class="mt-1 text-xs text-danger-600">{{ $message }}</div>
                            @enderror
                            <div class="mt-2 flex justify-end gap-2">
                                <x-filament::button wire:click="cancelEditingComment" color="gray" size="sm">Cancel</x-filament::button>
                                <x-filament::button wire:click="saveCommentEdit" size="sm">Save</x-filament::button>
                            </div>
                        @else
                            <div class="whitespace-pre-wrap text-sm text-gray-800">{{ $comment->body }}</div>
                            @if ($this->canEditComment($comment))
                                <div class="mt-2 flex justify-end">
                                    <x-filament::button wire:click="startEditingComment({{ $comment->id }})" color="gray" size="sm">Edit</x-filament::button>
                                </div>
                            @endif
                        @endif
                    </div>
                @empty
                    <div class="text-sm text-gray-500">No comments yet.</div>
                @endforelse
            </div>
        </x-filament::section>

        {{-- ── Work Items ────────────────────────────────────────────────── --}}
        <x-filament::section heading="Work Items">
            @if ($workItemLinks->isEmpty())
                <div class="text-sm text-gray-500">No linked work item.</div>
            @else
                <div class="overflow-x-auto rounded border border-gray-200">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-left text-xs text-gray-500">
                            <tr>
                                <th class="px-3 py-2 font-medium">Tracker</th>
                                <th class="px-3 py-2 font-medium">Work Item</th>
                                <th class="px-3 py-2 font-medium">State</th>
                                <th class="px-3 py-2 font-medium">Created by</th>
                                <th class="px-3 py-2 font-medium">Created at</th>
                                <th class="px-3 py-2 font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($workItemLinks as $link)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-3 py-2">
                                        <x-filament::badge color="gray">{{ $link->tracker_id }}</x-filament::badge>
                                    </td>
                                    <td class="px-3 py-2">
                                        @if ($link->work_item_url)
                                            <a class="font-medium text-primary-700 underline" href="{{ $link->work_item_url }}" target="_blank" rel="noopener">
                                                {{ $link->work_item_title ?? $link->work_item_id }}
                                            </a>
                                        @else
                                            <span class="font-medium text-gray-700">{{ $link->work_item_title ?? $link->work_item_id }}</span>
                                        @endif
                                        <div class="font-mono text-xs text-gray-400">{{ $link->work_item_id }}</div>
                                    </td>
                                    <td class="px-3 py-2">
                                        @if ($link->work_item_state)
                                            <x-filament::badge color="info">{{ $link->work_item_state }}</x-filament::badge>
                                        @else
                                            <span class="text-gray-400">—</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-xs text-gray-600">
                                        {{ $link->createdBy?->name ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2 text-xs text-gray-600">
                                        {{ optional($link->created_at)->toDateTimeString() ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2">
                                        @can('work-items.link')
                                            <x-filament::button wire:click="unlinkWorkItem({{ $link->id }})" color="gray" size="sm">Unlink</x-filament::button>
                                        @endcan
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        {{-- ── Audit History ─────────────────────────────────────────────── --}}
        <x-filament::section heading="Audit History">
            @if ($auditRows->isEmpty())
                <div class="text-sm text-gray-500">No audit records found.</div>
            @else
                <div class="overflow-x-auto rounded border border-gray-200">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-left text-xs text-gray-500">
                            <tr>
                                <th class="px-3 py-2 font-medium">Action</th>
                                <th class="px-3 py-2 font-medium">Actor</th>
                                <th class="px-3 py-2 font-medium">Time</th>
                                <th class="px-3 py-2 font-medium">Details</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($auditRows as $row)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-3 py-2 font-mono text-xs">{{ $row->action }}</td>
                                    <td class="px-3 py-2 text-xs text-gray-600">{{ $row->user?->name ?? ($row->actor_kind ?? '—') }}</td>
                                    <td class="px-3 py-2 text-xs text-gray-500">{{ optional($row->created_at)->toDateTimeString() }}</td>
                                    <td class="px-3 py-2 text-xs text-gray-500">
                                        @if (is_array($row->payload_json) && $row->payload_json !== [])
                                            <details class="cursor-pointer">
                                                <summary class="select-none text-primary-700 underline">View payload</summary>
                                                <pre class="mt-1 max-h-40 overflow-auto rounded bg-gray-100 p-2 text-xs">{{ json_encode($row->payload_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                            </details>
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        {{-- ── Attachments ───────────────────────────────────────────────── --}}
        <x-filament::section heading="Attachments">
            @if ($attachments->isEmpty())
                <div class="text-sm text-gray-500">No attachments yet.</div>
            @else
                <div class="overflow-x-auto rounded border border-gray-200">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 text-left text-xs text-gray-500">
                            <tr>
                                <th class="px-3 py-2 font-medium">Name</th>
                                <th class="px-3 py-2 font-medium">Kind</th>
                                <th class="px-3 py-2 font-medium">Type</th>
                                <th class="px-3 py-2 font-medium">Size</th>
                                <th class="px-3 py-2 font-medium">Created by</th>
                                <th class="px-3 py-2 font-medium">Created at</th>
                                <th class="px-3 py-2 font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($attachments as $attachment)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-3 py-2 font-medium text-gray-800">{{ $attachment->name }}</td>
                                    <td class="px-3 py-2">
                                        <x-filament::badge color="gray">{{ $attachment->kind }}</x-filament::badge>
                                    </td>
                                    <td class="px-3 py-2 text-xs text-gray-500">{{ $attachment->mime }}</td>
                                    <td class="px-3 py-2 text-xs text-gray-600">{{ $this->formatBytes($attachment->size_bytes) }}</td>
                                    <td class="px-3 py-2 text-xs text-gray-600">{{ $attachment->createdBy?->name ?? $attachment->created_by_command ?? '—' }}</td>
                                    <td class="px-3 py-2 text-xs text-gray-500">{{ optional($attachment->created_at)->toDateTimeString() }}</td>
                                    <td class="px-3 py-2">
                                        <div class="flex flex-wrap gap-2">
                                            <a class="text-sm text-primary-700 underline" href="{{ $this->downloadAttachmentUrl($attachment) }}">Download</a>
                                            @can('work-items.create')
                                                <x-filament::button wire:click="deleteAttachment({{ $attachment->id }})" color="gray" size="sm">Delete</x-filament::button>
                                            @endcan
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if ($sarifRows !== [])
                <div class="mt-4">
                    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Trivy SARIF Results ({{ count($sarifRows) }})</div>
                    <div class="overflow-x-auto rounded border border-gray-200">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-left text-xs text-gray-500">
                                <tr>
                                    <th class="px-3 py-2 font-medium">Rule</th>
                                    <th class="px-3 py-2 font-medium">Severity</th>
                                    <th class="px-3 py-2 font-medium">Location</th>
                                    <th class="px-3 py-2 font-medium">Snippet</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($sarifRows as $row)
                                    <tr class="align-top hover:bg-gray-50">
                                        <td class="px-3 py-2 font-mono text-xs">{{ $row['rule_id'] }}</td>
                                        <td class="px-3 py-2 text-xs">{{ $row['severity'] }}</td>
                                        <td class="px-3 py-2 font-mono text-xs">{{ $row['location'] }}</td>
                                        <td class="px-3 py-2">
                                            @if ($row['snippet'] !== '')
                                                <div class="overflow-x-auto rounded border border-gray-200 bg-white p-2 text-xs shadow-sm">{!! $row['snippet_html'] !!}</div>
                                            @else
                                                <span class="text-xs text-gray-400">n/a</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </x-filament::section>

        {{-- ── Raw Evidence ──────────────────────────────────────────────── --}}
        <x-filament::section heading="Raw Evidence" collapsible collapsed>
            <p class="mb-3 text-xs text-gray-500">Normalised event fields, metadata, and raw source payload. Sensitive values are redacted.</p>
            <pre class="max-h-96 overflow-auto rounded bg-gray-950 p-4 text-xs text-gray-100">{{ json_encode($rawEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
        </x-filament::section>

    </div>
</x-filament-panels::page>
