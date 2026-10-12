<?php

namespace App\Sources\AzDo;

/** Builds an AzDoClient for a given credential; bound in the container so tests can swap it. */
class AzDoClientFactory
{
    public function make(string $organization, string $pat): AzDoClient
    {
        return new AzDoClient($organization, $pat);
    }
}
