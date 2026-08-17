<?php

use App\Queue\QueueRuntimeInspector;
use Illuminate\Support\Facades\DB;

function insertQueuedJob(string $queue): void
{
    DB::table('jobs')->insert([
        'queue' => $queue,
        'payload' => '{}',
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);
}

it('counts jobs on the app default queue', function () {
    config(['queue.default' => 'database']);

    insertQueuedJob('default');
    insertQueuedJob('default');

    expect(app(QueueRuntimeInspector::class)->queuedCount())->toBe(2);
});

it('also counts jobs on the repository-collection queue, which is never in queue.connections.*.queue', function () {
    config(['queue.default' => 'database']);

    insertQueuedJob('default');
    insertQueuedJob('repository-collection');
    insertQueuedJob('repository-collection');

    expect(app(QueueRuntimeInspector::class)->queuedCount())->toBe(3);
});

it('also counts jobs on the static-analysis queue, which is never in queue.connections.*.queue', function () {
    config(['queue.default' => 'database']);

    insertQueuedJob('default');
    insertQueuedJob('static-analysis');
    insertQueuedJob('static-analysis');

    expect(app(QueueRuntimeInspector::class)->queuedCount())->toBe(3);
});

it('counts repository-collection and static-analysis jobs together', function () {
    config(['queue.default' => 'database']);

    insertQueuedJob('repository-collection');
    insertQueuedJob('static-analysis');

    expect(app(QueueRuntimeInspector::class)->queuedCount())->toBe(2);
});

it('sums every queue name in a comma-separated queue.connections.*.queue config', function () {
    config([
        'queue.default' => 'database',
        'queue.connections.database.queue' => 'default,high',
    ]);

    insertQueuedJob('default');
    insertQueuedJob('high');

    expect(app(QueueRuntimeInspector::class)->queuedCount())->toBe(2);
});
