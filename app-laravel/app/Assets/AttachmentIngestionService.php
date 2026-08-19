<?php

namespace App\Assets;

use App\Assets\Parsers\CycloneDxSbomParser;
use App\Assets\Parsers\ParsedComponent;
use App\Assets\Parsers\ParsedFinding;
use App\Assets\Parsers\SarifFindingParser;
use App\Models\Attachment;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareComponent;
use App\Models\SoftwareSystem;
use Illuminate\Support\Facades\DB;

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

    public const KIND_CODE_QUALITY_OPENGREP = 'code-quality-opengrep';

    public function __construct(
        private readonly CycloneDxSbomParser $sbomParser,
        private readonly SarifFindingParser $sarifParser,
        private readonly SecurityEventCorrelator $correlator,
        private readonly StaleRecordSweeper $sweeper,
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
            self::KIND_CODE_QUALITY_DOTNET, self::KIND_CODE_QUALITY_JAVA, self::KIND_CODE_QUALITY_OPENGREP => $this->ingestFindings($attachment, $owner, LocalFinding::KIND_CODE_QUALITY),
            default => null,
        };
    }

    private function ingestSbom(Attachment $attachment, SoftwareAsset|SoftwareSystem|SecurityContainer $owner): void
    {
        $components = $this->sbomParser->parse($attachment->payload);

        DB::transaction(function () use ($attachment, $owner, $components): void {
            // Only a SecurityContainer owner carries the real (owner_type, owner_id, purl)
            // unique constraint upsert() needs to detect conflicts — SoftwareSystem/
            // SoftwareAsset ownership (only reachable via the manual assets:import-attachment
            // CLI command, one file at a time, never a bulk scan import) scopes instead by
            // software_system_id/software_asset_id, which carries no matching DB constraint,
            // so it keeps the original row-by-row path.
            $touchedIds = $owner instanceof SecurityContainer
                ? $this->upsertSoftwareComponents($attachment, $owner, $components)
                : $this->saveSoftwareComponentsSequentially($attachment, $owner, $components);

            // The whole SBOM parsed and every component upserted without throwing, so this is a
            // complete pass — safe to mark anything not touched this time as no longer present.
            $this->sweeper->sweepComponents($owner, $touchedIds);
        });
    }

    /**
     * @param  list<ParsedComponent>  $components
     * @return list<int>
     */
    private function upsertSoftwareComponents(Attachment $attachment, SecurityContainer $owner, array $components): array
    {
        if ($components === []) {
            return [];
        }

        $now = now();
        $hierarchy = $this->hierarchyColumns($owner);

        $rows = array_map(fn (ParsedComponent $component): array => [
            'attachment_id' => $attachment->id,
            'name' => $component->name,
            'version' => $component->version,
            'ecosystem' => $component->ecosystem,
            'purl' => $component->purl,
            'license' => $component->license,
            'metadata' => json_encode($component->metadata, JSON_THROW_ON_ERROR),
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            ...$hierarchy,
        ], $components);

        foreach (array_chunk($rows, 500) as $chunk) {
            // first_seen_at/created_at are deliberately excluded from the update-columns list
            // so they are only ever set on insert — first_seen_at must survive re-scans.
            SoftwareComponent::query()->upsert(
                $chunk,
                ['owner_type', 'owner_id', 'purl'],
                ['attachment_id', 'name', 'version', 'ecosystem', 'license', 'metadata', 'software_system_id', 'software_asset_id', 'last_seen_at', 'updated_at'],
            );
        }

        return array_values(SoftwareComponent::query()
            ->where('owner_type', SecurityContainer::class)
            ->where('owner_id', $owner->id)
            ->whereIn('purl', array_column($rows, 'purl'))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * @param  list<ParsedComponent>  $components
     * @return list<int>
     */
    private function saveSoftwareComponentsSequentially(Attachment $attachment, SoftwareAsset|SoftwareSystem $owner, array $components): array
    {
        $touchedIds = [];

        foreach ($components as $component) {
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
            $touchedIds[] = $record->id;
        }

        return $touchedIds;
    }

    private function ingestFindings(Attachment $attachment, SoftwareAsset|SoftwareSystem|SecurityContainer $owner, string $kind): void
    {
        $findings = $this->sarifParser->parse($attachment->payload);

        DB::transaction(function () use ($attachment, $owner, $kind, $findings): void {
            // Only a SecurityContainer owner carries the real (owner_type, owner_id, kind,
            // dedup_hash) unique constraint upsert() needs to detect conflicts —
            // SoftwareSystem/SoftwareAsset ownership (only reachable via the manual
            // assets:import-attachment CLI command, one file at a time, never a bulk scan
            // import) keeps the original row-by-row path.
            $touchedIds = $owner instanceof SecurityContainer
                ? $this->upsertFindings($attachment, $owner, $kind, $findings)
                : $this->saveFindingsSequentially($attachment, $owner, $kind, $findings);

            if ($touchedIds !== []) {
                $this->correlator->correlateBatch(
                    LocalFinding::query()->whereIn('id', $touchedIds)->get(),
                    $owner,
                );
            }

            // The whole SARIF file parsed and every finding upserted without throwing, so this
            // is a complete pass — safe to auto-resolve anything of this kind not seen this
            // time.
            $this->sweeper->sweepFindingsAsResolved($owner, $kind, $touchedIds);
        });
    }

    /**
     * @param  list<ParsedFinding>  $findings
     * @return list<int>
     */
    private function upsertFindings(Attachment $attachment, SecurityContainer $owner, string $kind, array $findings): array
    {
        if ($findings === []) {
            return [];
        }

        $now = now();
        $hierarchy = $this->hierarchyColumns($owner);

        $rows = array_map(fn (ParsedFinding $finding): array => [
            'attachment_id' => $attachment->id,
            'kind' => $kind,
            'rule_id' => $finding->ruleId,
            'title' => $finding->title,
            'description' => $finding->description,
            'severity' => $finding->severity,
            'file_path' => $finding->filePath,
            'start_line' => $finding->startLine,
            'end_line' => $finding->endLine,
            'dedup_hash' => LocalFinding::computeDedupHash($finding->ruleId, $finding->filePath, $finding->startLine),
            'package_name' => $finding->packageName,
            'package_version' => $finding->packageVersion,
            'metadata' => json_encode($finding->metadata, JSON_THROW_ON_ERROR),
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
            ...$hierarchy,
        ], $findings);

        foreach (array_chunk($rows, 500) as $chunk) {
            // first_seen_at/created_at, and the identity columns (rule_id/file_path/start_line,
            // which dedup_hash is itself derived from) are deliberately excluded from the
            // update-columns list — first_seen_at must survive re-scans, and the identity
            // columns are already guaranteed identical whenever dedup_hash matches.
            LocalFinding::query()->upsert(
                $chunk,
                ['owner_type', 'owner_id', 'kind', 'dedup_hash'],
                ['attachment_id', 'title', 'description', 'severity', 'end_line', 'package_name', 'package_version', 'metadata', 'software_system_id', 'software_asset_id', 'last_seen_at', 'updated_at'],
            );
        }

        return array_values(LocalFinding::query()
            ->where('owner_type', SecurityContainer::class)
            ->where('owner_id', $owner->id)
            ->where('kind', $kind)
            ->whereIn('dedup_hash', array_column($rows, 'dedup_hash'))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * @param  list<ParsedFinding>  $findings
     * @return list<int>
     */
    private function saveFindingsSequentially(Attachment $attachment, SoftwareAsset|SoftwareSystem $owner, string $kind, array $findings): array
    {
        $touchedIds = [];

        foreach ($findings as $finding) {
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
            $touchedIds[] = $record->id;
        }

        return $touchedIds;
    }

    /**
     * owner_type/owner_id are included here (not just software_system_id/software_asset_id)
     * because only a SecurityContainer owner's softwareComponents()/localFindings() is a
     * morphMany — Eloquent stamps the morph columns automatically through that relation only.
     * SoftwareSystem/SoftwareAsset ownership uses a plain hasMany keyed on
     * software_system_id/software_asset_id, which never touches owner_type/owner_id, and both
     * columns are NOT NULL — so without this, creating a component/finding directly on a bare
     * SoftwareSystem/SoftwareAsset owner fails outright, and even where it wouldn't (an update
     * to an existing row), the real (owner_type, owner_id, purl) unique constraint would stay
     * silently unenforceable for that path, since NULL never equals NULL in a unique index.
     *
     * @return array{owner_type: class-string, owner_id: int, software_system_id: ?int, software_asset_id: ?int}
     */
    private function hierarchyColumns(SoftwareAsset|SoftwareSystem|SecurityContainer $owner): array
    {
        return [
            'owner_type' => $owner::class,
            'owner_id' => $owner->id,
            ...match (true) {
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
            },
        ];
    }
}
