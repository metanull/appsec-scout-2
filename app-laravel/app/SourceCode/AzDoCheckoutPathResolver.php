<?php

namespace App\SourceCode;

use App\Sources\AzDo\AzDoClient;
use App\Sources\AzDo\AzDoItemNotFoundException;

/**
 * A scanner reports paths relative to where the build checked the repository out, which can
 * be a folder below the repository root (e.g. "project/src/App.java" for "src/App.java").
 * Neither the reported path nor the source's own item URL opens in Azure DevOps then, so code
 * reads (get-code) and source links resolve it here against the repository itself: read the
 * reported path, and when it does not exist, drop leading folders one at a time and read the
 * longest trailing path that does.
 */
final class AzDoCheckoutPathResolver
{
    /**
     * @param  'branch'|'commit'  $versionType
     * @return array{content: string, path: string} the file's content and its path in the repository
     *
     * @throws AzDoItemNotFoundException when neither the path nor any trailing part of it exists
     */
    public function read(AzDoClient $client, string $projectId, string $repositoryId, string $reportedPath, ?string $version, string $versionType = 'branch'): array
    {
        try {
            return ['content' => $client->getFileContent($projectId, $repositoryId, $reportedPath, $version, $versionType), 'path' => $reportedPath];
        } catch (AzDoItemNotFoundException $notFound) {
            $segments = explode('/', trim($reportedPath, '/'));

            if (count($segments) < 2) {
                throw $notFound;
            }
        }

        for ($skip = 1; $skip < count($segments); $skip++) {
            $path = '/' . implode('/', array_slice($segments, $skip));

            try {
                return ['content' => $client->getFileContent($projectId, $repositoryId, $path, $version, $versionType), 'path' => $path];
            } catch (AzDoItemNotFoundException) {
                continue;
            }
        }

        throw new AzDoItemNotFoundException("{$notFound->getMessage()} No shorter trailing part of that path exists there either.", 0, $notFound);
    }
}
