<?php

namespace App\Mcp\Tools\Collection;

use App\Audit\Recorder;
use App\Filament\Support\OperationsAuthorization;
use App\Mcp\Support\Scope;
use App\Mcp\Tools\AppSecTool;
use App\Models\SoftwareSystem;
use App\Models\User;
use App\Sync\FetchSystemJob;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('fetch-alerts')]
#[Title('Fetch alerts from the sources')]
#[Description('Refreshes the alerts (and containers) of one asset, system or container from their sources (AzDO, AppScan, Detectify). Queues one background fetch per software system, run with the system credential; it returns immediately with what was queued, and new or updated alerts appear once the jobs finish (failures go to the Error log). A container refreshes the whole system its source reports it under; to refresh a few known alerts, reload-alerts is much cheaper. Requires admin.queue or work-items.sync.')]
final class FetchAlertsTool extends AppSecTool
{
    public function __construct(private readonly Recorder $recorder) {}

    public function schema(JsonSchema $schema): array
    {
        return Scope::schema($schema);
    }

    protected function run(Request $request, User $actor): Response|ResponseFactory
    {
        if (! OperationsAuthorization::canQueueOrSync()) {
            return Response::error('Permission denied: this tool requires the admin.queue or work-items.sync permission.');
        }

        $systems = Scope::fromRequest($request)->upstreamSystems();

        if ($systems->isEmpty()) {
            throw new InvalidArgumentException('No active software system in this scope.');
        }

        foreach ($systems as $system) {
            FetchSystemJob::dispatch($system->id);
            $this->recorder->recordAdminAction('operations.dispatch_system_fetch', [
                'software_system_id' => $system->id,
                'source_id' => $system->source_id,
            ]);
        }

        return Response::structured([
            'queued' => $systems->map(fn (SoftwareSystem $system): array => [
                'system_id' => $system->id,
                'name' => $system->name,
                'source_id' => $system->source_id,
            ])->values()->all(),
            'note' => 'Queued in the background with the system credential. Re-query the alerts once the fetch has finished; failures are recorded in the Error log (channel sync).',
        ]);
    }
}
