<?php

namespace App\Triage;

class CodesearchClientFactory
{
    public function make(string $organization, string $pat): CodesearchClient
    {
        return new CodesearchClient($organization, $pat);
    }
}
