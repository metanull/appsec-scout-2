<?php

namespace App\SourceControl\GitHub;

use App\Credentials\CredentialField;
use App\Credentials\Vault;
use App\SourceControl\Contracts\SourceControlProvider;
use App\SourceControl\ValueObjects\TestResult;
use App\Trackers\GitHub\GitHubClient;

final class GitHubRepos implements SourceControlProvider
{
    private ?GitHubClient $client = null;

    private ?string $clientFingerprint = null;

    public function __construct(private readonly Vault $vault) {}

    public function id(): string
    {
        return 'github-repos';
    }

    public function displayName(): string
    {
        return 'GitHub Repos';
    }

    /** @return list<CredentialField> */
    public function credentialFields(): array
    {
        return [
            new CredentialField(key: 'github-repos.token', label: 'Personal Access Token', isSecret: true, required: true, description: 'GitHub personal access token scoped for repository access (clone/push), separate from the GitHub Issues tracker token.'),
        ];
    }

    public function testConnection(): TestResult
    {
        try {
            $this->getClient()->getCurrentUser();

            return TestResult::success();
        } catch (\Throwable $e) {
            return TestResult::failure($e->getMessage());
        }
    }

    private function getClient(): GitHubClient
    {
        if ($this->client instanceof GitHubClient && $this->clientFingerprint === null) {
            return $this->client;
        }

        $token = $this->vault->get('github-repos.token', null) ?? throw new \RuntimeException('Missing GitHub credential: github-repos.token');

        $fingerprint = hash('sha256', $token);

        if ($this->client instanceof GitHubClient && $this->clientFingerprint === $fingerprint) {
            return $this->client;
        }

        $this->clientFingerprint = $fingerprint;

        return $this->client = new GitHubClient($token);
    }
}
