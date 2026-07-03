<?php

namespace App\CuratedLinks;

use App\Audit\Recorder;
use App\Models\CuratedLink;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Models\User;
use App\SecurityEvents\SourceLinkHelper;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CuratedLinkService
{
    public function __construct(private readonly Recorder $recorder) {}

    /**
     * @param  array{label?: mixed, url?: mixed, kind?: mixed}  $data
     */
    public function create(SecurityEvent|SecurityContainer|SoftwareSystem|SoftwareAsset $owner, User $author, array $data): CuratedLink
    {
        $payload = $this->normalize($data);

        return DB::transaction(function () use ($owner, $author, $payload): CuratedLink {
            $link = $owner->curatedLinks()->create([
                'label' => $payload['label'],
                'url' => $payload['url'],
                'kind' => $payload['kind'],
                'created_by_user_id' => $author->id,
            ]);

            $this->recorder->recordAdminAction('curated_link_created', [
                'curated_link_id' => $link->id,
                'owner_type' => $link->owner_type,
                'owner_id' => $link->owner_id,
                'label' => $link->label,
                'kind' => $link->kind,
                'url' => $link->url,
            ]);

            return $link->refresh();
        });
    }

    /**
     * @param  array{label?: mixed, url?: mixed, kind?: mixed}  $data
     */
    public function update(CuratedLink $link, User $author, array $data): CuratedLink
    {
        $payload = $this->normalize($data);

        return DB::transaction(function () use ($link, $author, $payload): CuratedLink {
            $link->forceFill([
                'label' => $payload['label'],
                'url' => $payload['url'],
                'kind' => $payload['kind'],
            ])->save();

            $this->recorder->recordAdminAction('curated_link_updated', [
                'curated_link_id' => $link->id,
                'owner_type' => $link->owner_type,
                'owner_id' => $link->owner_id,
                'label' => $link->label,
                'kind' => $link->kind,
                'url' => $link->url,
                'author_id' => $author->id,
            ]);

            return $link->refresh();
        });
    }

    public function delete(CuratedLink $link, User $author): void
    {
        DB::transaction(function () use ($link, $author): void {
            $this->recorder->recordAdminAction('curated_link_deleted', [
                'curated_link_id' => $link->id,
                'owner_type' => $link->owner_type,
                'owner_id' => $link->owner_id,
                'label' => $link->label,
                'kind' => $link->kind,
                'url' => $link->url,
                'author_id' => $author->id,
            ]);

            $link->delete();
        });
    }

    /**
     * @param  array{label?: mixed, url?: mixed, kind?: mixed}  $data
     * @return array{label: string, url: string, kind: string}
     */
    private function normalize(array $data): array
    {
        $label = trim((string) ($data['label'] ?? ''));

        if ($label === '') {
            throw ValidationException::withMessages([
                'label' => 'A label is required.',
            ]);
        }

        $url = trim((string) ($data['url'] ?? ''));

        if ($url === '' || ! SourceLinkHelper::isSafeUrl($url)) {
            throw ValidationException::withMessages([
                'url' => 'The URL must use http or https.',
            ]);
        }

        $kind = strtolower(trim((string) ($data['kind'] ?? '')));

        if (! in_array($kind, CuratedLink::ALLOWED_KINDS, true)) {
            throw ValidationException::withMessages([
                'kind' => 'The selected kind is invalid.',
            ]);
        }

        return [
            'label' => $label,
            'url' => $url,
            'kind' => $kind,
        ];
    }
}
