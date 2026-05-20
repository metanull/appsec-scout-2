<?php

namespace App\Models\Enums;

enum EventType: string
{
    case Vulnerability = 'vulnerability';
    case Secret = 'secret';
    case Dependency = 'dependency';
    case License = 'license';
    case Misconfiguration = 'misconfiguration';
    case CodeQuality = 'code_quality';
    case Iac = 'iac';
    case Posture = 'posture';
}
