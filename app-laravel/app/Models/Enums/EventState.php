<?php

namespace App\Models\Enums;

enum EventState: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';
}
