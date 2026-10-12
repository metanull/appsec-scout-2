<?php

namespace App\Mcp\Tools\Collection;

use App\Audit\Recorder;
use App\Mcp\Support\RepositoryRunTargets;
use App\Mcp\Support\Scope;
use App\Mcp\Tools\AppSecTool;
use App\Models\StaticAnalysisRun;
use App\Models\User;
use App\SourceControl\Collection\DispatchStaticAnalysisRunsJob;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('run-static-analysis')]
#[Title('Run static analysis')]
#[Description('Re-runs static analysis (Roslynator, SpotBugs + Find Security Bugs, Opengrep) on the Azure DevOps repositories of one asset, system or container, producing code-quality local findings. Repositories whose head commit has not moved since their last clean analysis are skipped unless force is true. Queued in the background with the system credential; it returns immediately with what was queued. Only Azure DevOps systems are eligible (others are reported as skipped), and only one analysis run can be active at a time: when the scope spans several AzDO systems the first is queued and the rest reported as not_queued. Requires admin.queue.')]
final class RunStaticAnalysisTool extends AppSecTool
{
    private const OPERATION = 'static analysis';

    public function __construct(private readonly Recorder $recorder) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            ...Scope::schema($schema),
            'force' => $schema->boolean()->default(false)->description('Re-analyse every repository even when its head commit is unchanged.'),
        ];
    }

    protected function requiredPermissions(): array
    {
        return ['admin.queue'];
    }

    protected function run(Request $request, User $actor): ResponseFactory
    {
        $validated = $request->validate(['force' => ['nullable', 'boolean']]);
        $force = (bool) ($validated['force'] ?? false);
        $targets = RepositoryRunTargets::from(Scope::fromRequest($request)->upstreamSystems(), self::OPERATION);

        if (StaticAnalysisRun::query()->where('status', 'running')->exists()) {
            throw new InvalidArgumentException('A static analysis run is already in progress; nothing was queued. Try again once it finishes.');
        }

        DispatchStaticAnalysisRunsJob::dispatch($force, $targets->first->id);
        $this->recorder->recordAdminAction('operations.run_static_analysis', [
            'force' => $force,
            'software_system_id' => $targets->first->id,
        ]);

        return Response::structured([
            ...$targets->report(self::OPERATION),
            'force' => $force,
            'note' => 'Queued in the background with the system credential. Progress appears under Static analysis runs; new code-quality findings appear once it finishes.',
        ]);
    }
}
