<?php

namespace App\Sources\Context;

use Illuminate\Support\Arr;

/**
 * Central schema for reusable source context facts stored in metadata arrays.
 *
 * This class defines supported metadata keys and helper methods for working
 * with nested facts without mutating unrelated metadata branches.
 */
final class SourceContextFacts
{
    public const SOURCE_ALERT_WEB_URL = 'source.alert.web_url';

    public const AZDO_PROJECT_ID = 'azdo.project.id';

    public const AZDO_PROJECT_NAME = 'azdo.project.name';

    public const AZDO_REPOSITORY_ID = 'azdo.repository.id';

    public const AZDO_REPOSITORY_NAME = 'azdo.repository.name';

    public const AZDO_REPOSITORY_WEB_URL = 'azdo.repository.web_url';

    public const AZDO_REPOSITORY_REMOTE_URL = 'azdo.repository.remote_url';

    public const CODE_DEFAULT_BRANCH = 'code.default_branch';

    public const CODE_COMMIT_SHA = 'code.commit_sha';

    public const SECURITY_CVE = 'security.cve';

    public const SECURITY_CWE = 'security.cwe';

    public const PACKAGE_NAME = 'package.name';

    public const PACKAGE_VERSION = 'package.version';

    public const PACKAGE_ECOSYSTEM = 'package.ecosystem';

    public const TRACKER_JIRA_PROJECT_KEY = 'tracker.jira.project_key';

    public const TRACKER_GITHUB_REPOSITORY = 'tracker.github.repository';

    /**
     * @return list<string>
     */
    public static function supportedKeys(): array
    {
        return [
            self::SOURCE_ALERT_WEB_URL,
            self::AZDO_PROJECT_ID,
            self::AZDO_PROJECT_NAME,
            self::AZDO_REPOSITORY_ID,
            self::AZDO_REPOSITORY_NAME,
            self::AZDO_REPOSITORY_WEB_URL,
            self::AZDO_REPOSITORY_REMOTE_URL,
            self::CODE_DEFAULT_BRANCH,
            self::CODE_COMMIT_SHA,
            self::SECURITY_CVE,
            self::SECURITY_CWE,
            self::PACKAGE_NAME,
            self::PACKAGE_VERSION,
            self::PACKAGE_ECOSYSTEM,
            self::TRACKER_JIRA_PROJECT_KEY,
            self::TRACKER_GITHUB_REPOSITORY,
        ];
    }

    public static function isSupportedKey(string $key): bool
    {
        return in_array($key, self::supportedKeys(), true);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function has(array $metadata, string $key): bool
    {
        return Arr::has($metadata, $key);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function get(array $metadata, string $key): mixed
    {
        return Arr::get($metadata, $key);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function getString(array $metadata, string $key): ?string
    {
        $value = self::get($metadata, $key);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function set(array $metadata, string $key, mixed $value): array
    {
        if ($value === null) {
            Arr::forget($metadata, $key);

            /** @var array<string, mixed> $metadata */
            return self::pruneEmptyArrays($metadata);
        }

        Arr::set($metadata, $key, $value);

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private static function pruneEmptyArrays(array $metadata): array
    {
        foreach ($metadata as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            /** @var array<string, mixed> $value */
            $metadata[$key] = self::pruneEmptyArrays($value);

            if ($metadata[$key] === []) {
                unset($metadata[$key]);
            }
        }

        return $metadata;
    }
}
