<?php

namespace Tests\Unit\SourceControl;

use App\SourceControl\Bitbucket\BitbucketClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class BitbucketClientTest extends TestCase
{
    public function test_lists_repositories_across_pages_with_bearer_auth(): void
    {
        $page1 = json_encode([
            'values' => [[
                'slug' => 'backend-api',
                'name' => 'backend-api',
                'full_name' => 'myworkspace/backend-api',
                'mainbranch' => ['name' => 'main'],
                'links' => ['html' => ['href' => 'https://bitbucket.org/myworkspace/backend-api']],
            ]],
            'next' => 'https://api.bitbucket.org/2.0/repositories/myworkspace?page=2&pagelen=100',
        ], JSON_THROW_ON_ERROR);

        $page2 = json_encode([
            'values' => [[
                'slug' => 'frontend',
                'name' => 'frontend',
                'full_name' => 'myworkspace/frontend',
                'mainbranch' => ['name' => 'develop'],
                'links' => ['html' => ['href' => 'https://bitbucket.org/myworkspace/frontend']],
            ]],
        ], JSON_THROW_ON_ERROR);

        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], $page1),
            new Response(200, [], $page2),
        ]));
        $stack->push(Middleware::history($history));

        // The injected client carries the same Bearer/Accept headers the real
        // constructor sets, so the assertion below verifies requests are authenticated.
        $http = new Client([
            'handler' => $stack,
            'base_uri' => 'https://api.bitbucket.org/2.0/',
            'headers' => ['Authorization' => 'Bearer test-token', 'Accept' => 'application/json'],
        ]);

        $client = new BitbucketClient('myworkspace', 'test-token', 'https://api.bitbucket.org/2.0', $http);

        $repos = $client->listRepositories();

        $this->assertCount(2, $repos);
        $this->assertSame('backend-api', $repos[0]->slug);
        $this->assertSame('myworkspace/backend-api', $repos[0]->fullName);
        $this->assertSame('main', $repos[0]->mainBranch);
        $this->assertSame('https://bitbucket.org/myworkspace/backend-api', $repos[0]->htmlUrl);
        $this->assertSame('frontend', $repos[1]->slug);
        $this->assertSame('develop', $repos[1]->mainBranch);

        // Two requests: the workspace-scoped listing, then the followed `next` page.
        $this->assertCount(2, $history);
        $this->assertSame('Bearer test-token', $history[0]['request']->getHeaderLine('Authorization'));
        $this->assertStringContainsString('/repositories/myworkspace', (string) $history[0]['request']->getUri());
        $this->assertStringContainsString('page=2', (string) $history[1]['request']->getUri());
    }

    public function test_test_connection_returns_true_on_success(): void
    {
        $http = new Client(['handler' => new MockHandler([new Response(200, [], '{"values":[]}')])]);
        $client = new BitbucketClient('myworkspace', 'test-token', 'https://api.bitbucket.org/2.0', $http);

        $this->assertTrue($client->testConnection());
    }

    public function test_test_connection_returns_false_on_failure(): void
    {
        $http = new Client([
            'handler' => new MockHandler([new Response(401, [], 'unauthorized')]),
            'http_errors' => false,
        ]);
        $client = new BitbucketClient('myworkspace', 'test-token', 'https://api.bitbucket.org/2.0', $http);

        $this->assertFalse($client->testConnection());
    }
}
