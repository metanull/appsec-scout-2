<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ErrorLog extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'level',
        'channel',
        'software_system_id',
        'security_container_id',
        'message',
        'context_json',
        'trace',
        'occurred_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'context_json' => 'array',
        'occurred_at' => 'datetime',
    ];

    public $timestamps = false;
}
