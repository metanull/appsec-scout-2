<?php

namespace App\SecurityEvents;

/**
 * Accumulates a deduplicated, priority-aware list of reference links shared by
 * the alert (EventLinkCatalog) and Local Finding (LocalFindingLinkCatalog)
 * views, so both render the same "Links & References" section identically.
 *
 * Every link is validated as a safe http(s) URL before being kept. When two
 * entries share a URL the higher-priority one wins its label/kind — e.g. the
 * code-derived "Repository" (priority 3) supersedes a plain source "Repository"
 * (priority 2) pointing at the same page.
 *
 * @phpstan-type LinkRow array{label: string, url: string, kind: string, external: bool}
 */
final class LinkCollector
{
    public const KIND_SOURCE = 'source';

    public const KIND_CODE = 'code';

    public const KIND_REMEDIATION = 'remediation';

    public const KIND_STANDARD = 'standard';

    public const KIND_TRACKER = 'tracker';

    /** @var list<LinkRow> */
    private array $links = [];

    /** @var array<string, array{priority: int, index: int}> */
    private array $seen = [];

    public function add(string $label, ?string $url, string $kind, int $priority = 0): void
    {
        if ($url === null || $url === '') {
            return;
        }

        if (! SourceLinkHelper::isSafeUrl($url)) {
            return;
        }

        if (isset($this->seen[$url])) {
            if ($priority <= $this->seen[$url]['priority']) {
                return;
            }

            $this->links[$this->seen[$url]['index']] = [
                'label' => $label,
                'url' => $url,
                'kind' => $kind,
                'external' => true,
            ];
            $this->seen[$url]['priority'] = $priority;

            return;
        }

        $this->seen[$url] = [
            'priority' => $priority,
            'index' => count($this->links),
        ];
        $this->links[] = [
            'label' => $label,
            'url' => $url,
            'kind' => $kind,
            'external' => true,
        ];
    }

    /**
     * @return list<LinkRow>
     */
    public function all(): array
    {
        return $this->links;
    }

    /**
     * Return the display label for a link kind.
     */
    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            self::KIND_SOURCE => 'Source',
            self::KIND_CODE => 'Code',
            self::KIND_REMEDIATION => 'Remediation',
            self::KIND_STANDARD => 'Standards',
            self::KIND_TRACKER => 'Tracker',
            default => 'Other',
        };
    }
}
