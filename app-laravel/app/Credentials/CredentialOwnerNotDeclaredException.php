<?php

namespace App\Credentials;

use LogicException;

/**
 * A credential was read through `Vault::get()` without declaring whose credential it is. There
 * is deliberately no "no owner means system" default, so this is always a code defect to fix at
 * the read site, never a configuration problem.
 */
class CredentialOwnerNotDeclaredException extends LogicException
{
    public static function forKey(string $key): self
    {
        return new self(
            "Credential [{$key}] was read without declaring its owner. Run the read inside "
            . 'SystemIntegrationRuntime or OperatorIntegrationRuntime (or Vault::runAsOwner()), '
            . 'or call Vault::getSystem() for an explicit system read.',
        );
    }
}
