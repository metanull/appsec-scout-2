<?php

namespace App\Sources\Asoc;

use App\Http\OutboundHttpFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

final class AsocClient
{
    private const CACHE_TOKEN_KEY = 'asoc.token';

    private const TOKEN_TTL_MINUTES = 55;

    private readonly Client $http;

    public function __construct(
        private readonly string $keyId,
        private readonly string $keySecret,
        private readonly string $baseUrl = 'https://cloud.appscan.com',
        ?Client $httpClient = null,
    ) {
        $this->http = $httpClient ?? OutboundHttpFactory::create([
            'base_uri' => rtrim($this->baseUrl, '/') . '/',
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    public function testConnection(): bool
    {
        try {
            $this->listApplications(1);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<array<string, mixed>> */
    public function listApplications(int $pageSize = 100): array
    {
        return $this->fetchAllPages('api/v4/Apps', $pageSize);
    }

    /** @return list<array<string, mixed>> */
    public function listScans(string $appId, int $pageSize = 100): array
    {
        return $this->fetchAllPages('api/v4/Scans', $pageSize, [
            '$filter' => "AppId eq '{$appId}'",
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function listIssues(string $appId, ?\DateTimeInterface $since = null, int $pageSize = 100): array
    {
        $query = [];

        if ($since !== null) {
            $query['$filter'] = "LastUpdated ge '" . $since->format(\DateTimeInterface::ATOM) . "'";
        }

        return $this->fetchAllPages("api/v4/Issues/Application/{$appId}", $pageSize, $query);
    }

    /** @return array<string, mixed> */
    public function getIssue(string $issueId): array
    {
        /** @var array<string, mixed> $result */
        $result = $this->request('GET', 'api/v4/Issues/' . rawurlencode($issueId));

        return $result;
    }

    public function updateIssue(string $appId, string $issueId, string $status, ?string $comment = null): void
    {
        $this->request('PUT', "api/v4/Issues/Application/{$appId}/Update", [
            'json' => [
                'Status' => $status,
                'Comment' => $comment ?? '',
                'odataFilter' => "Id eq {$issueId}",
            ],
        ]);
    }

    public function getIssueArticleMarkdown(
        string $issueTypeId,
        ?string $language = null,
        ?string $cveId = null,
        ?string $apiVulnName = null,
    ): ?string {
        $articlePath = self::buildArticleUrl($issueTypeId, $language, $cveId);

        if ($apiVulnName !== null && $apiVulnName !== '') {
            $articlePath = $this->resolveFocusedArticleUrl($issueTypeId, $language, $apiVulnName);
        }

        $html = $this->requestString('GET', $articlePath, [], false, [
            'Accept' => 'text/html,application/xhtml+xml,application/json',
        ]);

        if (trim($html) === '') {
            return null;
        }

        $sanitizedHtml = $this->sanitizeHtml($html);

        $markdown = trim($this->toMarkdown($sanitizedHtml));

        return $markdown === '' ? null : $markdown;
    }

    public static function buildArticleUrl(string $issueTypeId, ?string $language = null, ?string $cveId = null): string
    {
        $query = [
            'issuetype' => $issueTypeId,
        ];

        if ($language !== null && $language !== '') {
            $query['language'] = $language;
        }

        if ($cveId !== null && $cveId !== '') {
            $query['cveId'] = $cveId;
        }

        return 'api/v4/Reports/Article/?' . http_build_query($query);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    private function fetchAllPages(string $path, int $pageSize = 100, array $query = []): array
    {
        $all = [];
        $skip = 0;

        while (true) {
            $pageQuery = array_merge($query, ['$top' => $pageSize, '$skip' => $skip]);

            /** @var array<string, mixed> $page */
            $page = $this->request('GET', $path, ['query' => $pageQuery]);

            /** @var array<int, array<string, mixed>> $items */
            $items = $page['Items'] ?? $page['items'] ?? [];

            if ($items === []) {
                break;
            }

            $all = [...$all, ...$items];

            if (count($items) < $pageSize) {
                break;
            }

            $skip += $pageSize;
        }

        return $all;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, string>  $extraHeaders
     * @return array<string, mixed>|string
     */
    private function request(
        string $method,
        string $path,
        array $options = [],
        bool $retryOnUnauthorized = true,
        array $extraHeaders = [],
        bool $asString = false,
    ): array|string {
        $token = $this->getToken();
        $options['headers'] = array_merge($options['headers'] ?? [], $extraHeaders, [
            'Authorization' => 'Bearer ' . $token,
        ]);

        try {
            $response = $this->http->request($method, ltrim($path, '/'), $options);
        } catch (ClientException $e) {
            $status = $e->getResponse()->getStatusCode();

            if ($status === 401 && $retryOnUnauthorized) {
                cache()->forget(self::CACHE_TOKEN_KEY);
                $this->authenticate(true);

                return $this->request($method, $path, $options, false, [], $asString);
            }

            throw $e;
        }

        $body = (string) $response->getBody();

        if ($asString) {
            return $body;
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, string>  $extraHeaders
     */
    private function requestString(
        string $method,
        string $path,
        array $options = [],
        bool $retryOnUnauthorized = true,
        array $extraHeaders = [],
    ): string {
        $result = $this->request($method, $path, $options, $retryOnUnauthorized, $extraHeaders, true);

        if (! is_string($result)) {
            throw new \RuntimeException('Expected string response body');
        }

        return $result;
    }

    private function getToken(): string
    {
        $cachedToken = cache()->get(self::CACHE_TOKEN_KEY);

        if (is_string($cachedToken) && $cachedToken !== '') {
            return $cachedToken;
        }

        return $this->authenticate();
    }

    private function authenticate(bool $force = false): string
    {
        if (! $force) {
            $cachedToken = cache()->get(self::CACHE_TOKEN_KEY);
            if (is_string($cachedToken) && $cachedToken !== '') {
                return $cachedToken;
            }
        }

        $response = $this->http->post('api/v4/Account/ApiKeyLogin', [
            'json' => [
                'KeyId' => $this->keyId,
                'KeySecret' => $this->keySecret,
            ],
        ]);

        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $token = $payload['Token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new \RuntimeException('ASoC authentication failed: missing token');
        }

        cache()->put(self::CACHE_TOKEN_KEY, $token, now()->addMinutes(self::TOKEN_TTL_MINUTES));

        return $token;
    }

    private function resolveFocusedArticleUrl(string $issueTypeId, ?string $language, string $apiVulnName): string
    {
        $generalPath = self::buildArticleUrl($issueTypeId, $language);
        $generalHtml = $this->requestString('GET', $generalPath, [], true, [
            'Accept' => 'text/html,application/xhtml+xml,application/json',
        ]);

        if (trim($generalHtml) === '') {
            return $generalPath;
        }

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($generalHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        foreach ($xpath->query("//*[@id='apiLinks']//a") ?: [] as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $label = trim($node->textContent);

            if ($label !== $apiVulnName) {
                continue;
            }

            $href = $node->getAttribute('href');
            if (trim($href) === '') {
                continue;
            }

            if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
                return $href;
            }

            return 'api/v4/Reports/Article/' . ltrim($href, '/');
        }

        return $generalPath;
    }

    private function sanitizeHtml(string $html): string
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        foreach ($xpath->query('//script|//style|//img') ?: [] as $node) {
            if (! $node instanceof \DOMNode) {
                continue;
            }

            $node->parentNode?->removeChild($node);
        }

        foreach ($xpath->query('//*[@*]') ?: [] as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $attrsToRemove = [];

            foreach ($node->attributes as $attribute) {
                $name = strtolower($attribute->name);
                if (str_starts_with($name, 'on')) {
                    $attrsToRemove[] = $attribute->name;
                }
            }

            foreach ($attrsToRemove as $attributeName) {
                $node->removeAttribute($attributeName);
            }
        }

        return $dom->saveHTML() ?: '';
    }

    private function toMarkdown(string $html): string
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $lines = [];

        foreach ($dom->childNodes as $child) {
            $this->appendMarkdownLines($child, $lines);
        }

        return trim(preg_replace('/\n{3,}/', "\n\n", implode("\n", array_filter($lines, static fn (string $line): bool => trim($line) !== ''))) ?? '');
    }

    /**
     * @param  list<string>  $lines
     */
    private function appendMarkdownLines(\DOMNode $node, array &$lines): void
    {
        if ($node instanceof \DOMText) {
            $text = trim($node->textContent);
            if ($text !== '') {
                $lines[] = $text;
            }

            return;
        }

        if (! $node instanceof \DOMElement) {
            return;
        }

        $tag = strtolower($node->tagName);

        if ($tag === 'h1') {
            $lines[] = '# ' . trim($node->textContent);

            return;
        }

        if ($tag === 'h2') {
            $lines[] = '## ' . trim($node->textContent);

            return;
        }

        if ($tag === 'h3') {
            $lines[] = '### ' . trim($node->textContent);

            return;
        }

        if ($tag === 'p' || $tag === 'li') {
            $text = trim($node->textContent);
            if ($text !== '') {
                $lines[] = $tag === 'li' ? '- ' . $text : $text;
            }

            return;
        }

        foreach ($node->childNodes as $child) {
            $this->appendMarkdownLines($child, $lines);
        }
    }
}
