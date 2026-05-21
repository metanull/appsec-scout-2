<?php

namespace App\Jobs;

use App\Triage\BinaryResolver;
use App\Triage\ProcessRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class UpdateTrivyDbJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(BinaryResolver $binaries, ProcessRunner $runner): void
    {
        $runner->run([
            $binaries->resolve('trivy'),
            'image',
            '--download-db-only',
            '--quiet',
        ], timeoutSeconds: 300, outputLimitBytes: 10000000);
    }
}
