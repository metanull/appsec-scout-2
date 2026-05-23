<?php

namespace App\Sources\Contracts;

use App\Sources\Dto\EventDto;

interface EnrichesFetchedEvents
{
    public function enrichFetchedEvent(EventDto $event): EventDto;
}
