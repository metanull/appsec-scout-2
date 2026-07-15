<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'local_finding_id',
    'tracker_id',
    'work_item_id',
    'work_item_url',
    'work_item_title',
    'work_item_state',
    'created_by_user_id',
    'created_at',
    'synced_at',
])]
class LocalFindingWorkItemLink extends Model
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

    /** @return BelongsTo<LocalFinding, $this> */
    public function localFinding(): BelongsTo
    {
        return $this->belongsTo(LocalFinding::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
