<?php

namespace App\Trackers\Reconciliation;

/**
 * Canonical identity of a single Azure DevOps Advanced Security alert.
 *
 * AzDO addresses the same alert through several URL shapes: the portal form
 * (/{project}/_git/{repository}/alerts/{id}) and the Advanced Security API form
 * (/{project}/_apis/{area}/repositories/{repository}/alerts/{id}) on the advsec.* host.
 * Project and repository segments are independently either a GUID or a name.
 * Parsing a URL into this value object collapses the host and path shapes; combining
 * it with the project/repository refs an event already carries in its metadata
 * collapses the GUID/name duality.
 */
final readonly class AzDoAlertReference
{
    public function __construct(
        public string $organizationUrl,
        public string $projectRef,
        public string $repositoryRef,
        public string $alertId,
    ) {}

    /**
     * Parse an AzDO alert URL. Returns null for any URL that does not identify a
     * single alert (project roots, repository roots, item links, non-AzDO URLs);
     * that is normal control flow for candidate URLs, not an error.
     */
    public static function fromUrl(string $url): ?self
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts)) {
            return null;
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        $path = $parts['path'] ?? null;

        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
            return null;
        }

        if (! is_string($host) || $host === '' || ! is_string($path)) {
            return null;
        }

        $segments = self::pathSegments($path);

        return self::fromPortalSegments($scheme, $host, $segments)
            ?? self::fromAdvancedSecuritySegments($scheme, $host, $segments);
    }

    public function withRefs(string $projectRef, string $repositoryRef): self
    {
        return new self(
            $this->organizationUrl,
            self::normalizeRef($projectRef),
            self::normalizeRef($repositoryRef),
            $this->alertId,
        );
    }

    public function key(): string
    {
        return 'azdo|' . $this->organizationUrl . '|' . $this->projectRef . '|' . $this->repositoryRef . '|' . $this->alertId;
    }

    /**
     * Canonical form of a project or repository reference: percent-decoded and lowercased,
     * exactly as URL segments are treated when parsing.
     */
    public static function normalizeRef(string $ref): string
    {
        return strtolower(rawurldecode(trim($ref)));
    }

    /**
     * Portal form: .../{project}/_git/{repository}/alerts/{id}
     *
     * @param  list<string>  $segments
     */
    private static function fromPortalSegments(string $scheme, string $host, array $segments): ?self
    {
        foreach ($segments as $index => $segment) {
            if ($segment !== '_git' || $index < 1) {
                continue;
            }

            $projectRef = self::segmentAt($segments, $index - 1);
            $repositoryRef = self::segmentAt($segments, $index + 1);
            $alertId = self::alertIdAt($segments, $index + 2);

            if ($projectRef === null || $repositoryRef === null || $alertId === null) {
                continue;
            }

            return new self(
                self::organizationUrl($scheme, $host, array_slice($segments, 0, $index - 1)),
                $projectRef,
                $repositoryRef,
                $alertId,
            );
        }

        return null;
    }

    /**
     * Advanced Security API form: .../{project}/_apis/{area}/repositories/{repository}/alerts/{id}
     *
     * @param  list<string>  $segments
     */
    private static function fromAdvancedSecuritySegments(string $scheme, string $host, array $segments): ?self
    {
        foreach ($segments as $index => $segment) {
            if ($segment !== 'repositories' || $index < 3 || self::segmentAt($segments, $index - 2) !== '_apis') {
                continue;
            }

            $projectRef = self::segmentAt($segments, $index - 3);
            $repositoryRef = self::segmentAt($segments, $index + 1);
            $alertId = self::alertIdAt($segments, $index + 2);

            if ($projectRef === null || $repositoryRef === null || $alertId === null) {
                continue;
            }

            return new self(
                self::organizationUrl($scheme, $host, array_slice($segments, 0, $index - 3)),
                $projectRef,
                $repositoryRef,
                $alertId,
            );
        }

        return null;
    }

    /**
     * The numeric alert id when the segments at $index and $index + 1 are 'alerts' and a number.
     *
     * @param  list<string>  $segments
     */
    private static function alertIdAt(array $segments, int $index): ?string
    {
        if (self::segmentAt($segments, $index) !== 'alerts') {
            return null;
        }

        $alertId = self::segmentAt($segments, $index + 1);

        return $alertId !== null && ctype_digit($alertId) ? $alertId : null;
    }

    /** @param list<string> $segments */
    private static function segmentAt(array $segments, int $index): ?string
    {
        return $segments[$index] ?? null;
    }

    /** @param list<string> $organizationSegments */
    private static function organizationUrl(string $scheme, string $host, array $organizationSegments): string
    {
        $normalizedHost = strtolower($host);

        if (str_starts_with($normalizedHost, 'advsec.')) {
            $normalizedHost = substr($normalizedHost, strlen('advsec.'));
        }

        $organizationUrl = strtolower($scheme) . '://' . $normalizedHost;

        if ($organizationSegments !== []) {
            $organizationUrl .= '/' . implode('/', $organizationSegments);
        }

        return $organizationUrl;
    }

    /** @return list<string> */
    private static function pathSegments(string $path): array
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '') {
                continue;
            }

            $segments[] = self::normalizeRef($segment);
        }

        return $segments;
    }
}
