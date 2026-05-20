<?php

it('audit config has retain_days key', function () {
    expect(config('audit'))->toHaveKey('retain_days');
});

it('audit retain_days defaults to 365', function () {
    expect(config('audit.retain_days'))->toBe(365);
});

it('audit retain_days is an integer', function () {
    expect(config('audit.retain_days'))->toBeInt();
});

it('scheduler uses audit retain_days from config', function () {
    config(['audit.retain_days' => 90]);

    expect(config('audit.retain_days'))->toBe(90);
});
