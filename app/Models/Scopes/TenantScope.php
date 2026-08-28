<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Exceptions\TenantContextMissingException;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Appends `where <table>.tenant_id = ?` to every query on a tenant-owned model.
 *
 * The column is qualified because these models are joined constantly (bookings
 * to departures to products) and an unqualified `tenant_id` is ambiguous the
 * moment a join appears.
 *
 * With no tenant resolved this **throws** rather than returning every tenant's
 * rows (spec TEN-4). The only exceptions are code inside
 * `Tenancy::withoutTenancy()` and console commands, which legitimately operate
 * across tenants.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (Tenancy::suspended()) {
            return;
        }

        $tenantId = Tenancy::id();

        if ($tenantId === null) {
            throw TenantContextMissingException::forModel($model::class);
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
