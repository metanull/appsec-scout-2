<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'integration_kind',
    'integration_id',
    'enabled',
    'fetch_interval_minutes',
    'last_synced_at',
    'last_sync_status',
    'last_sync_message',
])]
class IntegrationSetting extends Model
{
    public const KIND_SOURCE = 'source';

    public const KIND_TRACKER = 'tracker';

    public const KIND_SOURCE_CONTROL = 'source_control';

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'fetch_interval_minutes' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }
}
