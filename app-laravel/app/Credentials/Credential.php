<?php

namespace App\Credentials;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Credential extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'owner_user_id',
        'integration_key',
        'value',
        'description',
        'last_tested_at',
        'last_tested_ok',
        'last_tested_error',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'value' => 'encrypted',
        'last_tested_at' => 'datetime',
        'last_tested_ok' => 'boolean',
    ];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
