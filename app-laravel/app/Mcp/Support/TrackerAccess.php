<?php

namespace App\Mcp\Support;

use App\Trackers\Contracts\Tracker;
use App\Trackers\Registry as TrackerRegistry;
use App\Triage\OperatorIntegrationRuntime;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;

/**
 * Tracker selection for the MCP work-item tools. Work items are user-triggered, so they always
 * use the acting user's personal tracker credentials (Profile → Integrations), never the system
 * credential; a user without them gets a clear error instead of a failed tracker call.
 */
final class TrackerAccess
{
    public function __construct(
        private readonly TrackerRegistry $trackers,
        private readonly OperatorIntegrationRuntime $runtime,
    ) {}

    public function trackerIdSchema(JsonSchema $schema): Type
    {
        return $schema->string()->enum($this->trackerIds())->required()->description('The tracker to use.');
    }

    public function resolve(Request $request): Tracker
    {
        $validated = $request->validate(['tracker_id' => ['required', 'string', Rule::in($this->trackerIds())]]);
        $tracker = $this->trackers->find($validated['tracker_id']);

        if ($tracker === null) {
            throw ValidationException::withMessages(['tracker_id' => "Unknown tracker {$validated['tracker_id']}."]);
        }

        return $tracker;
    }

    public function ensurePersonalCredentials(Tracker $tracker, int $userId): void
    {
        $missing = $this->runtime->missingTrackerCredentialLabels($tracker->id(), $userId);

        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf(
                'Missing personal %s credential(s): %s. Add them on your Profile integrations page.',
                $tracker->displayName(),
                implode(', ', $missing),
            ));
        }
    }

    /**
     * Run a tracker call with the acting user's personal credentials.
     *
     * @template TReturn
     *
     * @param  callable(Tracker): TReturn  $callback
     * @return TReturn
     */
    public function asUser(Tracker $tracker, int $userId, callable $callback): mixed
    {
        return $this->runtime->runTracker($tracker->id(), $userId, $callback);
    }

    /**
     * Reject work-item fields the tracker cannot set, so a ticket is never created with silently
     * dropped input.
     *
     * @param  array<string, mixed>  $input
     */
    public static function ensureSupported(Tracker $tracker, array $input): void
    {
        $capabilities = $tracker->capabilities();
        $unsupported = array_keys(array_filter([
            'labels' => ($input['labels'] ?? []) !== [] && ! $capabilities->supportsLabels,
            'priority' => ($input['priority'] ?? null) !== null && ! $capabilities->supportsPriority,
            'assignee_id' => ($input['assignee_id'] ?? null) !== null && ! $capabilities->supportsAssignee,
            'parent_id' => ($input['parent_id'] ?? null) !== null && ! $capabilities->supportsParent,
        ]));

        $messages = array_map(
            fn (string $field): string => "{$tracker->displayName()} does not support {$field}.",
            array_combine($unsupported, $unsupported),
        );

        $itemType = $input['item_type'] ?? null;

        if (is_string($itemType) && $capabilities->supportedItemTypes !== [] && ! in_array($itemType, $capabilities->supportedItemTypes, true)) {
            $messages['item_type'] = sprintf('%s item types are: %s.', $tracker->displayName(), implode(', ', $capabilities->supportedItemTypes));
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    /** @return array<string, mixed> */
    public static function capabilities(Tracker $tracker): array
    {
        $capabilities = $tracker->capabilities();

        return [
            'labels' => $capabilities->supportsLabels,
            'priority' => $capabilities->supportsPriority,
            'assignee' => $capabilities->supportsAssignee,
            'parent' => $capabilities->supportsParent,
            'item_types' => $capabilities->supportedItemTypes,
        ];
    }

    /** @return list<string> */
    private function trackerIds(): array
    {
        return array_map(fn (Tracker $tracker): string => $tracker->id(), $this->trackers->all());
    }
}
