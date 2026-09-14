<?php

namespace App\Models;

use Database\Factories\ToolInvocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One row per scanning tool invoked against one repository during one
 * collection/analysis run — recorded on every outcome (findings, clean,
 * skipped, or failed), not only on failure. Answers "was repository X
 * scanned by tool Y, and with what result" — a question neither Attachment
 * (created only for non-empty output) nor ErrorLog (created only on failure;
 * AnalyzeRepositoryJob::logNoToolchain() writes only a log line, no row) can
 * answer on its own.
 */
#[Fillable([
    'run_type',
    'run_id',
    'security_container_id',
    'tool',
    'tool_version',
    'command_json',
    'outcome',
    'exit_code',
    'started_at',
    'finished_at',
    'duration_seconds',
    'output_tail',
])]
class ToolInvocation extends Model
{
    /** @use HasFactory<ToolInvocationFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'command_json' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * Polymorphic parent run — RepositoryCollectionRun or StaticAnalysisRun,
     * per run_type. Typed as the base Model, matching morphTo()'s own
     * inferred generic (see Attachment::owner() for the same pattern).
     *
     * @return MorphTo<Model, $this>
     */
    public function run(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<SecurityContainer, $this> */
    public function securityContainer(): BelongsTo
    {
        return $this->belongsTo(SecurityContainer::class);
    }
}
