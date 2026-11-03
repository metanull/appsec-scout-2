<?php

namespace App\Jobs;

use Filament\Actions\Exports\Models\Export;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Deletes list-page exports older than the retention window — the CSV/XLSX
 * files on the export's disk first, then the `exports` row, so a download link
 * never outlives its file nor a file its row.
 */
class PruneExports implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(private readonly int $retainDays = 7) {}

    public function handle(): int
    {
        $pruned = 0;

        Export::query()
            ->where('created_at', '<', now()->subDays($this->retainDays))
            ->each(function (Export $export) use (&$pruned): void {
                $export->deleteFileDirectory();
                $export->delete();
                $pruned++;
            });

        return $pruned;
    }
}
