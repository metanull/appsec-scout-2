<?php

namespace App\Models\Enums;

enum RepositoryProviderType: string
{
    case AzureRepos = 'azure-repos';
    case GitHub = 'github';
    case Bitbucket = 'bitbucket';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
