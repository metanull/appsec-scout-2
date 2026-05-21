<?php

namespace App\Triage;

use App\Audit\Recorder;
use App\Models\SecurityEvent;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class BfgService
{
    private const BUNDLE_LIMIT_BYTES = 50000000;

    private const OUTPUT_LIMIT_BYTES = 100000000;

    private const SECRET_LIST_LIMIT_BYTES = 1000000;

    private const TIMEOUT_SECONDS = 300.0;

    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly BinaryResolver $binaries,
        private readonly GitUrlValidator $gitUrlValidator,
        private readonly ProcessRunner $runner,
        private readonly Recorder $recorder,
    ) {}

    public function run(string $gitUrl, string $secretListFile, ?int $attachToEventId = null, ?int $createdByUserId = null): BfgRunResult
    {
        $validatedUrl = $this->gitUrlValidator->validate($gitUrl);
        $secretListPath = $this->validateSecretList($secretListFile);
        $workspace = storage_path('app/triage/' . (string) Str::uuid());
        $repoPath = $workspace . '/repo.git';
        $bundlePath = $workspace . '/rewritten.bundle';

        File::ensureDirectoryExists($workspace);

        try {
            $this->runner->run([
                $this->binaries->resolve('git'),
                'clone',
                '--mirror',
                '--',
                $validatedUrl,
                $repoPath,
            ], timeoutSeconds: self::TIMEOUT_SECONDS, outputLimitBytes: self::OUTPUT_LIMIT_BYTES);

            $bfgRun = $this->runner->run([
                $this->binaries->resolve('java'),
                '-jar',
                '/opt/bfg/bfg.jar',
                '--replace-text',
                $secretListPath,
                $repoPath,
            ], timeoutSeconds: self::TIMEOUT_SECONDS, outputLimitBytes: self::OUTPUT_LIMIT_BYTES);

            $this->runner->run([
                $this->binaries->resolve('git'),
                '-C',
                $repoPath,
                'reflog',
                'expire',
                '--expire=now',
                '--all',
            ], timeoutSeconds: self::TIMEOUT_SECONDS, outputLimitBytes: self::OUTPUT_LIMIT_BYTES);

            $this->runner->run([
                $this->binaries->resolve('git'),
                '-C',
                $repoPath,
                'gc',
                '--prune=now',
                '--aggressive',
            ], timeoutSeconds: self::TIMEOUT_SECONDS, outputLimitBytes: self::OUTPUT_LIMIT_BYTES);

            $this->runner->run([
                $this->binaries->resolve('git'),
                '-C',
                $repoPath,
                'bundle',
                'create',
                $bundlePath,
                '--all',
            ], timeoutSeconds: self::TIMEOUT_SECONDS, outputLimitBytes: self::OUTPUT_LIMIT_BYTES);

            $report = trim($bfgRun->stdout . PHP_EOL . $bfgRun->stderr);
            $bundle = $this->readFileWithinLimit($bundlePath, self::BUNDLE_LIMIT_BYTES);

            if ($attachToEventId === null) {
                return new BfgRunResult($report, $bundle, null, null);
            }

            $event = SecurityEvent::query()->findOrFail($attachToEventId);
            $reportAttachment = $this->attachments->attachToEvent(
                event: $event,
                kind: 'bfg-report',
                mime: 'text/plain',
                name: sprintf('bfg-report-%s.txt', now()->format('Ymd-His')),
                payload: $report,
                createdByUserId: $createdByUserId,
                createdByCommand: 'triage:bfg',
            );
            $bundleAttachment = $this->attachments->attachToEvent(
                event: $event,
                kind: 'bfg-bundle',
                mime: 'application/octet-stream',
                name: sprintf('bfg-bundle-%s.bundle', now()->format('Ymd-His')),
                payload: $bundle,
                createdByUserId: $createdByUserId,
                createdByCommand: 'triage:bfg',
            );

            $this->recorder->recordTriageRun(SecurityEvent::class, (string) $event->id, [
                'command' => 'bfg',
                'report_attachment_id' => $reportAttachment->id,
                'bundle_attachment_id' => $bundleAttachment->id,
            ]);

            return new BfgRunResult($report, $bundle, $reportAttachment->id, $bundleAttachment->id);
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    private function validateSecretList(string $secretListFile): string
    {
        if (! File::exists($secretListFile) || ! File::isFile($secretListFile) || ! is_readable($secretListFile)) {
            throw new \InvalidArgumentException('The secret list file must exist and be readable.');
        }

        if (File::size($secretListFile) > self::SECRET_LIST_LIMIT_BYTES) {
            throw new \InvalidArgumentException('The secret list file exceeds the 1 MB limit.');
        }

        return $secretListFile;
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

        return File::get($path);
    }
}
