<?php

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Applies the tenant filter to every business-owned model.
 *
 * Because the filter is a global scope rather than a `where` in each
 * query, forgetting to add it is not a data leak: the default query is
 * already safe. Super Admin work opts in explicitly per query with
 * `withoutGlobalScope(TenantScope::class)`.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(TenantContext::class);
        $businessId = $tenant->resolve();

        if ($businessId === null) {
            return;
        }

        $builder->where($model->qualifyColumn('business_id'), $businessId);
    }
}
