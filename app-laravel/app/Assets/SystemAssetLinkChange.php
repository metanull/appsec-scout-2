<?php

namespace App\Assets;

use App\Models\SoftwareSystem;

/** The outcome of linking a software system to an asset (or unlinking it), with the rows re-stamped. */
final readonly class SystemAssetLinkChange
{
    /** @param  array{local_findings: int, software_components: int}  $restamped */
    public function __construct(
        public SoftwareSystem $system,
        public ?int $previousAssetId,
        public array $restamped,
    ) {}
}
