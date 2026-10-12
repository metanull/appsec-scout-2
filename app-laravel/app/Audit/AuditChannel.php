<?php

namespace App\Audit;

/**
 * Names the surface an action came through (e.g. "mcp") so Recorder can tag every audit
 * entry written while it is set. Bound as a scoped instance: it lives for one request, one
 * queued job, or one long-running console process, and never travels into dispatched jobs
 * (unlike Laravel Context), which run with system credentials rather than as the user.
 */
final class AuditChannel
{
    private ?string $channel = null;

    public function set(string $channel): void
    {
        $this->channel = $channel;
    }

    public function current(): ?string
    {
        return $this->channel;
    }
}
