<?php

namespace App\Triage;

use App\Audit\Recorder;
use App\Models\SecurityEvent;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class TrivyService
{
    private const OUTPUT_LIMIT_BYTES = 100000000;

    private const TIMEOUT_SECONDS = 300.0;

    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly BinaryResolver $binaries,
        private readonly GitUrlValidator $gitUrlValidator,
        private readonly ProcessRunner $runner,
        private readonly Recorder $recorder,
    ) {}

    public function run(string $gitUrl, ?int $attachToEventId = null, ?int $createdByUserId = null): TrivyRunResult
    {
        $validatedUrl = $this->gitUrlValidator->validate($gitUrl);
        $workspace = storage_path('app/triage/' . (string) Str::uuid());
        $clonePath = $workspace . '/clone';
        $sarifPath = $workspace . '/trivy.sarif';

        File::ensureDirectoryExists($workspace);

        try {
            $this->runner->run([
                $this->binaries->resolve('git'),
                'clone',
                '--depth',
                '1',
                '--no-tags',
                '--',
                $validatedUrl,
                $clonePath,
            ], timeoutSeconds: self::TIMEOUT_SECONDS, outputLimitBytes: self::OUTPUT_LIMIT_BYTES);

            $this->runner->run([
                $this->binaries->resolve('trivy'),
                'fs',
                '--quiet',
                '--format',
                'sarif',
                '--output',
                $sarifPath,
                '--skip-db-update',
                $clonePath,
            ], timeoutSeconds: self::TIMEOUT_SECONDS, outputLimitBytes: self::OUTPUT_LIMIT_BYTES);

            $sarif = $this->readFileWithinLimit($sarifPath, self::OUTPUT_LIMIT_BYTES);

            if ($attachToEventId === null) {
                return new TrivyRunResult($sarif, null);
            }

            $event = SecurityEvent::query()->findOrFail($attachToEventId);
            $attachment = $this->attachments->attachToEvent(
                event: $event,
                kind: 'trivy-sarif',
                mime: 'application/sarif+json',
                name: sprintf('trivy-%s.sarif', now()->format('Ymd-His')),
                payload: $sarif,
                createdByUserId: $createdByUserId,
                createdByCommand: 'triage:trivy',
            );

            $this->recorder->recordTriageRun(SecurityEvent::class, (string) $event->id, [
                'command' => 'trivy',
                'attachment_id' => $attachment->id,
            ]);

            return new TrivyRunResult($sarif, $attachment->id);
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    private function readFileWithinLimit(string $path, int $limitBytes): string
    {
        if (! File::exists($path)) {
            throw new \RuntimeException(sprintf('Expected output file [%s] was not created.', $path));
        }

        $size = File::size($path);

        if ($size > $limitBytes) {
            throw new \RuntimeException('Output file exceeded the configured size limit.');
        }

        $contents = File::get($path);

        if (strlen($contents) > $limitBytes) {
            throw new \RuntimeException('Output file exceeded the configured size limit.');
        }

        return $contents;
    }
}
