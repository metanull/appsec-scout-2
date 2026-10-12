<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Collection\CollectRepositoriesTool;
use App\Mcp\Tools\Collection\FetchAlertsTool;
use App\Mcp\Tools\Collection\RunStaticAnalysisTool;
use App\Mcp\Tools\Findings\GetAlertTool;
use App\Mcp\Tools\Findings\GetLocalFindingTool;
use App\Mcp\Tools\Findings\GetTriageSummaryTool;
use App\Mcp\Tools\Findings\ListAlertsTool;
use App\Mcp\Tools\Findings\ListLocalFindingsTool;
use App\Mcp\Tools\Inventory\GetContainerLinksTool;
use App\Mcp\Tools\Inventory\LinkSystemToAssetTool;
use App\Mcp\Tools\Inventory\ListSecurityContainersTool;
use App\Mcp\Tools\Inventory\ListSoftwareAssetsTool;
use App\Mcp\Tools\Inventory\ListSoftwareSystemsTool;
use App\Mcp\Tools\Inventory\MoveContainerToSystemTool;
use App\Mcp\Tools\Inventory\RestoreContainerSourceLinkTool;
use App\Mcp\Tools\Inventory\UnlinkSystemFromAssetTool;
use App\Mcp\Tools\Sbom\GetSbomTool;
use App\Mcp\Tools\Sbom\ListSbomComponentsTool;
use App\Mcp\Tools\Sync\ListPendingChangesTool;
use App\Mcp\Tools\Sync\PushPendingChangesTool;
use App\Mcp\Tools\Trackers\CreateAlertsWorkItemTool;
use App\Mcp\Tools\Trackers\CreateLocalFindingsWorkItemTool;
use App\Mcp\Tools\Trackers\GetTrackerOptionsTool;
use App\Mcp\Tools\Triage\ChangeAlertSeverityTool;
use App\Mcp\Tools\Triage\ChangeAlertStateTool;
use App\Mcp\Tools\Triage\ChangeLocalFindingSeverityTool;
use App\Mcp\Tools\Triage\ChangeLocalFindingStatusTool;
use App\Mcp\Tools\Triage\CommentAlertTool;
use App\Mcp\Tools\Triage\CommentLocalFindingTool;
use App\Mcp\Tools\WhoAmITool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('AppSec Scout')]
#[Version('1.0.0')]
#[Instructions(<<<'TEXT'
    AppSec Scout aggregates application-security findings for triage.

    Inventory hierarchy: a software asset groups software systems (one per source project:
    an Azure DevOps project, an AppScan application, a Detectify domain); a software system
    holds security containers (e.g. repositories). Triage per asset or per system covers every
    source at once.

    Findings come in two kinds: alerts (from AzDO Advanced Security, AppScan on Cloud,
    Detectify; state and severity changes become pending until pushed back to the source with push-pending-changes) and
    local findings (from Trivy scans and static analysis of collected repositories; changes
    apply immediately).

    Ids are always taken from list tools. Every tool runs as one AppSec Scout user (see
    whoami) and is limited by that user's permissions. Tools that change data default to a dry
    run that describes the change; show it to the user and repeat the call with dry_run=false
    only once they agree.
    TEXT)]
final class AppSecScoutServer extends Server
{
    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        WhoAmITool::class,
        ListSoftwareAssetsTool::class,
        ListSoftwareSystemsTool::class,
        ListSecurityContainersTool::class,
        GetContainerLinksTool::class,
        ListAlertsTool::class,
        GetAlertTool::class,
        ListLocalFindingsTool::class,
        GetLocalFindingTool::class,
        ListSbomComponentsTool::class,
        GetSbomTool::class,
        GetTriageSummaryTool::class,
        ChangeAlertStateTool::class,
        ChangeAlertSeverityTool::class,
        CommentAlertTool::class,
        ChangeLocalFindingStatusTool::class,
        ChangeLocalFindingSeverityTool::class,
        CommentLocalFindingTool::class,
        ListPendingChangesTool::class,
        PushPendingChangesTool::class,
        GetTrackerOptionsTool::class,
        CreateAlertsWorkItemTool::class,
        CreateLocalFindingsWorkItemTool::class,
        FetchAlertsTool::class,
        CollectRepositoriesTool::class,
        RunStaticAnalysisTool::class,
        LinkSystemToAssetTool::class,
        UnlinkSystemFromAssetTool::class,
        MoveContainerToSystemTool::class,
        RestoreContainerSourceLinkTool::class,
    ];
}
