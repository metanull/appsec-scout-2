<?php

namespace App\Mcp\Tools\Operations;

use App\Database\TextSearch;
use App\Mcp\Support\ErrorLogScope;
use App\Mcp\Support\FindingPresenter;
use App\Mcp\Support\Format;
use App\Mcp\Support\Pagination;
use App\Mcp\Support\Scope;
use App\Mcp\Support\Search;
use App\Mcp\Tools\AppSecTool;
use App\Models\ErrorLog;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-errors')]
#[Title('List errors')]
#[Description('Lists Error log entries newest first: failed fetches and pushes (channel sync), repository collection and static analysis failures, MCP tool failures (channel mcp) and other application errors, with their context. Filter by asset, system or container, channel, level, a since date (default: the last 7 days) and text in the message. Stack traces are left out unless include_trace is true. Requires admin.errors.')]
#[IsReadOnly]
final class ListErrorsTool extends AppSecTool
{
    private const TRACE_LIMIT = 4000;

    public function schema(JsonSchema $schema): array
    {
        return [
            ...Scope::schema($schema),
            'channel' => $schema->string()->description('Only this channel, e.g. sync, mcp.'),
            'level' => $schema->string()->description('Only this level, e.g. error, warning.'),
            'since' => $schema->string()->description('ISO 8601 date-time; only entries at or after it. Default: 7 days ago.'),
            'search' => Search::schema($schema, 'the error message'),
            'include_trace' => $schema->boolean()->default(false)->description('Include the stack trace (cut to ' . self::TRACE_LIMIT . ' characters).'),
            ...Pagination::schema($schema),
        ];
    }

    protected function requiredPermissions(): array
    {
        return ['admin.errors'];
    }

    protected function run(Request $request, User $actor): ResponseFactory
    {
        $validated = $request->validate([
            'channel' => ['nullable', 'string', 'max:64'],
            'level' => ['nullable', 'string', 'max:32'],
            'since' => ['nullable', 'date'],
            'search' => Search::rules(),
            'include_trace' => ['nullable', 'boolean'],
        ]);

        $since = isset($validated['since']) ? Carbon::parse($validated['since']) : now()->subDays(7);
        $withTrace = (bool) ($validated['include_trace'] ?? false);

        $query = ErrorLogScope::apply(ErrorLog::query(), Scope::fromRequest($request))
            ->with(['softwareSystem:id,name', 'securityContainer:id,name'])
            ->where('occurred_at', '>=', $since)
            ->when(isset($validated['channel']), fn ($query) => $query->where('channel', $validated['channel']))
            ->when(isset($validated['level']), fn ($query) => $query->where('level', $validated['level']))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        TextSearch::apply($query, ['message'], $validated['search'] ?? null);

        return Pagination::paginate($query, $request, fn (ErrorLog $error): array => [
            'id' => $error->id,
            'occurred_at' => Format::date($error->getAttribute('occurred_at')),
            'level' => $error->level,
            'channel' => $error->channel,
            'message' => $error->message,
            'system' => FindingPresenter::named($error->softwareSystem),
            'container' => FindingPresenter::named($error->securityContainer),
            'context' => Format::arrayAttribute($error->getAttribute('context_json')),
            ...($withTrace ? ['trace' => is_string($error->trace) ? Str::limit($error->trace, self::TRACE_LIMIT) : null] : []),
        ]);
    }
}
