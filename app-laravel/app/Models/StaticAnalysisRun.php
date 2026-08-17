<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'source_control_id',
    'batch_id',
    'started_at',
    'finished_at',
    'status',
    'counts_json',
    'error_message',
])]
class StaticAnalysisRun extends Model
{
    public $timestamps = false;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'counts_json' => 'array',
        ];
    }
}
