<?php

namespace App\SourceControl\Bitbucket;

use App\Credentials\CredentialField;
use App\Credentials\Vault;
use App\SourceControl\Contracts\EnumeratesInventory;
use App\SourceControl\Contracts\SourceControlProvider;
use App\SourceControl\ValueObjects\TestResult;
use App\Sources\Context\SourceContextFacts;
use App\Sources\Dto\ContainerDto;
use App\Sources\Dto\SystemDto;

final class BitbucketRepos implements EnumeratesInventory, SourceControlProvider
{
    private ?BitbucketClient $client = null;

    private ?string $clientFingerprint = null;

    public function __construct(private readonly Vault $vault) {}

    public function id(): string
    {
        return 'bitbucket-repos';
    }

    public function displayName(): string
    {
        return 'Bitbucket';
    }

    /** @return list<CredentialField> */
    public function credentialFields(): array
    {
        return [
            new CredentialField(key: 'bitbucket-repos.token', label: 'API Token', isSecret: true, required: true, description: 'Atlassian API token / access token for Bitbucket Cloud repository access (Bearer auth), separate from any Jira tracker credential.'),
            new CredentialField(key: 'bitbucket-repos.workspace', label: 'Workspace', isSecret: false, required: true, description: 'The Bitbucket Cloud workspace ID.'),
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

    /**
     * The whole workspace is modeled as a single system; its repositories become the containers.
     *
     * @return iterable<SystemDto>
     */
    public function fetchProjects(): iterable
    {
        $workspace = $this->getClient()->workspace();

        yield new SystemDto(
            sourceSystemId: $workspace,
            name: $workspace,
            url: 'https://bitbucket.org/' . rawurlencode($workspace),
        );
    }

    /** @return iterable<ContainerDto> */
    public function fetchRepositories(SystemDto $project): iterable
    {
        foreach ($this->getClient()->listRepositories() as $repo) {
            $metadata = [];
            $metadata = SourceContextFacts::set($metadata, SourceContextFacts::CODE_DEFAULT_BRANCH, $repo->mainBranch);
            $metadata = SourceContextFacts::set($metadata, SourceContextFacts::SOURCE_PROVIDER, 'bitbucket');

            yield new ContainerDto(
                sourceContainerId: $repo->slug,
                name: $repo->slug,
                sourceSystemId: $project->sourceSystemId,
                kind: 'repository',
                url: $repo->htmlUrl,
                metadata: $metadata !== [] ? $metadata : null,
            );
        }
    }

    private function getClient(): BitbucketClient
    {
        if ($this->client instanceof BitbucketClient && $this->clientFingerprint === null) {
            return $this->client;
        }

        $token = $this->vault->get('bitbucket-repos.token', null) ?? throw new \RuntimeException('Missing Bitbucket credential: bitbucket-repos.token');
        $workspace = $this->vault->get('bitbucket-repos.workspace', null) ?? throw new \RuntimeException('Missing Bitbucket credential: bitbucket-repos.workspace');
        $fingerprint = hash('sha256', implode('|', [$workspace, $token]));

        if ($this->client === null || $this->clientFingerprint !== $fingerprint) {
            $this->client = new BitbucketClient($workspace, $token);
            $this->clientFingerprint = $fingerprint;
        }

        return $this->client;
    }
}
