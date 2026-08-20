<?php

use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

it('treats a forwarded https request as secure when the proxy is trusted', function () {
    config(['trustedproxy.proxies' => '*']);

    Route::get('/proxy-probe', fn (Request $request) => response()->json(['secure' => $request->isSecure()]));

    $this->get('/proxy-probe', ['X-Forwarded-Proto' => 'https'])
        ->assertOk()
        ->assertJson(['secure' => true]);
});

it('ignores forwarded headers when no proxy is trusted', function () {
    expect(config('trustedproxy.proxies'))->toBeNull();

    Route::get('/proxy-probe', fn (Request $request) => response()->json(['secure' => $request->isSecure()]));

    $this->get('/proxy-probe', ['X-Forwarded-Proto' => 'https'])
        ->assertOk()
        ->assertJson(['secure' => false]);
});

it('trusts only the listed proxy addresses', function () {
    config(['trustedproxy.proxies' => '10.0.0.0/8']);

    Route::get('/proxy-probe', fn (Request $request) => response()->json(['secure' => $request->isSecure()]));

    $this->get('/proxy-probe', ['X-Forwarded-Proto' => 'https'])
        ->assertOk()
        ->assertJson(['secure' => false]);
});

it('forces https on generated urls when app.force_https is enabled', function () {
    config(['app.force_https' => true]);

    (new AppServiceProvider(app()))->boot();

    expect(url('/'))->toStartWith('https://');
});

it('leaves generated urls untouched by default', function () {
    expect(config('app.force_https'))->toBeFalse()
        ->and(url('/'))->toStartWith('http://');
});
