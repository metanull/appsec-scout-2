<?php

namespace App\Filament\Resources\AuditLogResource\Pages;

use App\Audit\AuditLog;
use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\SecurityEventResource;
use App\Filament\Resources\UserResource;
use App\Models\SecurityEvent;
use App\Models\User;
use Filament\Resources\Pages\ViewRecord;

class ViewAuditLog extends ViewRecord
{
    protected static string $resource = AuditLogResource::class;

    public function getUserUrl(): ?string
    {
        $userId = $this->currentRecord()->user_id;

        if ($userId === null) {
            return null;
        }

        if (! User::where('id', $userId)->exists()) {
            return null;
        }

        return UserResource::getUrl('edit', ['record' => $userId]);
    }

    public function getSubjectUrl(): ?string
    {
        $subjectType = $this->currentRecord()->subject_type;
        $subjectId = $this->currentRecord()->subject_id;

        if ($subjectType === null || $subjectId === null) {
            return null;
        }

        if (class_basename($subjectType) !== 'SecurityEvent') {
            return null;
        }

        if (! SecurityEvent::where('id', $subjectId)->exists()) {
            return null;
        }

        return SecurityEventResource::getUrl('view', ['record' => $subjectId]);
    }

    public function getRedactedPayload(): string
    {
        $payload = $this->currentRecord()->payload_json;

        if (! is_array($payload)) {
            return '{}';
        }

        return json_encode($this->redactArray($payload), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function redactArray(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->redactArray($value);
            } elseif (is_scalar($value) && $this->isSensitiveKey((string) $key)) {
                $result[$key] = '[redacted]';
            } elseif (is_string($value)) {
                $result[$key] = $this->redactString($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    private function redactString(string $value): string
    {
        return (string) preg_replace(
            '/((token|secret|password|api[_-]?key|pat|authorization)[^=:\"]*[=:\"]\s*)([^\s\",}]+)/i',
            '$1[redacted]',
            $value,
        );
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        return str_contains($normalized, 'token')
            || str_contains($normalized, 'secret')
            || str_contains($normalized, 'password')
            || str_contains($normalized, 'api_key')
            || str_contains($normalized, 'apikey')
            || str_contains($normalized, 'pat')
            || str_contains($normalized, 'authorization');
    }

    private function currentRecord(): AuditLog
    {
        /** @var AuditLog $record */
        $record = $this->getRecord();

        return $record;
    }
}
