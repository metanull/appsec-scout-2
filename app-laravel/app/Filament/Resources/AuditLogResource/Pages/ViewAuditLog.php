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

    private function currentRecord(): AuditLog
    {
        /** @var AuditLog $record */
        $record = $this->getRecord();

        return $record;
    }
}
