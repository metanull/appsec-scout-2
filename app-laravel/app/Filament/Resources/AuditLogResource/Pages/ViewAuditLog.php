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

    protected string $view = 'filament.resources.audit-log-resource.pages.view-audit-log';

    public function getRedactedPayload(): string
    {
        /** @var AuditLog $record */
        $record = $this->record;
        $payload = $record->payload_json;

        if (! is_array($payload)) {
            return '—';
        }

        $redacted = $this->redactArray($payload);

        return json_encode($redacted, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    public function getUserUrl(): ?string
    {
        /** @var AuditLog $record */
        $record = $this->record;

        if ($record->user_id === null) {
            return null;
        }

        if (! User::where('id', $record->user_id)->exists()) {
            return null;
        }

        return UserResource::getUrl('edit', ['record' => $record->user_id]);
    }

    public function getSubjectUrl(): ?string
    {
        /** @var AuditLog $record */
        $record = $this->record;

        if ($record->subject_type !== 'App\\Models\\SecurityEvent' && $record->subject_type !== 'SecurityEvent') {
            return null;
        }

        if ($record->subject_id === null) {
            return null;
        }

        if (! SecurityEvent::where('id', $record->subject_id)->exists()) {
            return null;
        }

        return SecurityEventResource::getUrl('view', ['record' => $record->subject_id]);
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function redactArray(array $payload): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $redacted[$key] = $this->redactArray($value);

                continue;
            }

            if (is_scalar($value) && $this->isSensitiveKey((string) $key)) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            $redacted[$key] = is_string($value) ? $this->redactString($value) : $value;
        }

        return $redacted;
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
}
