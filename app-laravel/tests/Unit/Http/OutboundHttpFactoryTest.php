<?php

use App\Http\OutboundHttpFactory;
use GuzzleHttp\Client;

it('creates a Guzzle client with no proxy when env is empty', function () {
    config(['proxy.http_proxy' => null, 'proxy.https_proxy' => null, 'proxy.no_proxy' => null, 'proxy.verify' => true]);

    $client = OutboundHttpFactory::create();

    expect($client)->toBeInstanceOf(Client::class);
});

it('includes proxy when HTTP_PROXY is set', function () {
    config(['proxy.http_proxy' => 'http://proxy.example.com:3128', 'proxy.https_proxy' => null, 'proxy.no_proxy' => null, 'proxy.verify' => true]);

    $reflection = new ReflectionClass(OutboundHttpFactory::class);
    $method = $reflection->getMethod('proxyOptions');
    $options = $method->invoke(null);

    expect($options)->toHaveKey('proxy')
        ->and($options['proxy']['http'])->toBe('http://proxy.example.com:3128');
});

it('parses NO_PROXY comma list into array', function () {
    config(['proxy.http_proxy' => 'http://proxy.example.com:3128', 'proxy.https_proxy' => null, 'proxy.no_proxy' => 'localhost, .internal.corp, 127.0.0.1', 'proxy.verify' => true]);

    $reflection = new ReflectionClass(OutboundHttpFactory::class);
    $method = $reflection->getMethod('parseNoProxy');
    $result = $method->invoke(null, 'localhost, .internal.corp, 127.0.0.1');

    expect($result)->toBe(['localhost', '.internal.corp', '127.0.0.1']);
});

it('returns null when NO_PROXY is empty', function () {
    $reflection = new ReflectionClass(OutboundHttpFactory::class);
    $method = $reflection->getMethod('parseNoProxy');

    expect($method->invoke(null, null))->toBeNull()
        ->and($method->invoke(null, ''))->toBeNull();
});

it('accepts custom defaults merged over proxy options', function () {
    config(['proxy.http_proxy' => null, 'proxy.https_proxy' => null, 'proxy.no_proxy' => null, 'proxy.verify' => true]);

    $client = OutboundHttpFactory::create(['timeout' => 30]);

    expect($client)->toBeInstanceOf(Client::class);
});
