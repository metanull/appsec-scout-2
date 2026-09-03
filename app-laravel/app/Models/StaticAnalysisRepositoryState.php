<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The commit of a repository that static analysis last completed cleanly
 * against. AnalyzeRepositoryJob compares the remote head against this row
 * and skips the repository outright when they match.
 *
 * @property int $security_container_id
 * @property string $commit_sha
 * @property \Illuminate\Support\Carbon $analyzed_at
 * @property int|null $analyzed_run_id
 */
#[Fillable([
    'security_container_id',
    'commit_sha',
    'analyzed_at',
    'analyzed_run_id',
])]
class StaticAnalysisRepositoryState extends Model
{
    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'security_container_id' => 'integer',
            'analyzed_at' => 'datetime',
            'analyzed_run_id' => 'integer',
        ];
    }

    /** @return BelongsTo<SecurityContainer, $this> */
    public function securityContainer(): BelongsTo
    {
        return $this->belongsTo(SecurityContainer::class);
    }
}
