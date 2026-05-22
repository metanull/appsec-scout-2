<?php

namespace App\Sync;

use App\Credentials\Credential;
use App\Integrations\IntegrationSettingsRepository;

final class CredentialResolver
{
    public function __construct(private readonly IntegrationSettingsRepository $settings) {}

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

        $serviceUserId = $this->settings->serviceUserIdForCredentialKey($key);

        if ($serviceUserId !== null) {
            return $this->exact($key, $serviceUserId);
        }

        $systemCredential = $this->exact($key, null);

        if ($systemCredential instanceof Credential) {
            return $systemCredential;
        }

        return null;
    }
}
