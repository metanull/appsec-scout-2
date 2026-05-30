<?php

/**
 * Filament primitive compliance check (Story 4.2).
 *
 * This test scans all Blade files under resources/views/filament/ and fails if
 * it finds raw HTML controls that should be expressed with Filament primitives.
 *
 * Rules:
 *  - No raw <table>, <thead>, <tbody>, <tr>, <td>, <th> elements.
 *  - No raw <input> elements.
 *  - No raw <select> elements.
 *  - No raw <textarea> elements.
 *  - No wire:submit attributes (use Filament form actions).
 *
 * Files may be added to the ALLOW_LIST with a documented reason ONLY when a
 * specific Filament primitive cannot satisfy the requirement.
 *
 * After Epics 1–3 complete, the allow-list must remain empty unless a
 * primitive gap is explicitly documented here.
 */

// Allow-list: file paths (relative to resources/views/filament/) → reason.
// Must be empty once all epics are complete.
$allowList = [
    // Example: 'pages/my-special-page.blade.php' => 'Requires raw <canvas> — no Filament widget covers WebGL',
];

$forbiddenPatterns = [
    '/<table[\s>]/i' => 'raw <table> element (use Filament TableWidget or relation manager)',
    '/<thead[\s>]/i' => 'raw <thead> element (use Filament TableWidget or relation manager)',
    '/<tr[\s>]/i' => 'raw <tr> element (use Filament TableWidget or relation manager)',
    '/<td[\s>]/i' => 'raw <td> element (use Filament TableWidget or relation manager)',
    '/<th[\s>]/i' => 'raw <th> element (use Filament TableWidget or relation manager)',
    '/<input[\s>]/i' => 'raw <input> element (use Filament TextInput, Toggle, etc.)',
    '/<select[\s>]/i' => 'raw <select> element (use Filament Select component)',
    '/<textarea[\s>]/i' => 'raw <textarea> element (use Filament Textarea component)',
    '/wire:submit/i' => 'wire:submit attribute (use Filament action form)',
];

$viewsDir = dirname(__DIR__, 3) . '/resources/views/filament';

$bladeFiles = collect(
    new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($viewsDir, RecursiveDirectoryIterator::SKIP_DOTS)
    )
)
    ->filter(fn (SplFileInfo $file): bool => $file->isFile() && str_ends_with($file->getFilename(), '.blade.php'))
    ->values();

$violations = [];

foreach ($bladeFiles as $file) {
    $relativePath = ltrim(str_replace(str_replace('/', DIRECTORY_SEPARATOR, $viewsDir), '', $file->getPathname()), DIRECTORY_SEPARATOR);
    $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);

    if (isset($allowList[$relativePath])) {
        continue;
    }

    $contents = file_get_contents($file->getPathname());

    if ($contents === false) {
        continue;
    }

    foreach ($forbiddenPatterns as $pattern => $description) {
        if (preg_match($pattern, $contents)) {
            $violations[] = "[{$relativePath}] contains {$description}";
        }
    }
}

test('filament views use primitives not raw html controls', function () use ($violations) {
    expect($violations)->toBe([], 'The following Filament Blade views contain raw HTML that should be replaced with Filament primitives:' . PHP_EOL . implode(PHP_EOL, $violations));
});
