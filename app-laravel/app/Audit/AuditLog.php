<?php

namespace App\Audit;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'actor_kind',
        'action',
        'subject_type',
        'subject_id',
        'payload_json',
        'ip',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'payload_json' => 'array',
        'created_at' => 'datetime',
    ];

    public $timestamps = false;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
