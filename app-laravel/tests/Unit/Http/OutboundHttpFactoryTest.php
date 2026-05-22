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

it('includes https proxy when HTTPS_PROXY is set', function () {
    config(['proxy.http_proxy' => null, 'proxy.https_proxy' => 'https://proxy.example.com:3129', 'proxy.no_proxy' => null, 'proxy.verify' => true]);

    $reflection = new ReflectionClass(OutboundHttpFactory::class);
    $method = $reflection->getMethod('proxyOptions');
    $options = $method->invoke(null);

    expect($options)->toHaveKey('proxy')
        ->and($options['proxy']['https'])->toBe('https://proxy.example.com:3129');
});

it('includes both http and https proxy when both are set', function () {
    config(['proxy.http_proxy' => 'http://proxy.example.com:3128', 'proxy.https_proxy' => 'https://proxy.example.com:3129', 'proxy.no_proxy' => null, 'proxy.verify' => true]);

    $reflection = new ReflectionClass(OutboundHttpFactory::class);
    $method = $reflection->getMethod('proxyOptions');
    $options = $method->invoke(null);

    expect($options)->toHaveKey('proxy')
        ->and($options['proxy']['http'])->toBe('http://proxy.example.com:3128')
        ->and($options['proxy']['https'])->toBe('https://proxy.example.com:3129');
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

it('includes no_proxy list in proxy options when set', function () {
    config(['proxy.http_proxy' => 'http://proxy.example.com:3128', 'proxy.https_proxy' => null, 'proxy.no_proxy' => 'localhost,127.0.0.1', 'proxy.verify' => true]);

    $reflection = new ReflectionClass(OutboundHttpFactory::class);
    $method = $reflection->getMethod('proxyOptions');
    $options = $method->invoke(null);

    expect($options['proxy'])->toHaveKey('no')
        ->and($options['proxy']['no'])->toContain('localhost', '127.0.0.1');
});

it('omits proxy key entirely when no proxy env vars are set', function () {
    config(['proxy.http_proxy' => null, 'proxy.https_proxy' => null, 'proxy.no_proxy' => null, 'proxy.verify' => true]);

    $reflection = new ReflectionClass(OutboundHttpFactory::class);
    $method = $reflection->getMethod('proxyOptions');
    $options = $method->invoke(null);

    expect($options)->not()->toHaveKey('proxy');
});

it('forwards verify option from config', function () {
    config(['proxy.http_proxy' => null, 'proxy.https_proxy' => null, 'proxy.no_proxy' => null, 'proxy.verify' => '/etc/ssl/certs/ca-certificates.crt']);

    $reflection = new ReflectionClass(OutboundHttpFactory::class);
    $method = $reflection->getMethod('proxyOptions');
    $options = $method->invoke(null);

    expect($options['verify'])->toBe('/etc/ssl/certs/ca-certificates.crt');
});

it('uses default ca discovery when SSL_CERT_FILE is empty', function () {
    config(['proxy.http_proxy' => null, 'proxy.https_proxy' => null, 'proxy.no_proxy' => null, 'proxy.verify' => '']);

    $reflection = new ReflectionClass(OutboundHttpFactory::class);
    $method = $reflection->getMethod('proxyOptions');
    $options = $method->invoke(null);

    expect($options['verify'])->toBeTrue();
});

it('fails fast when SSL_CERT_FILE points to a missing bundle', function () {
    config(['proxy.http_proxy' => null, 'proxy.https_proxy' => null, 'proxy.no_proxy' => null, 'proxy.verify' => '/missing/ca-bundle.crt']);

    $reflection = new ReflectionClass(OutboundHttpFactory::class);
    $method = $reflection->getMethod('proxyOptions');

    expect(fn () => $method->invoke(null))->toThrow(RuntimeException::class, 'SSL CA bundle not found');
});

it('accepts custom defaults merged over proxy options', function () {
    config(['proxy.http_proxy' => null, 'proxy.https_proxy' => null, 'proxy.no_proxy' => null, 'proxy.verify' => true]);

    $client = OutboundHttpFactory::create(['timeout' => 30]);

    expect($client)->toBeInstanceOf(Client::class);
});

it('custom defaults are merged with proxy options when both present', function () {
    config(['proxy.http_proxy' => 'http://proxy.example.com:3128', 'proxy.https_proxy' => null, 'proxy.no_proxy' => null, 'proxy.verify' => true]);

    $reflection = new ReflectionClass(OutboundHttpFactory::class);
    $method = $reflection->getMethod('proxyOptions');
    $proxyOptions = $method->invoke(null);

    $merged = array_merge($proxyOptions, ['timeout' => 30]);

    expect($merged)->toHaveKey('proxy')
        ->and($merged)->toHaveKey('timeout')
        ->and($merged['timeout'])->toBe(30);
});
