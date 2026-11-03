<?php

namespace App\Filament\Support;

use App\Audit\Recorder;
use App\Models\User;
use Filament\Actions\ExportAction;
use Filament\Actions\Exports\Exporter;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

/**
 * The list pages' "Export" table header action: Filament's queued ExportAction
 * over the table's own query with its active filters, search and sort applied
 * (every matching row, not just the current page), as CSV and XLSX. Gated by
 * the `exports.create` permission; the user also needs access to the list
 * itself. Each request is audited with the active filters and chosen columns.
 */
final class ListPageExportAction
{
    public const string PERMISSION = 'exports.create';

    /** @param class-string<Exporter> $exporter */
    public static function make(string $exporter): ExportAction
    {
        return ExportAction::make()
            ->exporter($exporter)
            ->authorize(fn (): bool => self::currentUserCan())
            ->enableVisibleTableColumnsByDefault()
            ->columnMappingColumns(2)
            ->chunkSize(500)
            // Audited before the export is dispatched: Filament's export job forgets the auth
            // guards when it finishes, which on a synchronous queue would erase the requester
            // before an after() hook could record them.
            ->before(fn (array $data, Table $table, HasTable $livewire): null => self::audit($exporter, $data, $table, $livewire));
    }

    private static function currentUserCan(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can(self::PERMISSION);
    }

    /**
     * @param  class-string<Exporter>  $exporter
     * @param  array<string, mixed>  $data
     */
    private static function audit(string $exporter, array $data, Table $table, HasTable $livewire): null
    {
        $columnMap = is_array($data['columnMap'] ?? null) ? $data['columnMap'] : [];

        app(Recorder::class)->recordExportRequested($exporter::getModel(), [
            'exporter' => $exporter,
            'total_rows' => $exporter::modifyQuery($livewire->getTableQueryForExport())->toBase()->getCountForPagination(),
            'columns' => array_keys(array_filter($columnMap, fn (mixed $column): bool => is_array($column) && (bool) ($column['isEnabled'] ?? false))),
            'filters' => array_map(self::indicatorLabel(...), $table->getFilterIndicators()),
        ]);

        return null;
    }

    private static function indicatorLabel(Indicator $indicator): string
    {
        $label = $indicator->getLabel();

        return $label instanceof Htmlable ? strip_tags($label->toHtml()) : $label;
    }
}
