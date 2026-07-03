<?php

namespace App\Models;

use App\Models\Casts\BinaryCast;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'owner_type',
    'owner_id',
    'kind',
    'mime',
    'name',
    'payload',
    'size_bytes',
    'created_at',
    'created_by_user_id',
    'created_by_command',
])]
class Attachment extends Model
{
    public $timestamps = false;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'payload' => BinaryCast::class,
            'created_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
