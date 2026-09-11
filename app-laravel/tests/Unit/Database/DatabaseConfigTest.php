<?php

it('resolves DB_SSLROOTCERT from env into pgsql config', function () {
    $value = 'path/to/ca.crt';
    putenv("DB_SSLROOTCERT=$value");

    $config = require config_path('database.php');

    expect($config['connections']['pgsql']['sslrootcert'])->toBe($value);

    putenv('DB_SSLROOTCERT');
});

it('resolves DB_SSLCERT from env into pgsql config', function () {
    $value = 'path/to/cert.crt';
    putenv("DB_SSLCERT=$value");

    $config = require config_path('database.php');

    expect($config['connections']['pgsql']['sslcert'])->toBe($value);

    putenv('DB_SSLCERT');
});

it('resolves DB_SSLKEY from env into pgsql config', function () {
    $value = 'path/to/key.key';
    putenv("DB_SSLKEY=$value");

    $config = require config_path('database.php');

    expect($config['connections']['pgsql']['sslkey'])->toBe($value);

    putenv('DB_SSLKEY');
});

it('omits sslrootcert from pgsql config when unset', function () {
    // Reset the connection config to simulate unset env vars
    $config = config('database.connections.pgsql');

    // Verify that when the env var is unset, the key is not in the config
    // (because array_filter drops null/false values)
    expect($config)->not->toHaveKey('sslrootcert');
});

it('omits sslcert from pgsql config when unset', function () {
    $config = config('database.connections.pgsql');

    expect($config)->not->toHaveKey('sslcert');
});

it('omits sslkey from pgsql config when unset', function () {
    $config = config('database.connections.pgsql');

    expect($config)->not->toHaveKey('sslkey');
});
