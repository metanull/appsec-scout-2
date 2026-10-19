<?php

namespace App\Mcp\Support;

use App\Credentials\Vault;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\User;
use App\Sources\AzDo\AzDoNormalizer;
use InvalidArgumentException;

/**
 * Code access from MCP is user-triggered, so it uses the acting user's own Azure DevOps Repos
 * credential (Profile → Integrations), never the system one. Also resolves which Azure DevOps
 * project and repository a container or system stands for.
 */
final class AzDoReposAccess
{
    public const MISSING_CREDENTIAL = 'Code access uses your own Azure DevOps Repos credential: add its Personal Access Token and Organization under Profile → Integrations.';

    public function __construct(private readonly Vault $vault) {}

    /** @return array{pat: string, organization: string} */
    public function credentials(User $user): array
    {
        [$pat, $organization] = $this->vault->runAsOwner($user->id, fn (): array => [
            $this->vault->get('azdo-repos.pat'),
            $this->vault->get('azdo-repos.organization'),
        ]);

        if (! is_string($pat) || trim($pat) === '' || ! is_string($organization) || trim($organization) === '') {
            throw new InvalidArgumentException(self::MISSING_CREDENTIAL);
        }

        return ['pat' => $pat, 'organization' => trim($organization)];
    }

    /** The Azure DevOps project behind a system; fails for a system of another source. */
    public static function project(SoftwareSystem $system): SoftwareSystem
    {
        if ($system->source_id !== AzDoNormalizer::SOURCE_ID) {
            throw new InvalidArgumentException("Software system #{$system->id} ({$system->name}) is not an Azure DevOps project: code access covers Azure DevOps repositories only.");
        }

        return $system;
    }

    /**
     * The Azure DevOps project a container's repository lives in: the system its source reports,
     * which differs from its linked system once an operator re-linked it.
     */
    public static function projectOf(SecurityContainer $container): SoftwareSystem
    {
        $source = $container->sourceSoftwareSystem
            ?? throw new InvalidArgumentException("Security container #{$container->id} has no source system, so its repository is unknown.");

        if ($source->source_id !== AzDoNormalizer::SOURCE_ID) {
            throw new InvalidArgumentException("Security container #{$container->id} ({$container->name}) is not an Azure DevOps repository: code access covers Azure DevOps repositories only.");
        }

        return $source;
    }
}
