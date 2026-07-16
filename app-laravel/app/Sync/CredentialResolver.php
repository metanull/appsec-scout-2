<?php

namespace App\Sync;

use App\Credentials\Credential;

final class CredentialResolver
{
    public function exact(string $key, ?int $ownerUserId): ?Credential
    {
        return Credential::query()
            ->where('integration_key', $key)
            ->when($ownerUserId === null, fn ($query) => $query->whereNull('owner_user_id'), fn ($query) => $query->where('owner_user_id', $ownerUserId))
            ->first();
    }
}
