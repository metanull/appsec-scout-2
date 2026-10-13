<?php

namespace App\Database;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Case-insensitive substring search over several columns, the same on every supported engine.
 * MySQL's default collation and SQLite's LIKE already ignore case for ASCII, but PostgreSQL's
 * LIKE does not, so it uses ILIKE there; JSON columns are cast to text first (see jsonAsText).
 * '!' is the escape character because it has the same literal syntax on all three engines
 * (MySQL rejects ESCAPE '\', PostgreSQL rejects ESCAPE '\\').
 */
final class TextSearch
{
    /**
     * Narrow $query to rows where any of $columns contains $term (ignoring case); a blank term
     * leaves the query unchanged.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<literal-string>  $columns
     * @param  list<literal-string>  $jsonColumns  columns among $columns stored as JSON
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, array $columns, ?string $term, array $jsonColumns = []): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $driver = $query->getModel()->getConnection()->getDriverName();
        $operator = $driver === 'pgsql' ? 'ILIKE' : 'LIKE';
        $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';

        return $query->where(function (Builder $nested) use ($columns, $jsonColumns, $driver, $operator, $pattern): void {
            foreach ($columns as $column) {
                $expression = in_array($column, $jsonColumns, true) ? self::jsonAsText($column, $driver) : $column;
                $nested->orWhereRaw($expression . ' ' . $operator . " ? ESCAPE '!'", [$pattern]);
            }
        });
    }

    /**
     * A JSON column as plain text: PostgreSQL has no LIKE for json at all, and MySQL compares
     * JSON with a binary (case-sensitive) collation unless it is cast to a character string.
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    private static function jsonAsText(string $column, string $driver): string
    {
        return match ($driver) {
            'pgsql' => $column . '::text',
            'mysql', 'mariadb' => 'CAST(' . $column . ' AS CHAR)',
            default => $column,
        };
    }
}
