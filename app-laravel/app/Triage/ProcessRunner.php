<?php

namespace App\Triage;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class ProcessRunner
{
    /**
     * @param  list<string>  $command
     */
    public function run(
        array $command,
        ?string $workingDirectory = null,
        float $timeoutSeconds = 300,
        int $outputLimitBytes = 100000000,
    ): ProcessRunResult {
        $process = new Process($command, $workingDirectory);
        $process->setTimeout($timeoutSeconds);

        $stdout = '';
        $stderr = '';

        $process->start();

        foreach ($process as $type => $buffer) {
            if ($type === Process::OUT) {
                $stdout .= $buffer;
            } else {
                $stderr .= $buffer;
            }

            if (strlen($stdout) + strlen($stderr) > $outputLimitBytes) {
                $process->stop(0);

                throw new ProcessOutputLimitExceeded('Process output exceeded the configured limit.');
            }
        }

        $process->wait();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return new ProcessRunResult(
            command: $command,
            stdout: $stdout,
            stderr: $stderr,
            exitCode: $process->getExitCode() ?? 0,
        );
    }
}
