<?php

namespace Tests\Unit\SourceCode;

use App\Models\Enums\RepositoryProviderType;
use App\Models\RepositoryMapping;
use App\Models\RepositoryProvider;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\SecurityEvents\SourceLinkHelper;
use App\SourceCode\CodeLocation;
use App\SourceCode\RepositoryCodeIdentity;
use App\SourceCode\RepositoryCodeUrlGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepositoryCodeUrlGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_azure_file_url_from_a_container_style_browse_identity(): void
    {
        $generator = new RepositoryCodeUrlGenerator;

        // No RepositoryMapping — just the repository's own browse URL + default
        // branch, exactly what a container carries after enumeration.
        $identity = new RepositoryCodeIdentity(
            providerType: RepositoryProviderType::AzureRepos,
            repositoryBrowseUrl: 'https://dev.azure.com/EESC-CoR/PW-API/_git/consultation-api',
            defaultBranch: 'main',
        );

        $this->assertSame(
            'https://dev.azure.com/EESC-CoR/PW-API/_git/consultation-api',
            $generator->repositoryUrlFor($identity),
        );

        $url = $generator->fileUrlFor($identity, new CodeLocation(
            filePath: 'src/App/appsettings.json',
            startLine: 55,
            endLine: 60,
        ));

        $this->assertSame(
            'https://dev.azure.com/EESC-CoR/PW-API/_git/consultation-api?path=/src/App/appsettings.json&version=GBmain&line=55&lineEnd=60&lineStartColumn=1&lineEndColumn=1',
            $url,
        );
        $this->assertTrue(SourceLinkHelper::isSafeUrl($url));
    }

    public function test_builds_github_file_url_from_a_browse_identity(): void
    {
        $generator = new RepositoryCodeUrlGenerator;

        $identity = new RepositoryCodeIdentity(
            providerType: RepositoryProviderType::GitHub,
            repositoryBrowseUrl: 'https://github.com/appsec-scout/platform',
            defaultBranch: 'develop',
        );

        $this->assertSame(
            'https://github.com/appsec-scout/platform/blob/develop/src/Example.php#L10',
            $generator->fileUrlFor($identity, new CodeLocation(filePath: 'src/Example.php', startLine: 10)),
        );
    }

    public function test_generates_github_repository_and_file_urls(): void
    {
        $generator = new RepositoryCodeUrlGenerator;
        $mapping = $this->makeGithubMapping();

        $this->assertSame('https://github.com/appsec-scout/platform/api', $generator->repositoryUrl($mapping));

        $location = new CodeLocation(
            filePath: 'src/Example.php',
            startLine: 10,
            endLine: 12,
            branch: 'feature/navigation',
        );

        $url = $generator->fileUrl($mapping, $location);

        $this->assertSame(
            'https://github.com/appsec-scout/platform/api/blob/feature%2Fnavigation/packages/core/src/Example.php#L10-L12',
            $url,
        );
        $this->assertTrue(SourceLinkHelper::isSafeUrl($url));
    }

    public function test_prefers_commit_sha_over_branch_for_github_file_urls(): void
    {
        $generator = new RepositoryCodeUrlGenerator;
        $mapping = $this->makeGithubMapping();

        $location = new CodeLocation(
            filePath: 'src/Example.php',
            startLine: 42,
            commitSha: 'abc123def456',
            branch: 'feature/navigation',
        );

        $this->assertSame(
            'https://github.com/appsec-scout/platform/api/blob/abc123def456/packages/core/src/Example.php#L42',
            $generator->fileUrl($mapping, $location),
        );
        $this->assertSame(
            'https://github.com/appsec-scout/platform/api/commit/abc123def456',
            $generator->commitUrl($mapping, $location),
        );
    }

    public function test_falls_back_to_default_branch_for_github_file_urls(): void
    {
        $generator = new RepositoryCodeUrlGenerator;
        $mapping = $this->makeGithubMapping(defaultBranch: 'develop');

        $location = new CodeLocation(filePath: 'src/Example.php');

        $this->assertSame(
            'https://github.com/appsec-scout/platform/api/blob/develop/packages/core/src/Example.php',
            $generator->fileUrl($mapping, $location),
        );
    }

    public function test_generates_azure_repos_repository_and_file_urls(): void
    {
        $generator = new RepositoryCodeUrlGenerator;
        $mapping = $this->makeAzureMapping();

        $this->assertSame('https://dev.azure.com/appsec-scout/_git/platform/api', $generator->repositoryUrl($mapping));

        $location = new CodeLocation(
            filePath: 'src/Example.cs',
            startLine: 5,
            endLine: 9,
            branch: 'feature/navigation',
        );

        $url = $generator->fileUrl($mapping, $location);

        $this->assertSame(
            'https://dev.azure.com/appsec-scout/_git/platform/api?path=/packages/core/src/Example.cs&version=GBfeature%2Fnavigation&line=5&lineEnd=9&lineStartColumn=1&lineEndColumn=1',
            $url,
        );
        $this->assertTrue(SourceLinkHelper::isSafeUrl($url));
    }

    public function test_prefers_commit_sha_over_branch_for_azure_repos_file_urls(): void
    {
        $generator = new RepositoryCodeUrlGenerator;
        $mapping = $this->makeAzureMapping();

        $location = new CodeLocation(
            filePath: 'src/Example.cs',
            commitSha: 'abc123def456',
            branch: 'feature/navigation',
        );

        $this->assertSame(
            'https://dev.azure.com/appsec-scout/_git/platform/api?path=/packages/core/src/Example.cs&version=GCabc123def456',
            $generator->fileUrl($mapping, $location),
        );
        $this->assertSame(
            'https://dev.azure.com/appsec-scout/_git/platform/api/commit/abc123def456',
            $generator->commitUrl($mapping, $location),
        );
    }

    public function test_returns_null_for_missing_path_or_traversal_segments(): void
    {
        $generator = new RepositoryCodeUrlGenerator;
        $mapping = $this->makeGithubMapping();

        $this->assertNull($generator->fileUrl($mapping, new CodeLocation));
        $this->assertNull($generator->fileUrl($mapping, new CodeLocation(filePath: '../Example.php')));
        $this->assertNull($generator->fileUrl($mapping, new CodeLocation(filePath: 'src/../Example.php')));
        $this->assertNull($generator->commitUrl($mapping, new CodeLocation(filePath: 'src/Example.php')));
    }

    public function test_returns_null_for_malformed_provider_type(): void
    {
        $generator = new RepositoryCodeUrlGenerator;
        $provider = new RepositoryProvider;
        $provider->setRawAttributes([
            'provider_type' => 'gitlab',
            'name' => 'Unsupported',
            'base_url' => 'https://gitlab.example.com/org',
        ], true);

        $mapping = new RepositoryMapping;
        $mapping->setRawAttributes([
            'repository_name' => 'platform/api',
            'repository_url' => 'https://gitlab.example.com/org/platform/api',
            'default_branch' => 'main',
            'path_prefix' => 'packages/core',
        ], true);
        $mapping->setRelation('repositoryProvider', $provider);

        $this->assertNull($generator->repositoryUrl($mapping));
        $this->assertNull($generator->fileUrl($mapping, new CodeLocation(filePath: 'src/Example.php')));
    }

    private function makeGithubMapping(?string $defaultBranch = 'main'): RepositoryMapping
    {
        $provider = RepositoryProvider::factory()->github()->create();
        $system = SoftwareSystem::factory()->create();
        $container = SecurityContainer::factory()->forSystem($system)->create();

        $mapping = RepositoryMapping::factory()
            ->forContainer($container)
            ->withProvider($provider)
            ->create([
                'repository_name' => 'platform/api',
                'default_branch' => $defaultBranch,
                'path_prefix' => 'packages/core',
            ]);

        $mapping->setRelation('repositoryProvider', $provider);

        return $mapping;
    }

    private function makeAzureMapping(?string $defaultBranch = 'main'): RepositoryMapping
    {
        $provider = RepositoryProvider::factory()->azureRepos()->create();
        $system = SoftwareSystem::factory()->create();

        $mapping = RepositoryMapping::factory()
            ->forSystem($system)
            ->withProvider($provider)
            ->create([
                'repository_name' => 'platform/api',
                'default_branch' => $defaultBranch,
                'path_prefix' => 'packages/core',
            ]);

        $mapping->setRelation('repositoryProvider', $provider);

        return $mapping;
    }
}
