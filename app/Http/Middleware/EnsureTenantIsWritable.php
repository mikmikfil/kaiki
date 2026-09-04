<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\ApiErrorResponse;
use App\Policies\TenantOwnedPolicy;
use App\Support\Tenancy;
use Closure;
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
 *
 * ## This does not, and cannot, protect the panel (#43)
 *
 * Every Filament action is a POST to `/livewire/update`, and Livewire re-runs
 * persistent middleware against a synthesized request that deliberately
 * restores the *original* page-load method — a `GET` for every panel page. The
 * safe-method check below therefore short-circuits and the tenant's state is
 * never consulted.
 *
 * Making this treat a Livewire request as unsafe would block that POST
 * wholesale, and sorting a table, searching, paginating and opening a modal are
 * all the same POST. So the panel's guard lives in
 * {@see TenantOwnedPolicy} instead, where the write is already
 * being authorized.
 *
 * This middleware stays because it is honest everywhere the method is: the API
 * write endpoints, and the non-Livewire POSTs the panel still makes (a file
 * upload goes to `/livewire/upload-file` as a real POST).
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
            return ApiErrorResponse::fromKey('errors.tenant_read_only', 'tenant_read_only', 403);
        }

        abort(403, __('errors.tenant_read_only'));
    }
}
