<?php

namespace App\SecurityEvents;

/**
 * Builds standard reference URLs for CVE and CWE identifiers and validates
 * outbound links from source normalizers.
 */
final class SourceLinkHelper
{
    /**
     * Return the NVD detail URL for a CVE ID, or null when the ID is absent or malformed.
     */
    public static function cveLinkUrl(?string $cveId): ?string
    {
        if ($cveId === null || $cveId === '') {
            return null;
        }

        $normalized = strtoupper(trim($cveId));

        if (! preg_match('/^CVE-\d{4}-\d{4,}$/i', $normalized)) {
            return null;
        }

        return 'https://nvd.nist.gov/vuln/detail/' . $normalized;
    }

    /**
     * Return the MITRE CWE definition URL for a CWE ID, or null when absent or malformed.
     * Accepts integer IDs or strings like "CWE-79" or "79".
     */
    public static function cweLinkUrl(int|string|null $cweId): ?string
    {
        if ($cweId === null || $cweId === '') {
            return null;
        }

        $stripped = preg_replace('/^CWE-?/i', '', (string) $cweId);

        if (! is_string($stripped) || ! ctype_digit($stripped)) {
            return null;
        }

        $numericId = (int) $stripped;

        if ($numericId <= 0) {
            return null;
        }

        return 'https://cwe.mitre.org/data/definitions/' . $numericId . '.html';
    }

    /**
     * Return whether a URL is safe to render as an outbound hyperlink.
     * Only http and https schemes are considered safe.
     */
    public static function isSafeUrl(?string $url): bool
    {
        if ($url === null || $url === '') {
            return false;
        }

        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }
}
