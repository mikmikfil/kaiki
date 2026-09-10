<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * How much of the hosted site an operator wants (ADR-0029, amending TEN-1).
 *
 * ## The middle state is the whole reason this is not a boolean
 *
 * It was `tenants.hosted_page_enabled`, and false meant every hosted URL 404s —
 * the marketing home page, the trip pages, the search page and the legal pages
 * together, because the flag is read in `HostedSlugResolver`, which runs
 * **before routing** and therefore cannot know which page was asked for.
 *
 * The product owner named the missing state twice, unprompted: an operator with
 * a website they like does not want Kaiki publishing a second, competing home
 * page under their name. They want the **bookable** half — a page per trip to
 * link to from their own site, a search page, and the widget. Forced to choose
 * between all of it and none of it, that operator picks none, and loses the trip
 * pages, which are the part Kaiki is actually good at.
 *
 * ## Two questions, and they are asked in different places
 *
 * - {@see self::servesAnything()} — the **coarse** one. Asked by the resolver,
 *   which runs before routing and knows only the slug, and by
 *   `HostedEmbedToken`, whose token must stay valid while any page is live.
 * - {@see self::servesHomePage()} — the **fine** one, asked by the controller
 *   that serves the home page and by nobody else.
 *
 * Keeping them separate is what makes the middle state possible at all.
 *
 * ## Not gated by plan, deliberately
 *
 * ADR-0029 decides the *shape* of the switch. Whether the full site is a paid
 * tier is a pricing question the product owner has deferred — so this ships
 * ungated, and a `Plan` predicate can be added in front of it later without any
 * of this moving.
 */
enum HostedSiteMode: string
{
    use HasTranslatedLabel;

    /** No hosted pages at all. The operator uses the widget on their own site, or nothing. */
    case Off = 'off';

    /** Trip pages, search and the legal pages — but no marketing home page. */
    case BookingsOnly = 'bookings_only';

    /** Everything, including the home page the operator composes from blocks. */
    case Full = 'full';

    /**
     * Is there a site at all?
     *
     * The question `HostedSlugResolver` asks, because it runs before routing:
     * there is no route yet, so there is no page to ask about. Answering `true`
     * here only means the request may proceed to a route — which may still 404
     * on the finer question.
     */
    public function servesAnything(): bool
    {
        return $this !== self::Off;
    }

    /**
     * The values that serve something, for a query that cannot call a method.
     *
     * `HostedSlugResolver` filters in SQL — it runs before routing and before a
     * model exists — so it needs the set as strings. Derived from
     * {@see self::servesAnything()} rather than written out, because a fourth
     * state added to this enum must not silently fail to appear in the one
     * place that decides whether a request reaches a route at all.
     *
     * @return list<string>
     */
    public static function servesAnythingValues(): array
    {
        return array_values(array_map(
            static fn (self $mode): string => $mode->value,
            array_filter(self::cases(), static fn (self $mode): bool => $mode->servesAnything()),
        ));
    }

    /** Is the operator's own marketing home page one of the pages we serve? */
    public function servesHomePage(): bool
    {
        return $this === self::Full;
    }

    /**
     * Do we serve a page a guest can book a trip from?
     *
     * The same answer as {@see self::servesAnything()} today, and it is a
     * separate method because the two are separate facts: `booking_url` in the
     * API points at a **product** page, while the panel's "view your page" link
     * points at the **home** page, and under three states those diverge. A
     * single `enabledFor()` served both and would now be wrong for one of them.
     */
    public function servesBookingPages(): bool
    {
        return $this !== self::Off;
    }
}
