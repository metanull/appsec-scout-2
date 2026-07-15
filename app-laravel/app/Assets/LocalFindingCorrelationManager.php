<?php

namespace App\Assets;

use App\Audit\Recorder;
use App\Models\LocalFinding;

final class LocalFindingCorrelationManager
{
    public function __construct(private readonly Recorder $recorder) {}

    public function unlink(LocalFinding $finding): void
    {
        $previousEventId = $finding->getAttribute('correlated_security_event_id');
        $previousMethod = $finding->getAttribute('correlation_method');

        $finding->forceFill([
            'correlated_security_event_id' => null,
            'correlation_method' => null,
            'updated_at' => now(),
        ])->save();

        $this->recorder->recordCorrelationCleared(LocalFinding::class, (string) $finding->id, [
            'previous_security_event_id' => $previousEventId,
            'previous_correlation_method' => $previousMethod,
        ]);
    }
}
