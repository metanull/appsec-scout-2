<?php

namespace App\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** @implements CastsAttributes<mixed, mixed> */
class BinaryCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return $contents === false ? null : $contents;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf('The [%s] attribute must be binary string data.', $key));
        }

        return $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            if ($contents === false) {
                throw new InvalidArgumentException(sprintf('The [%s] resource could not be read.', $key));
            }

            return $contents;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf('The [%s] attribute must be set with string data.', $key));
        }

        return $value;
    }
}
