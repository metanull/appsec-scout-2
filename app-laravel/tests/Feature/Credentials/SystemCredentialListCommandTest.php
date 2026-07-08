<?php

use Illuminate\Support\Facades\Artisan;

it('lists known system credential keys sorted, without values', function () {
    $exitCode = Artisan::call('credentials:system:list');

    expect($exitCode)->toBe(0);

    $keys = array_values(array_filter(explode("\n", Artisan::output())));

    expect($keys)->toContain('azdo.pat')
        ->and($keys)->toBe(collect($keys)->sort()->values()->all());
});
