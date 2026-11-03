<?php

/**
 * The app container's scheduler and queue worker must run as the user php-fpm
 * serves requests as. Jobs write files the web tier reads back — Filament's
 * list exports are stored private (0700 directory, 0600 files), so an export
 * written by a root worker 404s on download (www-data cannot see it).
 */
it('runs the queue worker and the scheduler as www-data', function (string $program) {
    $path = dirname(base_path()) . '/docker/supervisord.conf';

    if (! is_file($path)) {
        // Local Docker checks mount only app-laravel/; CI checks out the whole repository.
        $this->markTestSkipped('docker/supervisord.conf is not available in this checkout.');
    }

    $ini = parse_ini_file($path, true, INI_SCANNER_RAW);

    expect($ini)->toBeArray()
        ->and($ini["program:{$program}"]['user'] ?? null)->toBe('www-data');
})->with(['queue', 'schedule']);
