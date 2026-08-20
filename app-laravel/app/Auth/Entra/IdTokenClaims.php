<?php

namespace App\Auth\Entra;

use RuntimeException;

/**
 * Identity claims extracted from an Entra ID id_token. The token is obtained
 * over the TLS back-channel during the authorization-code exchange, so its
 * signature does not need to be re-verified before reading claims (OIDC Core
 * 3.1.3.7). App Roles arrive here as the `roles` claim — Microsoft Graph /me
 * does not return them.
 */
final class IdTokenClaims
{
    /** @param list<string> $roles */
    private function __construct(
        public readonly string $objectId,
        public readonly array $roles,
    ) {}

    public static function fromJwt(string $idToken): self
    {
        $segments = explode('.', $idToken);

        if (count($segments) !== 3) {
            throw new RuntimeException('Malformed Entra id_token: expected three JWT segments.');
        }

        $encoded = strtr($segments[1], '-_', '+/');
        $payload = base64_decode(str_pad($encoded, (int) ceil(strlen($encoded) / 4) * 4, '='), true);

        if ($payload === false) {
            throw new RuntimeException('Malformed Entra id_token: payload is not valid base64url.');
        }

        $claims = json_decode($payload, true);

        if (! is_array($claims)) {
            throw new RuntimeException('Malformed Entra id_token: payload is not a JSON object.');
        }

        $objectId = $claims['oid'] ?? null;

        if (! is_string($objectId) || $objectId === '') {
            throw new RuntimeException('Entra id_token is missing the oid claim.');
        }

        $roles = array_values(array_filter(
            is_array($claims['roles'] ?? null) ? $claims['roles'] : [],
            fn (mixed $role): bool => is_string($role),
        ));

        return new self($objectId, $roles);
    }
}
