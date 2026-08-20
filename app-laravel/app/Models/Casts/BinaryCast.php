<?php

namespace App\Models\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * On the pgsql driver, binary values are hex-encoded (bytea hex input format,
 * "\x...") on write: pdo_pgsql binds parameters as text, and raw binary sent
 * that way is rejected with "invalid input syntax for type bytea". Reads from
 * the database arrive as streams (already raw binary); the get() hex branch
 * only decodes the in-memory representation between set() and a reload.
 *
 * @implements CastsAttributes<mixed, mixed>
 */
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

        if ($this->usesPgsql($model) && str_starts_with($value, '\x')) {
            $hex = substr($value, 2);

            if ($hex === '' || ctype_xdigit($hex)) {
                $decoded = hex2bin($hex);

                if ($decoded !== false) {
                    return $decoded;
                }
            }
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

            return $this->encode($model, $contents);
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf('The [%s] attribute must be set with string data.', $key));
        }

        return $this->encode($model, $value);
    }

    private function encode(Model $model, string $contents): string
    {
        return $this->usesPgsql($model) ? '\x' . bin2hex($contents) : $contents;
    }

    private function usesPgsql(Model $model): bool
    {
        return $model->getConnection()->getDriverName() === 'pgsql';
    }
}
