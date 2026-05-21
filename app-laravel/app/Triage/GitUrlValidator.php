<?php

namespace App\Triage;

use InvalidArgumentException;

class GitUrlValidator
{
    private const URL_PATTERN = '/^https:\/\/[a-z0-9.\-]+\/[\w\/._\-]+(\.git)?$/';

    public function validate(string $gitUrl): string
    {
        $gitUrl = trim($gitUrl);

        if ($gitUrl === '') {
            throw new InvalidArgumentException('The git URL is required.');
        }

        foreach ([' ', "\t", "\n", "\r", '"', "'", '--', '$', '`', "\0"] as $forbidden) {
            if (str_contains($gitUrl, $forbidden)) {
                throw new InvalidArgumentException('The git URL contains forbidden characters.');
            }
        }

        if (preg_match(self::URL_PATTERN, $gitUrl) !== 1) {
            throw new InvalidArgumentException('The git URL must be an https repository URL.');
        }

        return $gitUrl;
    }
}
