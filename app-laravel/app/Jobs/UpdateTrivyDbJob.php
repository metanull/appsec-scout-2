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
        $cacheDir = storage_path('app/trivy/cache');

        if (! is_dir($cacheDir) && ! mkdir($cacheDir, 0775, true) && ! is_dir($cacheDir)) {
            throw new \RuntimeException("Unable to create Trivy cache directory [{$cacheDir}].");
        }

        $runner->run([
            $binaries->resolve('trivy'),
            'image',
            '--download-db-only',
            '--cache-dir',
            $cacheDir,
            '--quiet',
        ], timeoutSeconds: 300, outputLimitBytes: 10000000);
    }
}
