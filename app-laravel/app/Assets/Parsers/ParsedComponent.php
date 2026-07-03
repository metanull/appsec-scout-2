<?php

namespace App\Assets\Parsers;

final class ParsedComponent
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $version,
        public readonly ?string $ecosystem,
        public readonly string $purl,
        public readonly ?string $license,
        public readonly array $metadata,
    ) {}
}
