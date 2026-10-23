<?php

namespace App\SourceControl\Collection;

/**
 * What PackageFeedCredentialWriter prepared for one clone: the NuGet source
 * keys and Maven repository ids it wrote credentials for, and the Maven
 * settings file to pass to `mvn --settings` (null when no repository needed
 * one). Names only — never the credential itself.
 */
final readonly class PackageFeedCredentials
{
    /**
     * @param  list<string>  $nugetSources
     * @param  list<string>  $mavenRepositories
     */
    public function __construct(
        public array $nugetSources,
        public array $mavenRepositories,
        public ?string $mavenSettingsPath,
    ) {}

    public function isEmpty(): bool
    {
        return $this->nugetSources === [] && $this->mavenRepositories === [];
    }
}
