<x-filament-panels::page>
    @php
        /** @var \App\Models\SecurityEvent $record */
        $record = $this->getRecord();
        $sections = $this->visibleSections();
        $metadata = is_array($record->metadata) ? $record->metadata : [];
        $validation = is_array($metadata['validationFingerprints'] ?? null) ? $metadata['validationFingerprints'] : [];
        $occurrences = is_array($metadata['occurrences'] ?? null) ? $metadata['occurrences'] : [];
        $tags = is_array($metadata['tags'] ?? null) ? $metadata['tags'] : [];
        $auditRows = $this->auditRows();
        $workItemLinks = $this->workItemLinks();
        $attachments = $this->attachments();
        $sarifRows = $this->sarifRows();
    @endphp

    <div class="space-y-6" wire:poll.3s>
        <x-filament::section heading="Alert Summary">
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <div class="text-sm text-gray-500">Title</div>
                    <div class="font-medium">{{ $record->title }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Source</div>
                    <div>{{ $record->source_id }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Severity / State</div>
                    <div>{{ $record->severity->value }} / {{ $record->state->value }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">First / Last Seen</div>
                    <div>{{ optional($record->first_seen_at)->toDateTimeString() }} - {{ optional($record->last_seen_at)->toDateTimeString() }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Fingerprint</div>
                    <div class="break-all">{{ $record->fingerprint ?? 'n/a' }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500">Source Link</div>
                    @if ($record->url)
                        <a class="text-primary-600 underline" href="{{ $record->url }}" target="_blank" rel="noopener">Open source alert</a>
                    @else
                        <span>n/a</span>
                    @endif
                </div>
            </div>
        </x-filament::section>

        @if (in_array('secret', $sections, true))
            <x-filament::section heading="Secret Details">
                <div class="space-y-2 text-sm">
                    <div><strong>Detector:</strong> {{ $metadata['detector'] ?? 'n/a' }}</div>
                    <div><strong>Validation fingerprints:</strong> {{ count($validation) }}</div>
                    <div><strong>Truncated value:</strong> {{ $metadata['truncatedSecret'] ?? 'n/a' }}</div>
                    <div><strong>Occurrences loaded:</strong> {{ count($occurrences) }}</div>
                    <x-filament::button wire:click="loadSecretOccurrences" color="gray" size="sm">Load occurrences</x-filament::button>
                </div>
            </x-filament::section>
        @endif

        @if (in_array('dependency', $sections, true))
            <x-filament::section heading="Dependency Details">
                @php $package = is_array($metadata['package'] ?? null) ? $metadata['package'] : []; @endphp
                <div class="space-y-2 text-sm">
                    <div><strong>Package:</strong> {{ $package['name'] ?? 'n/a' }} {{ $package['version'] ?? '' }}</div>
                    <div><strong>CVE:</strong>
                        @if (! empty($metadata['cve']))
                            <a class="text-primary-600 underline" href="https://nvd.nist.gov/vuln/detail/{{ $metadata['cve'] }}" target="_blank" rel="noopener">{{ $metadata['cve'] }}</a>
                        @else
                            n/a
                        @endif
                    </div>
                    <div><strong>CVSS:</strong> {{ $metadata['cvss'] ?? 'n/a' }}</div>
                    <div><strong>Fixed in:</strong> {{ $metadata['fixedInVersion'] ?? 'n/a' }}</div>
                </div>
            </x-filament::section>
        @endif

        @if (in_array('code_location', $sections, true))
            <x-filament::section heading="Code Location">
                <div class="space-y-2 text-sm">
                    <div>
                        <strong>File:</strong>
                        @if ($record->version_control_url)
                            <a class="text-primary-600 underline" href="{{ $record->version_control_url }}" target="_blank" rel="noopener">{{ $record->file_path ?? 'n/a' }}</a>
                        @else
                            {{ $record->file_path ?? 'n/a' }}
                        @endif
                    </div>
                    <div><strong>Lines:</strong> {{ $record->start_line ?? 'n/a' }} - {{ $record->end_line ?? 'n/a' }}</div>
                    <div><strong>Rule:</strong> {{ $record->rule_id ?? 'n/a' }}</div>
                    <div><strong>CWE:</strong> {{ $metadata['cwe'] ?? 'n/a' }}</div>
                    @if ($record->snippet)
                        <pre class="overflow-x-auto rounded bg-gray-950 p-3 text-xs text-white"><code>{{ $record->snippet }}</code></pre>
                    @endif
                </div>
            </x-filament::section>
        @endif

        @if (in_array('posture', $sections, true))
            <x-filament::section heading="Misconfiguration / Posture">
                <div class="space-y-2 text-sm">
                    <div><strong>Resource Type:</strong> {{ $metadata['resourceType'] ?? 'n/a' }}</div>
                    <div><strong>Recommendation:</strong> {{ $metadata['recommendation'] ?? 'n/a' }}</div>
                    <div>
                        <strong>Documentation:</strong>
                        @if (! empty($metadata['documentationUrl']))
                            <a class="text-primary-600 underline" href="{{ $metadata['documentationUrl'] }}" target="_blank" rel="noopener">Open docs</a>
                        @else
                            n/a
                        @endif
                    </div>
                </div>
            </x-filament::section>
        @endif

        <x-filament::section heading="Remediation">
            <div class="prose max-w-none dark:prose-invert">{!! $this->remediationHtml() !!}</div>
        </x-filament::section>

        <x-filament::section heading="Comments">
            <div class="space-y-4">
                @can('alerts.edit')
                    <div class="rounded border border-dashed p-4">
                        <label class="mb-2 block text-sm font-medium text-gray-700" for="new-comment-body">Add local comment</label>
                        <textarea
                            id="new-comment-body"
                            wire:model="newCommentBody"
                            rows="4"
                            class="w-full rounded-lg border-gray-300 text-sm shadow-sm"
                        ></textarea>
                        @error('newCommentBody')
                            <div class="mt-2 text-sm text-danger-600">{{ $message }}</div>
                        @enderror
                        <div class="mt-3 flex justify-end">
                            <x-filament::button wire:click="addComment" size="sm">Add comment</x-filament::button>
                        </div>
                    </div>
                @endcan

                @forelse ($this->comments() as $comment)
                    <div class="rounded border p-3 text-sm">
                        <div class="mb-2 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                            <span>{{ optional($comment->created_at)->toDateTimeString() }}</span>
                            @if ($comment->upstream_comment_id)
                                <x-filament::badge color="gray">From source</x-filament::badge>
                            @elseif ($comment->author)
                                <x-filament::badge color="info">{{ $comment->author->name }}</x-filament::badge>
                            @else
                                <x-filament::badge color="gray">Local</x-filament::badge>
                            @endif
                            @if ($this->canEditComment($comment))
                                <span>Editable for 5 minutes</span>
                            @endif
                        </div>

                        @if ($editingCommentId === $comment->id)
                            <textarea
                                wire:model="editingCommentBody"
                                rows="4"
                                class="w-full rounded-lg border-gray-300 text-sm shadow-sm"
                            ></textarea>
                            @error('editingCommentBody')
                                <div class="mt-2 text-sm text-danger-600">{{ $message }}</div>
                            @enderror
                            <div class="mt-3 flex justify-end gap-2">
                                <x-filament::button wire:click="cancelEditingComment" color="gray" size="sm">Cancel</x-filament::button>
                                <x-filament::button wire:click="saveCommentEdit" size="sm">Save</x-filament::button>
                            </div>
                        @else
                            <div>{{ $comment->body }}</div>

                            @if ($this->canEditComment($comment))
                                <div class="mt-3 flex justify-end">
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

        <x-filament::section heading="Audit History">
            @forelse ($auditRows as $row)
                <div class="mb-2 text-sm">
                    <span class="font-medium">{{ $row->action }}</span>
                    <span class="text-gray-500">({{ optional($row->created_at)->toDateTimeString() }})</span>
                </div>
            @empty
                <div class="text-sm text-gray-500">No audit records found.</div>
            @endforelse
        </x-filament::section>

        <x-filament::section heading="Work Items">
            @if ($workItemLinks->isEmpty())
                <div class="text-sm text-gray-500">No linked work item.</div>
            @else
                <div class="space-y-3">
                    @foreach ($workItemLinks as $link)
                        <div class="rounded border p-3 text-sm">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-filament::badge color="gray">{{ $link->tracker_id }}</x-filament::badge>
                                @if ($link->work_item_state)
                                    <x-filament::badge color="info">{{ $link->work_item_state }}</x-filament::badge>
                                @endif
                                <span class="font-medium">{{ $link->work_item_title ?? $link->work_item_id }}</span>
                            </div>
                            <div class="mt-2 text-gray-500">{{ $link->work_item_id }}</div>
                            @if ($link->work_item_url)
                                <div class="mt-2">
                                    <a class="text-primary-600 underline" href="{{ $link->work_item_url }}" target="_blank" rel="noopener">Open work item</a>
                                </div>
                            @endif
                            @can('work-items.link')
                                <div class="mt-3 flex justify-end">
                                    <x-filament::button wire:click="unlinkWorkItem({{ $link->id }})" color="gray" size="sm">Unlink</x-filament::button>
                                </div>
                            @endcan
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($tags !== [])
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($tags as $tag)
                        <x-filament::badge color="gray">{{ $tag }}</x-filament::badge>
                    @endforeach
                </div>
            @endif
        </x-filament::section>

        <x-filament::section heading="Attachments">
            @if ($attachments->isEmpty())
                <div class="text-sm text-gray-500">No attachments yet.</div>
            @else
                <div class="space-y-3">
                    @foreach ($attachments as $attachment)
                        <div class="rounded border p-3 text-sm">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">{{ $attachment->name }}</span>
                                <x-filament::badge color="gray">{{ $attachment->kind }}</x-filament::badge>
                                <span class="text-gray-500">{{ $attachment->mime }}</span>
                                <span class="text-gray-500">{{ $this->formatBytes($attachment->size_bytes) }}</span>
                            </div>
                            <div class="mt-2 text-xs text-gray-500">{{ optional($attachment->created_at)->toDateTimeString() }}</div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <a class="text-primary-600 underline" href="{{ $this->downloadAttachmentUrl($attachment) }}">Download</a>
                                @can('work-items.create')
                                    <x-filament::button wire:click="deleteAttachment({{ $attachment->id }})" color="gray" size="sm">Delete</x-filament::button>
                                @endcan
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            @if ($sarifRows !== [])
                <div class="mt-4 overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="px-2 py-1">Rule</th>
                                <th class="px-2 py-1">Severity</th>
                                <th class="px-2 py-1">Location</th>
                                <th class="px-2 py-1">Snippet</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($sarifRows as $row)
                                <tr class="border-t align-top">
                                    <td class="px-2 py-2">{{ $row['rule_id'] }}</td>
                                    <td class="px-2 py-2">{{ $row['severity'] }}</td>
                                    <td class="px-2 py-2">{{ $row['location'] }}</td>
                                    <td class="px-2 py-2">
                                        @if ($row['snippet'] !== '')
                                            <pre class="overflow-x-auto rounded bg-gray-950 p-3 text-xs text-white"><code>{{ $row['snippet'] }}</code></pre>
                                        @else
                                            <span class="text-gray-500">n/a</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
