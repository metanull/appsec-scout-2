<?php

namespace App\Filament\Resources\AuditLogResource\Support;

use App\Audit\AuditLog;
use App\Filament\Resources\SecurityContainerResource;
use App\Filament\Resources\SecurityEventResource;
use App\Filament\Resources\SoftwareSystemResource;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;

/**
 * Resolves an AuditLog's polymorphic subject (subject_type/subject_id) to a
 * display name, kind, owning system, and link.
 *
 * subject_type is not a safe Eloquent morph target: most write actions
 * record a real model FQCN there, but Recorder::recordCredentialChange()
 * records the bare label "credential" instead — a real MorphTo would try to
 * instantiate a class literally named "credential" and fail. Only the
 * known-safe model types below are resolved; anything else (including
 * "credential") renders with a name but no lookup and no link.
 */
final class AuditSubjectResolver
{
    private const SAFE_TYPES = [
        SecurityContainer::class,
        SecurityEvent::class,
        SoftwareSystem::class,
    ];

    /**
     * Batched for a whole table page: one query per subject type present,
     * instead of one query per row.
     *
     * @param  array<int, AuditLog>  $records
     * @return array<string, array{name: string, url: string|null, kind: string|null, system: string|null}>
     */
    public static function resolveForRecords(array $records): array
    {
        $idsByType = [];

        foreach ($records as $record) {
            $type = $record->subject_type;
            $id = $record->subject_id;

            if ($type === null || $id === null || ! in_array($type, self::SAFE_TYPES, true)) {
                continue;
            }

            $idsByType[$type][] = $id;
        }

        $map = [];

        foreach ($idsByType as $type => $ids) {
            foreach (self::fetch($type, array_values(array_unique($ids))) as $id => $info) {
                $map["{$type}:{$id}"] = $info;
            }
        }

        return $map;
    }

    /**
     * For a single record (the detail page). Callers rendering more than
     * one entry from this (e.g. Name/Kind/System) should memoize the result
     * themselves rather than call this repeatedly for the same record.
     *
     * @return array{name: string, url: string|null, kind: string|null, system: string|null}|null
     */
    public static function resolveOne(AuditLog $record): ?array
    {
        $type = $record->subject_type;
        $id = $record->subject_id;

        if ($type === null || $id === null || ! in_array($type, self::SAFE_TYPES, true)) {
            return null;
        }

        return self::fetch($type, [$id])[(int) $id] ?? null;
    }

    /**
     * Keyed by the (numeric) record id — PHP normalises a numeric string
     * array key to int, so despite building this with (string) $id, the
     * real key type is int; callers must index accordingly.
     *
     * @param  list<string>  $ids
     * @return array<int, array{name: string, url: string|null, kind: string|null, system: string|null}>
     */
    private static function fetch(string $type, array $ids): array
    {
        $info = [];

        switch ($type) {
            case SecurityContainer::class:
                foreach (SecurityContainer::query()->whereIn('id', $ids)->with('softwareSystem')->get() as $container) {
                    $info[$container->id] = [
                        'name' => $container->name,
                        'url' => SecurityContainerResource::getUrl('view', ['record' => $container]),
                        'kind' => $container->kind,
                        'system' => $container->softwareSystem?->name,
                    ];
                }

                break;
            case SecurityEvent::class:
                foreach (SecurityEvent::query()->whereIn('id', $ids)->get() as $event) {
                    $info[$event->id] = [
                        'name' => $event->title,
                        'url' => SecurityEventResource::getUrl('view', ['record' => $event]),
                        'kind' => null,
                        'system' => null,
                    ];
                }

                break;
            case SoftwareSystem::class:
                foreach (SoftwareSystem::query()->whereIn('id', $ids)->get() as $system) {
                    $info[$system->id] = [
                        'name' => $system->name,
                        'url' => SoftwareSystemResource::getUrl('view', ['record' => $system]),
                        'kind' => null,
                        'system' => null,
                    ];
                }

                break;
        }

        return $info;
    }
}
