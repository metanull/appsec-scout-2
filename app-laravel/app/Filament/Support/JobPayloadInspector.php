<?php

namespace App\Filament\Support;

use App\SourceControl\Collection\AnalyzeRepositoryJob;
use App\SourceControl\Collection\CollectRepositoryJob;
use App\SourceControl\Collection\RepositoryCollectionTarget;
use App\Sync\FetchSourceJob;
use App\Trackers\RefreshWorkItemsJob;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads a queue job's raw Laravel payload JSON — shared by the Failed Jobs
 * and Pending Jobs pages and the recent-failures widget, all of which show
 * the same derived fields for the same payload shape.
 */
final class JobPayloadInspector
{
    public static function jobName(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return 'Unknown job';
        }

        $displayName = $decoded['displayName'] ?? null;

        if (is_string($displayName) && $displayName !== '') {
            return $displayName;
        }

        $commandName = data_get($decoded, 'data.commandName');

        return is_string($commandName) && $commandName !== '' ? $commandName : 'Unknown job';
    }

    public static function exceptionPreview(string $exception): string
    {
        if (str_contains($exception, 'Data too long for column')) {
            $column = Str::between($exception, "Data too long for column '", "'");

            return $column !== ''
                ? "Database value exceeded security_events.{$column}. Run migrations, then retry or forget this failed job."
                : 'Database value exceeded a column size. Run migrations, then retry or forget this failed job.';
        }

        return Str::limit($exception, 1000);
    }

    /**
     * The source/tracker a job's payload concerns, tried first as plain JSON
     * properties (most job payloads) and falling back to the job command
     * object itself when the command was PHP-serialized rather than
     * exposing its properties as JSON (e.g. CollectRepositoryJob,
     * AnalyzeRepositoryJob, FetchSourceJob, RefreshWorkItemsJob all encode
     * `data.command` as a serialized object, not a JSON map).
     */
    public static function sourceOrTracker(string $payload): ?string
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return null;
        }

        $sourceId = data_get($decoded, 'data.command.sourceId')
            ?? data_get($decoded, 'data.command.source_id')
            ?? data_get($decoded, 'data.sourceId')
            ?? null;

        if (is_string($sourceId) && $sourceId !== '') {
            return "source:{$sourceId}";
        }

        $trackerId = data_get($decoded, 'data.command.trackerId')
            ?? data_get($decoded, 'data.command.tracker_id')
            ?? data_get($decoded, 'data.trackerId')
            ?? null;

        if (is_string($trackerId) && $trackerId !== '') {
            return "tracker:{$trackerId}";
        }

        $command = self::deserializeCommand($decoded);

        if ($command instanceof FetchSourceJob) {
            return "source:{$command->sourceId}";
        }

        if ($command instanceof RefreshWorkItemsJob && is_string($command->trackerId) && $command->trackerId !== '') {
            return "tracker:{$command->trackerId}";
        }

        return null;
    }

    /**
     * The AzDO project/repository a repository-collection or static-analysis
     * job's payload concerns, read from the serialized command's
     * RepositoryCollectionTarget. Not sortable/filterable in SQL — the value
     * lives inside a serialized blob, not a column.
     *
     * @return array{project: string, repository: string}|null
     */
    public static function repositoryTarget(string $payload): ?array
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return null;
        }

        $command = self::deserializeCommand($decoded);

        if (! $command instanceof CollectRepositoryJob && ! $command instanceof AnalyzeRepositoryJob) {
            return null;
        }

        // $command->target is declared RepositoryCollectionTarget (non-nullable)
        // on both job classes, so it is always that type once construction —
        // real or via unserialize() — has succeeded.
        return ['project' => $command->target->projectName, 'repository' => $command->target->repositoryName];
    }

    /** @param array<string, mixed> $decodedPayload */
    private static function deserializeCommand(array $decodedPayload): ?object
    {
        $command = data_get($decodedPayload, 'data.command');

        if (! is_string($command) || $command === '') {
            return null;
        }

        try {
            $unserialized = unserialize($command, [
                'allowed_classes' => [
                    FetchSourceJob::class,
                    RefreshWorkItemsJob::class,
                    CollectRepositoryJob::class,
                    AnalyzeRepositoryJob::class,
                    RepositoryCollectionTarget::class,
                ],
            ]);
        } catch (Throwable) {
            return null;
        }

        return is_object($unserialized) ? $unserialized : null;
    }
}
