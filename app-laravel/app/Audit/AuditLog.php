<?php

namespace App\Audit;

use Illuminate\Database\Eloquent\Model;

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
}
