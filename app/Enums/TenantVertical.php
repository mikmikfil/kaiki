<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasTranslatedLabel;

/**
 * What trade an operator is in.
 *
 * ## Two cases, and the second one is honest rather than lazy
 *
 * The product owner's plan is that Kaiki will one day sell to something other
 * than boat operators. This column is what makes that visible **from the
 * platform side** — a filter, a count, a merchant list that can be read by
 * segment — and it is cheap: a nullable string with a constant default, which
 * `docs/data-model.md` §6 makes portable and addable to `tenants` at any time.
 *
 * What it is **not** is the second vertical. The whole catalogue is
 * boat-shaped — `vessels`, `ports`, `departures`, seat capacity — and the
 * compliance half is Greek maritime law: the ναυλοσύμφωνο, the coastguard's
 * passenger manifest, the port authority. None of that follows from a column.
 * A second trade is a decision with an ADR behind it, and it names its own case
 * here when it is made.
 *
 * So `Other` exists to tag a pilot customer who is not a boat operator, not to
 * pretend the product already serves them. Inventing four plausible verticals
 * today would be designing somebody else's roadmap in an enum.
 */
enum TenantVertical: string
{
    use HasTranslatedLabel;

    /** Boat trips and charters — everything the product is built around today. */
    case Boats = 'boats';

    /** Anything else. A case of its own arrives with the decision that scopes it. */
    case Other = 'other';
}
