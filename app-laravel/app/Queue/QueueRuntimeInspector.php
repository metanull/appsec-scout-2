<?php

namespace App\Queue;

use App\Sync\FetchSourceJob;
use App\Trackers\RefreshWorkItemsJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class QueueRuntimeInspector
{
    public function queuedCount(): int
    {
        $connectionName = $this->queueConnectionName();
        $total = 0;

        foreach ($this->queueNames() as $queueName) {
            $total += max(0, (int) Queue::connection($connectionName)->size($queueName));
        }

        return $total;
    }

    /**
     * @return array{source: list<string>, tracker: list<string>}
     */
    public function queuedIntegrationIds(): array
    {
        $sourceIds = [];
        $trackerIds = [];

        foreach ($this->pendingPayloads() as $payload) {
            $command = $this->deserializeCommand($payload);

            if ($command instanceof FetchSourceJob) {
                $sourceIds[] = $command->sourceId;

                continue;
            }

            if ($command instanceof RefreshWorkItemsJob && is_string($command->trackerId) && $command->trackerId !== '') {
                $trackerIds[] = $command->trackerId;
            }
        }

        return [
            'source' => array_values(array_unique($sourceIds)),
            'tracker' => array_values(array_unique($trackerIds)),
        ];
    }

    /** @return list<string> */
    private function pendingPayloads(): array
    {
        $connectionName = $this->queueConnectionName();
        $connectionConfig = config("queue.connections.{$connectionName}");

        if (! is_array($connectionConfig)) {
            return [];
        }

        $driver = (string) ($connectionConfig['driver'] ?? '');

        return match ($driver) {
            'database' => $this->databasePayloads($connectionConfig),
            'redis' => $this->redisPayloads($connectionConfig),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $connectionConfig
     * @return list<string>
     */
    private function databasePayloads(array $connectionConfig): array
    {
        $table = is_string($connectionConfig['table'] ?? null) ? $connectionConfig['table'] : 'jobs';
        $dbConnection = is_string($connectionConfig['connection'] ?? null) && $connectionConfig['connection'] !== ''
            ? $connectionConfig['connection']
            : null;

        $payloads = DB::connection($dbConnection)
            ->table($table)
            ->pluck('payload')
            ->filter(fn (mixed $payload): bool => is_string($payload) && $payload !== '')
            ->values()
            ->all();

        /** @var list<string> $payloads */
        return $payloads;
    }

    /**
     * @param  array<string, mixed>  $connectionConfig
     * @return list<string>
     */
    private function redisPayloads(array $connectionConfig): array
    {
        $redisConnection = is_string($connectionConfig['connection'] ?? null) && $connectionConfig['connection'] !== ''
            ? $connectionConfig['connection']
            : 'default';

        $payloads = [];

        foreach ($this->queueNames() as $queueName) {
            try {
                $queuePayloads = Redis::connection($redisConnection)->lrange("queues:{$queueName}", 0, -1);
            } catch (Throwable) {
                continue;
            }

            foreach ($queuePayloads as $payload) {
                if (is_string($payload) && $payload !== '') {
                    $payloads[] = $payload;
                }
            }
        }

        return $payloads;
    }

    /** @return list<string> */
    private function queueNames(): array
    {
        $connectionName = $this->queueConnectionName();
        $connectionConfig = config("queue.connections.{$connectionName}");

        if (! is_array($connectionConfig)) {
            return ['default'];
        }

        $configuredQueue = $connectionConfig['queue'] ?? 'default';

        $queues = match (true) {
            is_array($configuredQueue) => $configuredQueue,
            is_string($configuredQueue) => explode(',', $configuredQueue),
            default => ['default'],
        };

        $normalized = array_values(array_unique(array_filter(array_map(
            fn (mixed $queue): string => trim((string) $queue),
            $queues,
        ), fn (string $queue): bool => $queue !== '')));

        return $normalized === [] ? ['default'] : $normalized;
    }

    private function queueConnectionName(): string
    {
        return (string) config('queue.default', 'database');
    }

    private function deserializeCommand(string $payload): ?object
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return null;
        }

        $command = $decoded['data']['command'] ?? null;

        if (! is_string($command) || $command === '') {
            return null;
        }

        try {
            $unserialized = unserialize($command, [
                'allowed_classes' => [
                    FetchSourceJob::class,
                    RefreshWorkItemsJob::class,
                ],
            ]);
        } catch (Throwable) {
            return null;
        }

        return is_object($unserialized) ? $unserialized : null;
    }
}
