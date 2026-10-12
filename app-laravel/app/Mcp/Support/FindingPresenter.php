<?php

namespace App\Mcp\Support;

use App\Models\EventComment;
use App\Models\LocalFindingComment;
use App\Models\LocalFindingWorkItemLink;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Models\WorkItemLink;
use Illuminate\Database\Eloquent\Model;

/** Payload shapes shared by the alert and local-finding MCP tools, so both read the same way. */
final class FindingPresenter
{
    public const RECENT_COMMENTS = 20;

    /** @return array<string, mixed> */
    public static function comment(EventComment|LocalFindingComment $comment): array
    {
        return [
            'author' => $comment->author?->name,
            'body' => $comment->body,
            'created_at' => Format::date($comment->getAttribute('created_at')),
            'from_source' => $comment instanceof EventComment && $comment->getAttribute('upstream_comment_id') !== null,
        ];
    }

    /** @return array<string, mixed> */
    public static function workItem(WorkItemLink|LocalFindingWorkItemLink $link): array
    {
        return [
            'tracker_id' => $link->tracker_id,
            'work_item_id' => $link->work_item_id,
            'url' => $link->work_item_url,
            'title' => $link->work_item_title,
            'state' => $link->work_item_state,
        ];
    }

    /** @return array{id: int, name: string}|null */
    public static function named(?Model $model): ?array
    {
        if ($model === null) {
            return null;
        }

        return ['id' => (int) $model->getKey(), 'name' => (string) $model->getAttribute('name')];
    }

    /** @return array{type: string, id: int, name: string}|null */
    public static function owner(?Model $owner): ?array
    {
        $type = match (true) {
            $owner instanceof SecurityContainer => 'container',
            $owner instanceof SoftwareSystem => 'system',
            $owner instanceof SoftwareAsset => 'asset',
            default => null,
        };

        if ($type === null) {
            return null;
        }

        return ['type' => $type, 'id' => (int) $owner->getKey(), 'name' => (string) $owner->getAttribute('name')];
    }
}
