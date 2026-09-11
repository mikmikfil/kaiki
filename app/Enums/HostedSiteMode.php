<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * How much of the hosted site an operator gets (ADR-0029, amended 2026-09-11).
 *
 * ## Two states, and the booking pages are in both
 *
 * It was `tenants.hosted_page_enabled`, a boolean, and then three states with
 * an **off** that 404'd every hosted URL. The product owner retired *off* on
 * 2026-09-11, on the model WebHotelier and FareHarbor both use: every operator
 * always has the bookable pages — a page per trip, the search page and the
 * legal pages — and what varies is whether Kaiki also publishes a marketing
 * home page. An operator with a website of their own links to the trip pages
 * or uses the widget; they are never left with a checkout whose terms have
 * nowhere to be read.
 *
 * ## The platform's choice, not the operator's
 *
 * Set on `/admin` (`EditTenant`), agreed with the operator when they sign up,
 * and audited like a plan change. The operator's own screen shows it and does
 * not change it.
 *
 * ## Not gated by plan, deliberately
 *
 * Whether the full site is a paid tier is a pricing question the product owner
 * has deferred — so this ships ungated, and a `Plan` predicate can be added in
 * front of it later without any of this moving.
 */
enum HostedSiteMode: string
{
    use HasTranslatedLabel;

    /** Trip pages, search and the legal pages — but no marketing home page. */
    case BookingsOnly = 'bookings_only';

    /** Everything, including the home page the operator composes from blocks. */
    case Full = 'full';

    /**
     * Is the operator's own marketing home page one of the pages we serve?
     *
     * The only question left: the booking pages are served in both states, so
     * the one controller that serves the home page asks this and nobody else.
     */
    public function servesHomePage(): bool
    {
        return $this === self::Full;
    }
}
