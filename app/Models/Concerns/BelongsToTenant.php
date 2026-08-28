<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Exceptions\TenantContextMissingException;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mandatory on every tenant-owned model (spec TEN-5, ADR-0001).
 *
 * There is no implicit exemption. A model that is genuinely platform-owned —
 * `vat_rates` is the first — must be named in the allow-list in
 * `config/tenancy.php`, so "which tables are not scoped" is one list a reviewer
 * can read, not a property of whichever trait someone forgot to add.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(static function (self $model): void {
            if ($model->tenant_id !== null) {
                return;
            }

            $tenantId = Tenancy::id();

            if ($tenantId === null) {
                throw TenantContextMissingException::forModel($model::class);
            }

            $model->tenant_id = $tenantId;
        });
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
