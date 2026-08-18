<?php

use App\Jobs\PruneErrorLogs;
use App\Models\ErrorLog;
use Illuminate\Support\Facades\DB;

it('prunes error logs older than retain days', function () {
    DB::table('error_logs')->insert([
        'level' => 'error',
        'message' => 'old error',
        'occurred_at' => now()->subDays(120),
    ]);
    DB::table('error_logs')->insert([
        'level' => 'error',
        'message' => 'recent error',
        'occurred_at' => now()->subDays(10),
    ]);

    (new PruneErrorLogs(90))->handle();

    expect(ErrorLog::count())->toBe(1)
        ->and(ErrorLog::first()->message)->toBe('recent error');
});

it('honors a configured retention window read from config', function () {
    config(['logging.error_retain_days' => 30]);

    DB::table('error_logs')->insert([
        'level' => 'error',
        'message' => 'outside configured window',
        'occurred_at' => now()->subDays(45),
    ]);
    DB::table('error_logs')->insert([
        'level' => 'error',
        'message' => 'inside configured window',
        'occurred_at' => now()->subDays(10),
    ]);

    (new PruneErrorLogs((int) config('logging.error_retain_days')))->handle();

    expect(ErrorLog::count())->toBe(1)
        ->and(ErrorLog::first()->message)->toBe('inside configured window');
});
