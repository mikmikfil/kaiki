<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\AgeBand;
use App\Support\Format\MoneyFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A passenger category for one product (`docs/api.md`, `AgeBand`; spec CAT-7,
 * CAT-8).
 *
 * `code` is the stable machine key and `label` is the translated one. Both are
 * present because they answer different questions: a price snapshot groups by
 * `code` and must survive an operator renaming «Παιδί» to «Παιδικό», while the
 * booking form renders `label`.
 *
 * ## `counts_toward_capacity` is the field that decides a seat
 *
 * CAT-8: an infant on a lap is a person aboard and not a seat sold. The widget
 * needs it to compute the party size it may offer, which is why it is in the
 * payload at all rather than staying an engine detail.
 *
 * ## `from_price_cents` is not emitted, and that is a decision waiting on a human
 *
 * The contract lists it as an **optional** property and `docs/api.md` §9 item 6
 * records it as **OPEN** — "expose advisory `from_price_cents` per age band?" —
 * with the product owner to decide before M3. `CLAUDE.md` makes an undecided
 * question a hard stop, not a judgement call, so the field is omitted; omitting
 * an optional property is contract-legal and adding it later is additive.
 *
 * The risk the decision is about is worth keeping written down: an advisory
 * price on a band is a **second pricing surface**, and only `POST /price-quote`
 * binds. Two numbers that can disagree in front of a guest is a support
 * incident, not a UI nicety.
 *
 * @mixin AgeBand
 */
final class AgeBandResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'code' => $this->code,
            'label' => $this->label,
            'min_age' => $this->min_age,
            'max_age' => $this->max_age,
            'counts_toward_capacity' => $this->counts_toward_capacity,
            'requires_adult' => $this->requires_adult,
            'is_base' => $this->is_base,
            'sort_order' => $this->sort_order,
            'currency' => MoneyFormatter::currency(),
        ];
    }
}
