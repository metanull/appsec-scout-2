<?php

namespace App\Credentials;

use RuntimeException;

/**
 * A stored credential exists but its ciphertext cannot be decrypted with the current
 * APP_KEY — a distinct, loud condition, never to be confused with "not configured".
 */
class CredentialDecryptionException extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self(
            "Stored credential [{$key}] cannot be decrypted — was APP_KEY changed? "
            . 'Restore the original APP_KEY, or store a new value for this credential.',
        );
    }
}
