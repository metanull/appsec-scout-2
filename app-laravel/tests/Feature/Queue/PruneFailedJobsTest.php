<?php

use App\Jobs\PruneFailedJobs;
use App\Models\FailedJob;
use Illuminate\Support\Facades\DB;

it('prunes failed jobs older than retain days', function () {
    DB::table('failed_jobs')->insert([
        'uuid' => (string) str()->uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{"job":"Old"}',
        'exception' => 'boom',
        'failed_at' => now()->subDays(120),
    ]);
    DB::table('failed_jobs')->insert([
        'uuid' => (string) str()->uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{"job":"Recent"}',
        'exception' => 'boom',
        'failed_at' => now()->subDays(10),
    ]);

    $deleted = (new PruneFailedJobs(90))->handle();

    expect($deleted)->toBe(1)
        ->and(FailedJob::count())->toBe(1)
        ->and(FailedJob::first()->payload)->toBe('{"job":"Recent"}');
});

it('honors a configured retention window read from config', function () {
    config(['queue.failed.retain_days' => 30]);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) str()->uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{"job":"OutsideWindow"}',
        'exception' => 'boom',
        'failed_at' => now()->subDays(45),
    ]);
    DB::table('failed_jobs')->insert([
        'uuid' => (string) str()->uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{"job":"InsideWindow"}',
        'exception' => 'boom',
        'failed_at' => now()->subDays(10),
    ]);

    (new PruneFailedJobs((int) config('queue.failed.retain_days')))->handle();

    expect(FailedJob::count())->toBe(1)
        ->and(FailedJob::first()->payload)->toBe('{"job":"InsideWindow"}');
});
