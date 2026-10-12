<?php

namespace App\Mcp\Tools\Collection;

use App\Audit\Recorder;
use App\Mcp\Support\RepositoryRunTargets;
use App\Mcp\Support\Scope;
use App\Mcp\Tools\AppSecTool;
use App\Models\RepositoryCollectionRun;
use App\Models\User;
use App\SourceControl\Collection\DispatchRepositoryCollectionRunsJob;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('collect-repositories')]
#[Title('Collect repositories')]
#[Description('Re-collects the Azure DevOps repositories of one asset, system or container: clone + Trivy, producing the SBOM, vulnerability and secret local findings. Queued in the background with the system credential; it returns immediately with what was queued. Only Azure DevOps systems are eligible (others are reported as skipped), and only one collection run can be active at a time: when the scope spans several AzDO systems the first is queued and the rest reported as not_queued. Requires admin.queue.')]
final class CollectRepositoriesTool extends AppSecTool
{
    private const OPERATION = 'repository collection';

    public function __construct(private readonly Recorder $recorder) {}

    public function schema(JsonSchema $schema): array
    {
        return Scope::schema($schema);
    }

    protected function requiredPermissions(): array
    {
        return ['admin.queue'];
    }

    protected function run(Request $request, User $actor): ResponseFactory
    {
        $targets = RepositoryRunTargets::from(Scope::fromRequest($request)->upstreamSystems(), self::OPERATION);

        if (RepositoryCollectionRun::query()->where('status', 'running')->exists()) {
            throw new InvalidArgumentException('A repository collection run is already in progress; nothing was queued. Try again once it finishes.');
        }

        DispatchRepositoryCollectionRunsJob::dispatch($targets->first->id);
        $this->recorder->recordAdminAction('operations.collect_repositories', ['software_system_id' => $targets->first->id]);

        return Response::structured([
            ...$targets->report(self::OPERATION),
            'note' => 'Queued in the background with the system credential. Progress appears under Repository collection runs; new SBOM and vulnerability findings appear once it finishes.',
        ]);
    }
}
