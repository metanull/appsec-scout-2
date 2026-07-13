<?php

namespace App\Assets;

use App\Assets\Parsers\CycloneDxSbomParser;
use App\Assets\Parsers\SarifFindingParser;
use App\Models\Attachment;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;

/**
 * Parses a freshly stored Attachment (SBOM, vulnerabilities, secrets, or
 * Roslynator/SpotBugs static analysis) into searchable SoftwareComponent/
 * LocalFinding rows. A no-op for any other attachment kind (e.g. manual
 * uploads).
 */
final class AttachmentIngestionService
{
    public const KIND_SBOM = 'sbom';

    public const KIND_VULNERABILITIES = 'vulnerabilities';

    public const KIND_SECRETS = 'secrets';

    public const KIND_CODE_QUALITY_DOTNET = 'code-quality-dotnet';

    public const KIND_CODE_QUALITY_JAVA = 'code-quality-java';

    public function __construct(
        private readonly CycloneDxSbomParser $sbomParser,
        private readonly SarifFindingParser $sarifParser,
        private readonly SecurityEventCorrelator $correlator,
    ) {}

    public function ingest(Attachment $attachment): void
    {
        $owner = $attachment->owner;

        if (! $owner instanceof SoftwareAsset && ! $owner instanceof SoftwareSystem && ! $owner instanceof SecurityContainer) {
            return;
        }

        match ($attachment->kind) {
            self::KIND_SBOM => $this->ingestSbom($attachment, $owner),
            self::KIND_VULNERABILITIES => $this->ingestFindings($attachment, $owner, LocalFinding::KIND_VULNERABILITY),
            self::KIND_SECRETS => $this->ingestFindings($attachment, $owner, LocalFinding::KIND_SECRET),
            self::KIND_CODE_QUALITY_DOTNET, self::KIND_CODE_QUALITY_JAVA => $this->ingestFindings($attachment, $owner, LocalFinding::KIND_CODE_QUALITY),
            default => null,
        };
    }

    private function ingestSbom(Attachment $attachment, SoftwareAsset|SoftwareSystem|SecurityContainer $owner): void
    {
        foreach ($this->sbomParser->parse($attachment->payload) as $component) {
            $record = $owner->softwareComponents()->firstOrNew(['purl' => $component->purl]);
            $isNew = ! $record->exists;

            $record->fill([
                'attachment_id' => $attachment->id,
                'name' => $component->name,
                'version' => $component->version,
                'ecosystem' => $component->ecosystem,
                'license' => $component->license,
                'metadata' => $component->metadata,
                'first_seen_at' => $record->first_seen_at ?? now(),
                'last_seen_at' => now(),
                ...$this->hierarchyColumns($owner),
            ]);

            if ($isNew) {
                $record->first_seen_at = now();
            }

            $record->save();
        }
    }

    private function ingestFindings(Attachment $attachment, SoftwareAsset|SoftwareSystem|SecurityContainer $owner, string $kind): void
    {
        foreach ($this->sarifParser->parse($attachment->payload) as $finding) {
            $record = $owner->localFindings()->firstOrNew([
                'kind' => $kind,
                'rule_id' => $finding->ruleId,
                'file_path' => $finding->filePath,
                'start_line' => $finding->startLine,
            ]);
            $isNew = ! $record->exists;

            $record->fill([
                'attachment_id' => $attachment->id,
                'title' => $finding->title,
                'description' => $finding->description,
                'severity' => $finding->severity,
                'end_line' => $finding->endLine,
                'package_name' => $finding->packageName,
                'package_version' => $finding->packageVersion,
                'metadata' => $finding->metadata,
                'last_seen_at' => now(),
                ...$this->hierarchyColumns($owner),
            ]);

            if ($isNew) {
                $record->first_seen_at = now();
            }

            $record->save();

            $this->correlator->correlate($record);
        }
    }

    /** @return array{software_system_id: ?int, software_asset_id: ?int} */
    private function hierarchyColumns(SoftwareAsset|SoftwareSystem|SecurityContainer $owner): array
    {
        return match (true) {
            $owner instanceof SecurityContainer => [
                'software_system_id' => $owner->software_system_id,
                'software_asset_id' => $owner->softwareSystem?->software_asset_id,
            ],
            $owner instanceof SoftwareSystem => [
                'software_system_id' => $owner->id,
                'software_asset_id' => $owner->software_asset_id,
            ],
            $owner instanceof SoftwareAsset => [
                'software_system_id' => null,
                'software_asset_id' => $owner->id,
            ],
        };
    }
}
