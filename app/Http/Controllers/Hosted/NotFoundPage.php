<?php

declare(strict_types=1);

namespace App\Http\Controllers\Hosted;

use App\Domain\Hosted\Support\HostedHost;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * «Αυτή η σελίδα δεν υπάρχει», on the operator's own page (24/9 list).
 *
 * Laravel's error view rendered before `SetLocale` had run — an unknown slug
 * aborts in `ResolveTenant`, an unmatched URL runs no middleware at all — so a
 * guest on a Greek operator's site met «Not Found» in English, on a grey page
 * with nothing of the operator on it.
 *
 * With the operator known, the 404 is theirs: their brand, their language,
 * and a way back to their trips. Without one there is no brand to borrow, so
 * the plain error page answers, in Greek.
 */
final class NotFoundPage extends HostedController
{
    /** Is this a 404 the guest pages should answer, rather than the panel? */
    public static function applies(Request $request): bool
    {
        if ($request->is('app', 'app/*', 'admin', 'admin/*', 'api/*', 'livewire/*') || $request->expectsJson()) {
            return false;
        }

        return HostedHost::matches($request->getHost()) || Tenancy::current() instanceof Tenant;
    }

    public function respond(Request $request): ?Response
    {
        $tenant = Tenancy::current();

        if (! $tenant instanceof Tenant) {
            app()->setLocale('el');

            return null;
        }

        $locale = $this->resolveLocale($request, $tenant);

        return $this->render($request, $tenant, 'hosted.not-found', static fn (): array => [], $locale)
            ->setStatusCode(Response::HTTP_NOT_FOUND);
    }
}
