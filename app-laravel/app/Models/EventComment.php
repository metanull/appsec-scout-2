<?php

namespace App\Models;

use Database\Factories\EventCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'event_id', 'body', 'author_user_id', 'upstream_comment_id', 'created_at',
])]
class EventComment extends Model
{
    /** @use HasFactory<EventCommentFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SecurityEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(SecurityEvent::class, 'event_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
