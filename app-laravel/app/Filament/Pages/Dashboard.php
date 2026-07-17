<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AlertsByStateChartWidget;
use App\Filament\Widgets\AlertsByStateTableWidget;
use App\Filament\Widgets\AlertsByWorkItemStatusChartWidget;
use App\Filament\Widgets\AlertsByWorkItemStatusTableWidget;
use App\Filament\Widgets\LocalFindingsByStateChartWidget;
use App\Filament\Widgets\LocalFindingsByStateTableWidget;
use App\Filament\Widgets\LocalFindingsByWorkItemStatusChartWidget;
use App\Filament\Widgets\LocalFindingsByWorkItemStatusTableWidget;
use App\Filament\Widgets\OpenAlertsBySeverityChartWidget;
use App\Filament\Widgets\OpenAlertsBySeverityTableWidget;
use App\Filament\Widgets\OpenAlertsBySourceBreakdownTableWidget;
use App\Filament\Widgets\OpenAlertsBySourceChartWidget;
use App\Filament\Widgets\OpenAlertsBySystemChartWidget;
use App\Filament\Widgets\OpenAlertsBySystemTableWidget;
use App\Filament\Widgets\OpenAlertsByTypeChartWidget;
use App\Filament\Widgets\OpenAlertsByTypeTableWidget;
use App\Filament\Widgets\OpenLocalFindingsByKindChartWidget;
use App\Filament\Widgets\OpenLocalFindingsByKindTableWidget;
use App\Filament\Widgets\OpenLocalFindingsBySeverityChartWidget;
use App\Filament\Widgets\OpenLocalFindingsBySeverityTableWidget;
use App\Filament\Widgets\OpenLocalFindingsBySystemChartWidget;
use App\Filament\Widgets\OpenLocalFindingsBySystemTableWidget;
use App\Filament\Widgets\OperationsHealthStatsWidget;
use App\Filament\Widgets\RecentErrorsTableWidget;
use App\Filament\Widgets\RecentFailedJobsTableWidget;
use App\Filament\Widgets\RecentSyncRunsTableWidget;
use App\Filament\Widgets\SbomScanStatusWidget;
use App\Filament\Widgets\StaticAnalysisScanStatusWidget;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Pins the dashboard to the eleven breakdown pairs in the agreed grouped order
 * with alternating pie position — Alerts first with the pie right of the table,
 * Local Findings second with the pie left — over a three-column grid so each
 * pie (span 1) + table (span 2) pair fills one row. The operations widgets keep
 * their existing relative order below. An explicit widget list replaces
 * discovery-based ordering, so the layout no longer depends on `$sort`.
 */
class Dashboard extends BaseDashboard
{
    public function getColumns(): int|array
    {
        return 3;
    }

    /**
     * @return list<class-string>
     */
    public function getWidgets(): array
    {
        return [
            AlertsByStateTableWidget::class,
            AlertsByStateChartWidget::class,
            LocalFindingsByStateChartWidget::class,
            LocalFindingsByStateTableWidget::class,

            AlertsByWorkItemStatusTableWidget::class,
            AlertsByWorkItemStatusChartWidget::class,
            LocalFindingsByWorkItemStatusChartWidget::class,
            LocalFindingsByWorkItemStatusTableWidget::class,

            OpenAlertsBySystemTableWidget::class,
            OpenAlertsBySystemChartWidget::class,
            OpenLocalFindingsBySystemChartWidget::class,
            OpenLocalFindingsBySystemTableWidget::class,

            OpenAlertsBySourceBreakdownTableWidget::class,
            OpenAlertsBySourceChartWidget::class,

            OpenAlertsByTypeTableWidget::class,
            OpenAlertsByTypeChartWidget::class,
            OpenLocalFindingsByKindChartWidget::class,
            OpenLocalFindingsByKindTableWidget::class,

            OpenAlertsBySeverityTableWidget::class,
            OpenAlertsBySeverityChartWidget::class,
            OpenLocalFindingsBySeverityChartWidget::class,
            OpenLocalFindingsBySeverityTableWidget::class,

            RecentSyncRunsTableWidget::class,
            OperationsHealthStatsWidget::class,
            SbomScanStatusWidget::class,
            StaticAnalysisScanStatusWidget::class,
            RecentFailedJobsTableWidget::class,
            RecentErrorsTableWidget::class,
        ];
    }
}
