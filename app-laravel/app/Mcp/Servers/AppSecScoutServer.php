<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Findings\GetAlertTool;
use App\Mcp\Tools\Findings\GetLocalFindingTool;
use App\Mcp\Tools\Findings\ListAlertsTool;
use App\Mcp\Tools\Findings\ListLocalFindingsTool;
use App\Mcp\Tools\Inventory\GetContainerLinksTool;
use App\Mcp\Tools\Inventory\ListSecurityContainersTool;
use App\Mcp\Tools\Inventory\ListSoftwareAssetsTool;
use App\Mcp\Tools\Inventory\ListSoftwareSystemsTool;
use App\Mcp\Tools\Sbom\GetSbomTool;
use App\Mcp\Tools\Sbom\ListSbomComponentsTool;
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
    Detectify; state and severity changes become pending until pushed back to the source) and
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
    ];
}
