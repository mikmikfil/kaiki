<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses writes for a tenant in read-only mode (spec TEN-9, SAA-7).
 *
 * A lapsed subscription must not destroy the operator's business overnight.
 * Guests keep seeing trips, existing bookings keep working, token pages keep
 * opening — what stops is the operator changing anything. That asymmetry is the
 * whole design: the pressure lands on the person who owes money, not on the
 * tourist holding a ticket.
 *
 * Only unsafe HTTP methods are gated. Reads are never blocked here.
 */
final class EnsureTenantIsWritable
{
    /** Methods that cannot change state, so they are never refused. */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->getMethod(), self::SAFE_METHODS, strict: true)) {
            return $next($request);
        }

        $tenant = Tenancy::current();

        if ($tenant === null || $tenant->allowsWrites()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return new JsonResponse([
                'error' => [
                    'code' => 'tenant_read_only',
                    'message' => __('errors.tenant_read_only', [], 'en'),
                    'message_el' => __('errors.tenant_read_only', [], 'el'),
                ],
            ], 403);
        }

        abort(403, __('errors.tenant_read_only'));
    }
}
