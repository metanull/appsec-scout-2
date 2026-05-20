<?php

namespace App\Sources\AzDo;

final class AzDoRepository
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $webUrl = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            name: (string) $data['name'],
            webUrl: isset($data['webUrl']) ? (string) $data['webUrl'] : null,
        );
    }
}
