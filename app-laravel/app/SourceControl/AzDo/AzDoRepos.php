<?php

namespace App\SourceControl\AzDo;

use App\Credentials\CredentialField;
use App\Credentials\Vault;
use App\SourceControl\Contracts\SourceControlProvider;
use App\SourceControl\ValueObjects\TestResult;
use App\Sources\AzDo\AzDoClient;

final class AzDoRepos implements SourceControlProvider
{
    private ?AzDoClient $client = null;

    private ?string $clientFingerprint = null;

    public function __construct(private readonly Vault $vault) {}

    public function id(): string
    {
        return 'azdo-repos';
    }

    public function displayName(): string
    {
        return 'Azure DevOps Repos';
    }

    /** @return list<CredentialField> */
    public function credentialFields(): array
    {
        return [
            new CredentialField(key: 'azdo-repos.pat', label: 'Personal Access Token', isSecret: true, required: true, description: 'Azure DevOps personal access token scoped for repository/code access (e.g. Code (Read)), separate from the Advanced Security source token.'),
            new CredentialField(key: 'azdo-repos.organization', label: 'Organization', isSecret: false, required: true, description: 'The Azure DevOps organization name.'),
        ];
    }

    public function testConnection(): TestResult
    {
        try {
            $client = $this->getClient();

            return $client->testConnection() ? TestResult::success() : TestResult::failure('Connection refused');
        } catch (\Throwable $e) {
            return TestResult::failure($e->getMessage());
        }
    }

    private function getClient(): AzDoClient
    {
        if ($this->client instanceof AzDoClient && $this->clientFingerprint === null) {
            return $this->client;
        }

        $pat = $this->vault->get('azdo-repos.pat', null, true) ?? throw new \RuntimeException('AzDO Repos PAT not configured');
        $organization = $this->vault->get('azdo-repos.organization', null, true) ?? throw new \RuntimeException('AzDO Repos organization not configured');
        $fingerprint = hash('sha256', implode('|', [$organization, $pat]));

        if ($this->client === null || $this->clientFingerprint !== $fingerprint) {
            $this->client = new AzDoClient($organization, $pat);
            $this->clientFingerprint = $fingerprint;
        }

        return $this->client;
    }
}
