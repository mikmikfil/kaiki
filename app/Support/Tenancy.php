<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use Closure;

/**
 * Thin wrapper over stancl/tenancy for the two things application code does:
 * run inside one tenant, and — rarely, deliberately — run across all of them.
 */
final class Tenancy
{
    /** True while a tenant is resolved for the current request or job. */
    private static bool $suspended = false;

    public static function current(): ?Tenant
    {
        $tenant = tenant();

        return $tenant instanceof Tenant ? $tenant : null;
    }

    public static function id(): ?int
    {
        $tenant = self::current();

        return $tenant?->getKey();
    }

    public static function check(): bool
    {
        return self::current() !== null;
    }

    /** Run a callback with the tenant scope suspended. */
    public static function withoutTenancy(Closure $callback): mixed
    {
        $previous = self::$suspended;
        self::$suspended = true;

        try {
            return $callback();
        } finally {
            self::$suspended = $previous;
        }
    }

    /**
     * Is the tenant scope currently suspended?
     *
     * Only ever true inside `withoutTenancy()`. There is deliberately no
     * automatic suspension for console commands: the test suite runs in
     * console, so auto-suspending there would switch the guard off in exactly
     * the place it is being tested. Seeders and platform commands opt out
     * explicitly, which is one grep away from an audit.
     */
    public static function suspended(): bool
    {
        return self::$suspended;
    }

    /** Run a callback inside one tenant, restoring the previous context after. */
    public static function forTenant(Tenant $tenant, Closure $callback): mixed
    {
        return $tenant->run($callback);
    }
}
