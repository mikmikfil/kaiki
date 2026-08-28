<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a tenant-owned model is queried with no tenant resolved.
 *
 * Spec TEN-4: there is no default tenant and no fallback. Returning rows from
 * every tenant would be the worst possible failure mode for this product, so
 * the absence of context is an error rather than a wildcard.
 *
 * If you hit this in legitimate platform code (super-admin, console, a queued
 * job that spans tenants), wrap the call in `Tenancy::withoutTenancy()` — it is
 * deliberately verbose and greppable so cross-tenant access is always visible
 * in review.
 */
final class TenantContextMissingException extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self(
            "No tenant is resolved, so [{$model}] cannot be queried. Every query on a "
            . 'tenant-owned model is scoped to exactly one tenant (spec TEN-4). Resolve a '
            . 'tenant first, or wrap deliberate cross-tenant access in '
            . 'App\Support\Tenancy::withoutTenancy().',
        );
    }
}
