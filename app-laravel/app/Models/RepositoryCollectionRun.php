<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'source_control_id',
    'batch_id',
    'started_at',
    'finished_at',
    'status',
    'counts_json',
    'error_message',
])]
class RepositoryCollectionRun extends Model
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

    /** @return MorphMany<ToolInvocation, $this> */
    public function toolInvocations(): MorphMany
    {
        return $this->morphMany(ToolInvocation::class, 'run');
    }
}
