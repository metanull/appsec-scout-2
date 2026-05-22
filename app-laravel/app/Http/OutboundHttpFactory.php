<?php

namespace App\Http;

use GuzzleHttp\Client;
use RuntimeException;

class OutboundHttpFactory
{
    /** @param array<string, mixed> $defaults */
    public static function create(array $defaults = []): Client
    {
        return new Client(array_merge(self::proxyOptions(), $defaults));
    }

    /** @return array<string, mixed> */
    private static function proxyOptions(): array
    {
        /** @var array{http_proxy: ?string, https_proxy: ?string, no_proxy: ?string, verify: bool|string|null} $proxy */
        $proxy = config('proxy');

        $options = ['verify' => self::verifyOption($proxy['verify'])];

        $proxyMap = array_filter([
            'http' => $proxy['http_proxy'],
            'https' => $proxy['https_proxy'],
            'no' => self::parseNoProxy($proxy['no_proxy']),
        ]);

        if ($proxyMap !== []) {
            $options['proxy'] = $proxyMap;
        }

        return $options;
    }

    private static function verifyOption(bool|string|null $verify): bool|string
    {
        if ($verify === null || $verify === '') {
            return true;
        }

        if (is_string($verify) && ! is_file($verify)) {
            throw new RuntimeException("SSL CA bundle not found at [{$verify}].");
        }

        return $verify;
    }

    /** @return list<string>|null */
    private static function parseNoProxy(?string $noProxy): ?array
    {
        if ($noProxy === null || $noProxy === '') {
            return null;
        }

        return array_values(array_filter(array_map('trim', explode(',', $noProxy))));
    }
}
