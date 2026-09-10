<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\Support\SetupChecklist;
use App\Filament\App\Pages\Setup;
use App\Filament\App\Widgets\SetupProgress;
use App\Models\User;
use App\Support\Authorization\Capability;
use Closure;
use Filament\Pages\Dashboard;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Opens the setup guide the first time an owner arrives, and never again (#51, SAA-10).
 *
 * ## The requirement pulls in two directions and both halves matter
 *
 * #51: *"When an owner signs in for the first time, then the wizard opens"*.
 * SAA-10: the operator *"sees a persistent checklist rather than a blocking
 * modal"*, and it must not nag *"on every page load"*. A redirect that fires
 * whenever setup is incomplete satisfies the first and violates the second — it
 * is a modal built out of HTTP, and worse than one, because there is no way to
 * dismiss it.
 *
 * So the redirect is bound to the **session**, not to the state of the account.
 * Once per sign-in the guide is offered; from then on it is
 * {@see SetupProgress} on the dashboard and a badge
 * in the navigation, both of which are ignorable. An operator who signs in,
 * looks at the guide, decides they have a boat to get out of the water and
 * clicks away is not asked again until they come back tomorrow.
 *
 * ## Why a session key rather than a column
 *
 * A column would answer "has this operator ever been offered the guide", and
 * that is the wrong question: the answer is yes for ever after the first
 * afternoon, and an operator who opened an account in March and came back in
 * June to actually use it would never see it. A session says "not again this
 * visit", which is what "do not nag" means.
 *
 * ## Only from the panel's front door
 *
 * Signing in lands on the dashboard, so that is where "signs in for the first
 * time" happens and the only path this fires on. The first version fired on the
 * first page view of the session whatever it was, and that is a different and
 * worse thing: an operator following a bookmark to their bookings, or a link in
 * a notification email, is not arriving — they are going somewhere, and taking
 * them to a setup guide instead loses what they came for.
 *
 * It also does not touch anything that is not a plain page view. A Livewire
 * update is every button in the panel and redirecting one breaks the action
 * rather than moving the operator; a non-GET is a form being submitted
 * somewhere.
 */
class OfferSetupOnce
{
    /** The session key. Namespaced, because a session is shared with everything. */
    public const OFFERED = 'kaiki.setup.offered';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldOffer($request)) {
            return $next($request);
        }

        // Written before the redirect rather than after it, so a browser that
        // never follows the Location header has still used up the one offer.
        $request->session()->put(self::OFFERED, true);

        return redirect()->to(Setup::getUrl());
    }

    private function shouldOffer(Request $request): bool
    {
        if (! $request->isMethod('GET') || $request->ajax() || $request->expectsJson()) {
            return false;
        }

        // `POST /livewire/update` is caught by the method check; this catches
        // the initial component request and anything else Livewire owns.
        if ($request->hasHeader('X-Livewire')) {
            return false;
        }

        if (! $request->hasSession() || $request->session()->get(self::OFFERED) === true) {
            return false;
        }

        $user = $request->user();

        if (! $user instanceof User || ! $user->hasCapability(Capability::ManageBilling)) {
            return false;
        }

        if (! SetupChecklist::applies()) {
            return false;
        }

        return $this->isPanelHome($request);
    }

    /**
     * Is this the dashboard, which is where signing in lands?
     *
     * Compared as a path rather than by route name: the panel home is
     * `Dashboard`'s route, and a tenant on a custom domain reaches it at the
     * same path on a different host.
     */
    private function isPanelHome(Request $request): bool
    {
        $home = parse_url(Dashboard::getUrl(), PHP_URL_PATH);

        return is_string($home) && $this->path($request) === rtrim($home, '/');
    }

    private function path(Request $request): string
    {
        return rtrim($request->getPathInfo(), '/');
    }
}
