<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Availability\Support\VesselFeed;
use App\Models\IcalFeed;
use App\Support\Tenancy;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * The unauthenticated vessel calendar feed (spec OPS-13, OPS-14).
 *
 * ## The URL is the credential, and there is no second factor available
 *
 * Google Calendar, Airbnb and Booking.com subscribe by fetching a URL. None of
 * them will send a header, hold a session, or complete an OAuth flow. So the
 * token in the path is the whole of the authentication, and three consequences
 * follow — all of them already in the schema (§2.7):
 *
 * 1. **40 hex characters from `random_bytes`**, not `Str::random`. This is a
 *    credential, and the difference between a CSPRNG and a convenience helper
 *    is the entire security of the URL.
 * 2. **Globally unique**, not unique per tenant, because the path carries no
 *    other identifier — a collision across operators would hand one operator's
 *    calendar to another.
 * 3. **Revocable and rotatable.** `is_active` false is a 404, and rotating
 *    replaces the token in place so the old URL stops working immediately.
 *
 * What the token does *not* do is make the contents safe to leak. It cannot:
 * the link ends up pasted into third-party services and forwarded between
 * colleagues. That is why {@see VesselFeed} publishes only busy periods with a
 * neutral summary, and why this route is the one place in the application where
 * "the credential is in the URL" is not also a reason to relax about what is
 * behind it.
 *
 * ## Tenancy is resolved from the row, not from the request
 *
 * There is no host, no session and no API key to resolve a tenant from — this
 * URL is fetched by a robot on somebody else's infrastructure. So the feed row
 * is read with the scope suspended, and everything after that runs *inside* the
 * tenant the row names, which is what makes the departure and block queries
 * scoped correctly rather than accidentally global.
 *
 * ## 404 for everything, deliberately
 *
 * A revoked feed, an unknown token and a deleted vessel all answer the same
 * way. A 403 on a revoked token confirms the token was once real, which tells a
 * scanner it is worth trying neighbours of it.
 */
final class IcalFeedController extends Controller
{
    public function __invoke(string $token): Response
    {
        // The token is globally unique, so this is deliberately unscoped —
        // there is no tenant context on this request to scope it by.
        $feed = Tenancy::withoutTenancy(
            static fn (): ?IcalFeed => IcalFeed::query()
                ->withoutGlobalScopes()
                ->where('token', $token)
                ->first(),
        );

        abort_unless($feed instanceof IcalFeed && $feed->is_active, 404);

        $tenant = $feed->tenant;

        abort_unless($tenant !== null, 404);

        /** @var string $body */
        $body = Tenancy::forTenant($tenant, static fn (): string => (new VesselFeed($feed))->render());

        // Recorded after the feed is built, so a request that failed to render
        // is not counted as a successful read. `saveQuietly` because this is a
        // read path: an observer firing on every calendar poll — every fifteen
        // minutes, per subscriber, per vessel — is a write amplification nobody
        // asked for.
        $feed->forceFill([
            'last_accessed_at' => Carbon::now(),
            'access_count' => $feed->access_count + 1,
        ])->saveQuietly();

        return response($body, 200, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="' . (new VesselFeed($feed))->filename() . '"',
            // Subscribers poll on their own schedule, typically hourly or
            // slower. Five minutes of shared caching absorbs a burst without
            // ever showing a stale boat for long enough to matter.
            'Cache-Control' => 'public, max-age=300',
            // The feed is a URL somebody pasted into a third party. Nothing
            // here should be interpreted by a browser that follows the link.
            'X-Content-Type-Options' => 'nosniff',
            // Not indexable, ever. The token is the credential and a search
            // engine that crawled one would publish it.
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
