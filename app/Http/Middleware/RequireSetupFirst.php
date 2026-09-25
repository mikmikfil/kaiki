<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\Support\SetupChecklist;
use App\Filament\App\Pages\Setup;
use App\Models\User;
use App\Support\Authorization\Capability;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A brand-new operator meets the setup guide, and only the setup guide.
 *
 * ## This replaced an offer, and the reversal is the point
 *
 * It was `OfferSetupOnce`: the guide opened once per sign-in, from the
 * dashboard only, and was ignorable everywhere else — written against SAA-10's
 * *"a persistent checklist rather than a blocking modal"*, with the reasoning
 * that a redirect on every page load *"is a modal built out of HTTP, and worse
 * than one, because there is no way to dismiss it"*.
 *
 * The product owner reversed it on 2026-09-22: *"the first time configurator
 * should open fullscreen and not have access to panel before setting this
 * up"*. The old objection was right about one thing and the fix is exactly
 * that thing — **there is a way out, and it is on the screen**: «Θα το κάνω
 * αργότερα» hands the panel over and leaves the guide in the menu, «Δεν το
 * χρειάζομαι» retires it altogether. Once either is pressed this middleware
 * never fires again for that operator, because it asks
 * {@see SetupChecklist::blocksPanel()}, which reads both.
 *
 * So the gate is the first afternoon, not a state of the account.
 *
 * ## What it deliberately does not touch
 *
 * **Anything that is not a page view.** A Livewire update is every button in
 * the panel, including the two buttons that end the gate; redirecting one
 * breaks the action rather than moving the operator. Non-GET is a form being
 * submitted. Both pass through untouched.
 *
 * **The guide itself**, or the operator arrives at a redirect loop.
 *
 * **Signing out.** An operator who wants to leave must always be able to leave,
 * and a gate that holds somebody inside their own session is a support call.
 *
 * **Anyone without the billing capability.** TEN-8: crew and managers never see
 * the guide, so they must never be held by it either — a skipper opening the
 * boarding screen on a quay is not the person who fills in an ΑΦΜ.
 */
class RequireSetupFirst
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldHold($request)) {
            return $next($request);
        }

        return redirect()->to(Setup::getUrl());
    }

    private function shouldHold(Request $request): bool
    {
        if (! $request->isMethod('GET') || $request->ajax() || $request->expectsJson()) {
            return false;
        }

        // The initial Livewire component request and anything else Livewire owns.
        if ($request->hasHeader('X-Livewire')) {
            return false;
        }

        $user = $request->user();

        if (! $user instanceof User || ! $user->hasCapability(Capability::ManageBilling)) {
            return false;
        }

        if (! SetupChecklist::blocksPanel()) {
            return false;
        }

        return ! $this->isWayOut($request);
    }

    /**
     * The guide itself and the way out of the session.
     *
     * Compared as paths rather than by route name, the way the panel home was:
     * a tenant on a custom domain reaches the same paths on another host.
     */
    private function isWayOut(Request $request): bool
    {
        $path = $this->path($request);

        $allowed = [
            Setup::getUrl(),
            // Nothing else since 2026-09-25: the home page, the last step the
            // guide answered on another screen, moved to the dashboard's first
            // steps, and every question left is answered on the guide itself.
            filament()->getPanel('app')->getLogoutUrl(),
        ];

        foreach ($allowed as $url) {
            $candidate = parse_url($url, PHP_URL_PATH);

            if (is_string($candidate) && $path === rtrim($candidate, '/')) {
                return true;
            }
        }

        return false;
    }

    private function path(Request $request): string
    {
        return rtrim($request->getPathInfo(), '/');
    }
}
