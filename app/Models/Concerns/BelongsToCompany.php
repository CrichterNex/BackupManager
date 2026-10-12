<?php

namespace App\Models\Concerns;

use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Restricts a model to the current company and stamps company_id on create. This replaces Postgres
 * row-level security, so any tenant data must be read through a model using this trait (or with an
 * explicit company_id filter, as the report queries do).
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query) {
            $id = CompanyContext::id();
            $table = $query->getModel()->getTable();
            // No company set means no rows, never all rows.
            $id === null ? $query->whereRaw('1 = 0') : $query->where("$table.company_id", $id);
        });

        static::creating(function ($model) {
            $current = CompanyContext::id();
            if ($current === null) {
                throw new LogicException('No company context set');
            }
            if ($model->company_id !== null && (int) $model->company_id !== $current) {
                throw new LogicException('Cannot write another company\'s data');
            }
            $model->company_id = $current;
        });
    }
}
