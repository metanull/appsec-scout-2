<?php

use App\Jobs\PruneErrorLogs;
use App\Logging\DatabaseHandler;
use App\Models\ErrorLog;
use Monolog\Level;
use Monolog\LogRecord;

it('writes ERROR level logs to the database', function () {
    $handler = new DatabaseHandler(Level::Error);
    $record = new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'app',
        level: Level::Error,
        message: 'Something went wrong',
        context: ['key' => 'value'],
    );

    $handler->handle($record);

    expect(ErrorLog::count())->toBe(1);
    $log = ErrorLog::first();
    expect($log->level)->toBe('ERROR')
        ->and($log->channel)->toBe('app')
        ->and($log->message)->toBe('Something went wrong')
        ->and($log->context_json)->toEqual(['key' => 'value']);
});

it('extracts exception trace into separate column', function () {
    $exception = new RuntimeException('Test exception');
    $handler = new DatabaseHandler(Level::Error);
    $record = new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'app',
        level: Level::Error,
        message: 'Error with exception',
        context: ['exception' => $exception],
    );

    $handler->handle($record);

    $log = ErrorLog::first();
    expect($log->trace)->not()->toBeNull()
        ->and($log->context_json)->not()->toHaveKey('exception');
});

it('does not write DEBUG logs (below ERROR threshold)', function () {
    $handler = new DatabaseHandler(Level::Error);
    $record = new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'app',
        level: Level::Debug,
        message: 'Debug message',
        context: [],
    );

    $handler->handle($record);

    expect(ErrorLog::count())->toBe(0);
});

it('prunes error logs older than retain days', function () {
    ErrorLog::create([
        'level' => 'ERROR',
        'message' => 'Old error',
        'occurred_at' => now()->subDays(100),
    ]);
    ErrorLog::create([
        'level' => 'ERROR',
        'message' => 'Recent error',
        'occurred_at' => now()->subDays(10),
    ]);

    (new PruneErrorLogs(90))->handle();

    expect(ErrorLog::count())->toBe(1)
        ->and(ErrorLog::first()->message)->toBe('Recent error');
});
