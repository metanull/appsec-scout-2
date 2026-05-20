<?php

namespace App\Filament\Support;

use App\Models\UserViewState;

class UserViewStateStore
{
    /** @return array<string, mixed> */
    public function load(int $userId, string $viewId): array
    {
        $state = UserViewState::query()
            ->where('user_id', $userId)
            ->where('view_id', $viewId)
            ->first();

        if ($state === null) {
            return [];
        }

        $rawPayload = $state->getRawOriginal('payload_json');

        if (! is_string($rawPayload) || $rawPayload === '') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($rawPayload, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $payload */
    public function save(int $userId, string $viewId, array $payload): void
    {
        UserViewState::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'view_id' => $viewId,
            ],
            [
                'payload_json' => $payload,
            ],
        );
    }
}
