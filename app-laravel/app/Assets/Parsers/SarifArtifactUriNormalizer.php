<?php

namespace App\Assets\Parsers;

/**
 * Normalises a SARIF `artifactLocation.uri` into a repository-relative file path.
 * Strips only a `file://` scheme and a known `source_root` prefix (the absolute
 * directory the scanned tree was cloned into) — it deliberately never resolves
 * SARIF's `uriBaseId`/`originalUriBaseIds` indirection, which Trivy uses for its
 * own (already-relative) paths and must be left alone.
 */
final class SarifArtifactUriNormalizer
{
    public function normalize(string $uri, ?string $sourceRoot): string
    {
        $path = str_replace('\\', '/', trim($uri));

        if (strtolower(substr($path, 0, 7)) === 'file://') {
            $path = $this->stripFileScheme($path);
        }

        if ($sourceRoot !== null) {
            $root = rtrim(str_replace('\\', '/', $sourceRoot), '/');

            if ($root !== '' && str_starts_with($path, $root . '/')) {
                $path = substr($path, strlen($root) + 1);
            }
        }

        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        return $path === '' ? trim($uri) : $path;
    }

    /**
     * Drops the `file://` scheme and the (usually empty) authority component,
     * percent-decoding what remains. `file:///a/b` → `/a/b`; a Windows
     * `file:///C:/a/b` arrives as `/C:/a/b`, so the extra leading slash in
     * front of the drive letter is dropped too, leaving `C:/a/b`.
     */
    private function stripFileScheme(string $path): string
    {
        $rest = substr($path, 7);
        $slashPos = strpos($rest, '/');
        $remainder = $slashPos === false ? $rest : substr($rest, $slashPos);
        $decoded = rawurldecode($remainder);

        if (strlen($decoded) >= 3 && $decoded[0] === '/' && ctype_alpha($decoded[1]) && $decoded[2] === ':') {
            $decoded = substr($decoded, 1);
        }

        return $decoded;
    }
}
