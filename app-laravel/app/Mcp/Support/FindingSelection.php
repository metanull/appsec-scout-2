<?php

namespace App\Mcp\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;

/**
 * Inputs shared by the triage write tools: an all-or-nothing list of finding ids, the
 * justification comment the triage services require, and the dry-run switch.
 */
final class FindingSelection
{
    public const MAX_IDS = 100;

    public const MIN_COMMENT = 10;

    public static function idsSchema(JsonSchema $schema, string $what): Type
    {
        return $schema->array()->items($schema->integer()->min(1))->min(1)->max(self::MAX_IDS)->required()
            ->description("The {$what} ids to change (1-" . self::MAX_IDS . '). Every id must exist, otherwise nothing changes.');
    }

    public static function commentSchema(JsonSchema $schema): Type
    {
        return $schema->string()->min(self::MIN_COMMENT)->required()
            ->description('Why: recorded as a comment on every changed finding (at least ' . self::MIN_COMMENT . ' characters).');
    }

    public static function dryRunSchema(JsonSchema $schema): Type
    {
        return $schema->boolean()->default(true)
            ->description('true (default): only describe the change. Show it to the user, then call again with false to apply.');
    }

    /** @return list<string> */
    public static function commentRules(): array
    {
        return ['required', 'string', 'min:' . self::MIN_COMMENT];
    }

    public static function isDryRun(Request $request): bool
    {
        $validated = $request->validate(['dry_run' => ['nullable', 'boolean']]);

        return (bool) ($validated['dry_run'] ?? true);
    }

    /**
     * Load every requested record, ordered by id, or fail naming the ids that do not exist.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return Collection<int, TModel>
     */
    public static function resolve(Request $request, string $field, string $model, string $label): Collection
    {
        $validated = $request->validate([
            $field => ['required', 'array', 'min:1', 'max:' . self::MAX_IDS],
            $field . '.*' => ['integer', 'min:1', 'distinct'],
        ]);

        $ids = array_map('intval', $validated[$field]);
        /** @var Collection<int, TModel> $found */
        $found = $model::query()->whereKey($ids)->orderBy('id')->get();
        $missing = array_values(array_diff($ids, array_map('intval', $found->modelKeys())));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                $field => "No {$label} with id(s): " . implode(', ', $missing) . '. Nothing was changed.',
            ]);
        }

        return $found;
    }
}
