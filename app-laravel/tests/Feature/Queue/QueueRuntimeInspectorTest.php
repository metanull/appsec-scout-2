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
