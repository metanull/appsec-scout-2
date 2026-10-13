<?php

namespace App\Mcp\Support;

use App\Models\ErrorLog;
use App\Models\SoftwareSystem;
use Illuminate\Database\Eloquent\Builder;

/**
 * Narrows Error log rows to a tool's asset / system / container scope. A container covers its own
 * errors and the system-level errors of its system (a failed fetch of that system).
 */
final class ErrorLogScope
{
    /**
     * @param  Builder<ErrorLog>  $query
     * @return Builder<ErrorLog>
     */
    public static function apply(Builder $query, Scope $scope): Builder
    {
        if ($scope->isEmpty()) {
            return $query;
        }

        if ($scope->container !== null) {
            $container = $scope->container;

            return $query->where(fn (Builder $inScope) => $inScope
                ->where('security_container_id', $container->id)
                ->orWhere(fn (Builder $systemLevel) => $systemLevel
                    ->where('software_system_id', $container->software_system_id)
                    ->whereNull('security_container_id')));
        }

        return $query->whereIn('software_system_id', $scope->systems()->map(fn (SoftwareSystem $system): int => $system->id)->all());
    }
}
