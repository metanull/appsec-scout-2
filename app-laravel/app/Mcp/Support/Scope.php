<?php

namespace App\Mcp\Support;

use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;

/**
 * The asset / system / container a tool call is about. Ids come out in the list<string> shape
 * the existing *TableQuery::apply*Scopes() filter helpers take; systems() resolves the scope
 * to the software systems an operation should run against.
 */
final class Scope
{
    private function __construct(
        public readonly ?SoftwareAsset $asset,
        public readonly ?SoftwareSystem $system,
        public readonly ?SecurityContainer $container,
    ) {}

    /** @return array<string, Type> */
    public static function schema(JsonSchema $schema): array
    {
        return [
            'asset_id' => $schema->integer()->min(1)
                ->description('Limit to one software asset (all of its linked software systems).'),
            'system_id' => $schema->integer()->min(1)
                ->description('Limit to one software system.'),
            'container_id' => $schema->integer()->min(1)
                ->description('Limit to one security container (e.g. a repository).'),
        ];
    }

    public static function fromRequest(Request $request): self
    {
        $validated = $request->validate([
            'asset_id' => ['nullable', 'integer', 'exists:software_assets,id'],
            'system_id' => ['nullable', 'integer', 'exists:software_systems,id'],
            'container_id' => ['nullable', 'integer', 'exists:security_containers,id'],
        ], [
            'asset_id.exists' => 'No software asset with id :input.',
            'system_id.exists' => 'No software system with id :input.',
            'container_id.exists' => 'No security container with id :input.',
        ]);

        return new self(
            isset($validated['asset_id']) ? SoftwareAsset::query()->findOrFail((int) $validated['asset_id']) : null,
            isset($validated['system_id']) ? SoftwareSystem::query()->findOrFail((int) $validated['system_id']) : null,
            isset($validated['container_id']) ? SecurityContainer::query()->findOrFail((int) $validated['container_id']) : null,
        );
    }

    /** Fails unless exactly one of asset_id, system_id or container_id was given. */
    public function requireSingle(): void
    {
        if (count(array_filter([$this->asset, $this->system, $this->container])) !== 1) {
            throw ValidationException::withMessages([
                'scope' => 'Provide exactly one of asset_id, system_id or container_id.',
            ]);
        }
    }

    /**
     * What a single scope is, for echoing back to the client.
     *
     * @return array{type: string, id: int, name: string}
     */
    public function describe(): array
    {
        $this->requireSingle();

        [$type, $model] = match (true) {
            $this->asset !== null => ['asset', $this->asset],
            $this->system !== null => ['system', $this->system],
            default => ['container', $this->container],
        };

        return ['type' => $type, 'id' => (int) $model?->getKey(), 'name' => (string) $model?->getAttribute('name')];
    }

    public function isEmpty(): bool
    {
        return $this->asset === null && $this->system === null && $this->container === null;
    }

    /** @return list<string> */
    public function assetIds(): array
    {
        return $this->asset === null ? [] : [(string) $this->asset->id];
    }

    /** @return list<string> */
    public function systemIds(): array
    {
        return $this->system === null ? [] : [(string) $this->system->id];
    }

    /** @return list<string> */
    public function containerIds(): array
    {
        return $this->container === null ? [] : [(string) $this->container->id];
    }

    /**
     * The non-removed software systems this scope covers: a system itself, an asset's linked
     * systems, or a container's system. Exactly one of the three ids must have been given.
     *
     * @return Collection<int, SoftwareSystem>
     */
    public function systems(): Collection
    {
        $this->requireSingle();

        $systems = match (true) {
            $this->asset !== null => $this->asset->softwareSystems()->orderBy('name')->get(),
            $this->system !== null => collect([$this->system]),
            default => collect([$this->container?->softwareSystem])->filter(),
        };

        return $systems
            ->filter(fn (SoftwareSystem $system): bool => $system->removed_at === null)
            ->values();
    }
}
