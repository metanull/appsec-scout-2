<?php

namespace App\Triage;

final class BfgRunResult
{
    public function __construct(
        public readonly string $report,
        public readonly string $bundle,
        public readonly ?int $reportAttachmentId,
        public readonly ?int $bundleAttachmentId,
    ) {}
}
