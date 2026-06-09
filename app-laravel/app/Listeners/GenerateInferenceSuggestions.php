<?php

namespace App\Listeners;

use App\Context\Inference\FuzzyMappingSuggestionGenerator;
use App\Events\SyncRunFinished;

final class GenerateInferenceSuggestions
{
    public function __construct(private FuzzyMappingSuggestionGenerator $generator) {}

    public function handle(SyncRunFinished $event): void
    {
        if (($event->run->status ?? '') !== 'success') {
            return;
        }

        // Run the deterministic generator to produce pending suggestions
        // from the freshly synced metadata. Any exceptions should bubble
        // so failures surface in tests/runtimes.
        $this->generator->generate();
    }
}
