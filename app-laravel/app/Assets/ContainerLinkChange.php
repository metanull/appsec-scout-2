<?php

namespace App\Assets;

use App\Models\SecurityContainer;

/** The outcome of moving a container between software systems, with the rows that moved along. */
final readonly class ContainerLinkChange
{
    public function __construct(
        public SecurityContainer $container,
        public int $previousSystemId,
        public int $events,
        public int $localFindings,
        public int $softwareComponents,
    ) {}
}
