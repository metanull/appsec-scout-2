<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'issue_type_id',
    'language',
    'api_vuln_name',
    'fetched_at',
    'markdown',
])]
class Article extends Model
{
    public $timestamps = false;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
        ];
    }
}
