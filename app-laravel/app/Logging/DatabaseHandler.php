<?php

namespace App\Logging;

use App\Models\ErrorLog;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

class DatabaseHandler extends AbstractProcessingHandler
{
    public function __construct(Level $level = Level::Error)
    {
        parent::__construct($level);
    }

    protected function write(LogRecord $record): void
    {
        $context = $record->context;
        $trace = $this->extractTrace($context);

        ErrorLog::create([
            'level' => strtoupper($record->level->name),
            'channel' => $record->channel,
            'message' => $record->message,
            'context_json' => $context !== [] ? $context : null,
            'trace' => $trace,
            'occurred_at' => $record->datetime->format('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<string, mixed> $context */
    private function extractTrace(array &$context): ?string
    {
        $exception = $context['exception'] ?? null;

        if (! $exception instanceof \Throwable) {
            return null;
        }

        unset($context['exception']);

        return $exception->getTraceAsString();
    }
}
