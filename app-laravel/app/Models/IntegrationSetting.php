<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'integration_kind',
    'integration_id',
    'enabled',
    'fetch_interval_minutes',
    'service_user_id',
    'last_synced_at',
    'last_sync_status',
    'last_sync_message',
])]
class IntegrationSetting extends Model
{
    public const KIND_SOURCE = 'source';

    public const KIND_TRACKER = 'tracker';

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'fetch_interval_minutes' => 'integer',
            'service_user_id' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function serviceUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'service_user_id');
    }
}
