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

    public function resolve(string $key, ?int $preferredUserId = null): ?Credential
    {
        if ($preferredUserId !== null) {
            return $this->exact($key, $preferredUserId);
        }

        $authUserId = auth()->id();

        if (is_int($authUserId)) {
            $credential = $this->exact($key, $authUserId);

            if ($credential instanceof Credential) {
                return $credential;
            }
        }

        $systemCredential = $this->exact($key, null);

        if ($systemCredential instanceof Credential) {
            return $systemCredential;
        }

        $serviceUserId = config('integration_settings.service_user_id');

        if (is_int($serviceUserId) || (is_string($serviceUserId) && $serviceUserId !== '')) {
            return $this->exact($key, (int) $serviceUserId);
        }

        return null;
    }
}
