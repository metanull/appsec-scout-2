<?php

namespace App\SourceCode;

use App\Models\Enums\RepositoryProviderType;

/**
 * The minimal set of facts needed to build browse/file/commit URLs for a
 * repository: which provider hosts it, the URL that browses the repository
 * root, and (optionally) the default branch to fall back to and a monorepo
 * sub-path prefix.
 *
 * It is deliberately decoupled from where those facts come from. A
 * SecurityContainer/SoftwareSystem that a Source (AzDO, GitHub, …) already
 * populated with its own `url` + `source.provider` + `code.default_branch`
 * yields one directly — no RepositoryMapping required — while an operator's
 * RepositoryMapping override (different provider, monorepo `path_prefix`, a
 * manual URL) yields another. RepositoryCodeUrlGenerator consumes only this,
 * so both paths share one URL-formatting implementation.
 */
final readonly class RepositoryCodeIdentity
{
    public function __construct(
        public RepositoryProviderType $providerType,
        public string $repositoryBrowseUrl,
        public ?string $defaultBranch = null,
        public ?string $pathPrefix = null,
    ) {}
}
