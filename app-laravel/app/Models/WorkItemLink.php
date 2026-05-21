<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'event_id',
    'tracker_id',
    'work_item_id',
    'work_item_url',
    'work_item_title',
    'work_item_state',
    'created_by_user_id',
    'created_at',
    'synced_at',
])]
class WorkItemLink extends Model
{
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SecurityEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(SecurityEvent::class, 'event_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<self, $this> */
    public function groupedLinks(): HasMany
    {
        return $this->hasMany(self::class, 'work_item_id', 'work_item_id')
            ->where('tracker_id', $this->tracker_id)
            ->orderBy('event_id');
    }
}
