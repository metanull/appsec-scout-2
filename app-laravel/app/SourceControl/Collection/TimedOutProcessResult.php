<?php

namespace App\SourceControl\Collection;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;

/**
 * The result of a step that exceeded its timeout. Laravel throws instead of
 * returning a result in that case; this wraps the partial result the exception
 * carries so the caller can treat the step as failed like any other failure.
 *
 * The timeout message is appended to the output, not the error output: the
 * job keeps the tail of error output followed by output, so it stays the
 * last line of what gets logged even when the step wrote a lot.
 */
final class TimedOutProcessResult implements ProcessResult
{
    public function __construct(private readonly ProcessTimedOutException $exception) {}

    public function command(): string
    {
        return $this->exception->result->command();
    }

    public function successful(): bool
    {
        return false;
    }

    public function failed(): bool
    {
        return true;
    }

    public function exitCode(): ?int
    {
        return $this->exception->result->exitCode();
    }

    public function output(): string
    {
        return ltrim($this->exception->result->output() . "\n" . $this->exception->getMessage());
    }

    public function seeInOutput(string $output): bool
    {
        return str_contains($this->output(), $output);
    }

    public function errorOutput(): string
    {
        return $this->exception->result->errorOutput();
    }

    public function seeInErrorOutput(string $output): bool
    {
        return str_contains($this->errorOutput(), $output);
    }

    public function throw(?callable $callback = null): never
    {
        throw $this->exception;
    }

    public function throwIf(bool $condition, ?callable $callback = null): static
    {
        if ($condition) {
            $this->throw($callback);
        }

        return $this;
    }
}
