<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'view_id', 'payload_json'])]
class UserViewState extends Model
{
    protected $table = 'user_view_state';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
