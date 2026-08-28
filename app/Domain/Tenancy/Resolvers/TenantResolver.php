<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Resolvers;

use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * One strategy for turning a request into exactly one tenant (spec TEN-4).
 *
 * Returning `null` means "not my kind of request" — never "no tenant, carry on
 * anyway". The chain decides what happens when every strategy declines, and the
 * answer is 404.
 */
interface TenantResolver
{
    public function resolve(Request $request): ?Tenant;

    /** Short name used in logs and in the resolution test matrix. */
    public function name(): string;
}
