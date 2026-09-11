<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Booking\Actions\CreateManualBooking;
use App\Domain\Booking\Actions\ImportBooking;
use App\Domain\Booking\Data\BookingDraftData;
use App\Domain\Hosted\Support\AllowedOrigin;
use App\Domain\Pricing\Actions\ComputePrice;
use App\Enums\BookingSource;
use App\Models\AgeBand;
use App\Models\ApiKey;
use App\Models\Departure;
use App\Models\Extra;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * `POST /api/v1/bookings` (`docs/api.md` §5, schema `BookingCreateRequest`).
 *
 * ## No price crosses this boundary
 *
 * PRC-1, and the schema's own first line: *"Contains **no prices**: the server
 * recomputes from the same inputs it would have used for the price quote."*
 * The widget runs on somebody else's page, so a `total_cents` field here would
 * be a number a third party could set. {@see PriceQuoteRequest} refuses one for
 * the same reason and this reuses its `prohibited` machinery rather than
 * inventing a second dialect of the same refusal.
 *
 * ## `source` accepts three values and refuses two
 *
 * > `manual` and `import` are back-office only and are rejected here.
 *
 * Not filtered to a default — **rejected**, with a validation error. A public
 * request claiming to be a manual booking is either a client bug or somebody
 * trying to skip BKG-32's lead-time rules, and silently rewriting it to
 * `widget` would hide both. The two back-office sources have their own Actions
 * ({@see CreateManualBooking} and
 * {@see ImportBooking}) which never come through
 * here.
 *
 * ## `terms_accepted` is validated as `accepted`, and stored as a timestamp
 *
 * GDR-9 wants consent *"with a timestamp and IP"*. The wire format is a boolean
 * because that is what a checkbox produces; what is written is `now()` and the
 * request IP, because a boolean records that somebody ticked a box and a
 * timestamp records when — which is the half that answers a dispute.
 *
 * ## `origin_url` is dropped, never refused, when the key may not send a guest there
 *
 * The page the guest was on, for the «← Επιστροφή στην ιστοσελίδα» link on the
 * checkout and booking pages (product owner, 2026-09-11). It is rendered as an
 * `href` on a page that looks like the operator's, so it is held to the same
 * rule as `return_url` — {@see AllowedOrigin}. A value that fails that rule is
 * stored as null rather than answered with a 422: a link we will not print is
 * not a reason to lose somebody's seats. A value that is not a URL at all is
 * still refused, because that is a client bug and not a policy question.
 */
class BookingCreateRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'product_uuid' => ['required', 'string', 'max:120'],
            'departure_uuid' => ['nullable', 'string', 'max:64'],

            'window' => ['nullable', 'array'],
            'window.local_date' => ['required_with:window', 'date_format:Y-m-d'],
            'window.local_time' => ['nullable', 'date_format:H:i'],
            'window.duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],

            'pax' => ['required', 'array', 'min:1'],
            'pax.*.age_band_uuid' => ['required', 'string', 'max:64'],
            'pax.*.qty' => ['required', 'integer', 'min:0', 'max:500'],

            'extras' => ['sometimes', 'array'],
            'extras.*.extra_uuid' => ['required', 'string', 'max:64'],
            'extras.*.qty' => ['required', 'integer', 'min:1', 'max:500'],

            'voucher_code' => ['nullable', 'string', 'max:24'],

            // Optional since ADR-0030 — a draft is a hold on seats, and the
            // lead guest is typed on the checkout page. Still validated when
            // sent, because an integrator who supplies a name and an email is
            // supplying the real ones and a malformed address should be
            // refused here rather than discovered when the confirmation
            // bounces.
            'guest' => ['sometimes', 'nullable', 'array'],
            'guest.name' => ['nullable', 'string', 'max:120'],
            'guest.email' => ['nullable', 'email', 'max:190'],
            'guest.phone' => ['nullable', 'string', 'max:32'],
            'guest.country' => ['nullable', 'string', 'size:2'],

            'special_requests' => ['nullable', 'string', 'max:2000'],
            'locale' => ['nullable', 'string', 'in:el,en'],
            'price_token' => ['nullable', 'string', 'max:500'],
            // `accepted` when it is sent, and it does not have to be:
            // ADR-0030 moved the consent to the checkout page, where it is
            // recorded beside the payment it authorises. A `false` is still
            // refused rather than ignored — a client that sends the field is
            // making a claim about a box, and an unticked box is not consent.
            'terms_accepted' => ['sometimes', 'accepted'],

            // See the class docblock: rejected rather than filtered.
            'source' => ['nullable', 'string', 'in:widget,hosted,wordpress'],

            // See the class docblock: checked against the key's origins in
            // `originUrl()`, and dropped rather than refused when it fails.
            'origin_url' => ['nullable', 'url', 'max:2000'],

            'utm' => ['sometimes', 'array'],
            'utm.source' => ['nullable', 'string', 'max:120'],
            'utm.medium' => ['nullable', 'string', 'max:120'],
            'utm.campaign' => ['nullable', 'string', 'max:120'],
            'utm.term' => ['nullable', 'string', 'max:120'],
            'utm.content' => ['nullable', 'string', 'max:120'],
        ];
    }

    /** The product, resolved by uuid inside the resolved tenant. */
    public function product(): ?Product
    {
        return Product::query()
            ->with(['ageBands', 'meetingPoint', 'vessel'])
            ->where('uuid', (string) $this->input('product_uuid'))
            ->first();
    }

    public function departure(): ?Departure
    {
        $uuid = $this->input('departure_uuid');

        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        return Departure::query()->where('uuid', $uuid)->first();
    }

    /**
     * Pax as {@see ComputePrice} wants it: band **code** to quantity.
     *
     * The wire carries uuids (CNV-8 keeps integer keys out of every payload)
     * and the engine speaks codes, because a code is what the price snapshot
     * freezes and what survives the band being deleted. The translation happens
     * once, here, rather than in the Action — which would then have to know
     * about a transport.
     *
     * @return array<string, int>
     */
    public function paxByCode(Product $product): array
    {
        $byUuid = $product->ageBands->keyBy('uuid');

        $pax = [];

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->input('pax', []);

        foreach ($rows as $row) {
            $band = $byUuid->get((string) ($row['age_band_uuid'] ?? ''));

            if (! $band instanceof AgeBand) {
                continue;
            }

            $qty = (int) ($row['qty'] ?? 0);

            if ($qty > 0) {
                $pax[$band->code] = ($pax[$band->code] ?? 0) + $qty;
            }
        }

        return $pax;
    }

    /**
     * Extras as the engine wants them: database id to quantity.
     *
     * The one place an integer id is legitimately produced from a payload, and
     * it never travels back — CNV-8 is a rule about what leaves.
     *
     * @return array<int, int>
     */
    public function extraQuantities(): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->input('extras', []);

        $uuids = array_map(static fn (array $row): string => (string) ($row['extra_uuid'] ?? ''), $rows);

        $byUuid = Extra::query()->whereIn('uuid', $uuids)->get()->keyBy('uuid');

        $quantities = [];

        foreach ($rows as $row) {
            $extra = $byUuid->get((string) ($row['extra_uuid'] ?? ''));

            if ($extra instanceof Extra) {
                $quantities[(int) $extra->getKey()] = (int) ($row['qty'] ?? 0);
            }
        }

        return $quantities;
    }

    public function toData(Product $product, ?Departure $departure): BookingDraftData
    {
        /** @var array<string, mixed> $guest */
        $guest = (array) ($this->input('guest') ?? []);

        /** @var array<string, mixed> $window */
        $window = $this->input('window', []);

        $date = $departure instanceof Departure
            ? $departure->local_date->copy()
            : Carbon::parse((string) ($window['local_date'] ?? $this->input('local_date')));

        return new BookingDraftData(
            product: $product,
            date: $date,
            guestName: self::stringOrNull($guest['name'] ?? null),
            guestEmail: self::stringOrNull($guest['email'] ?? null),
            guestPhone: isset($guest['phone']) ? (string) $guest['phone'] : null,
            guestCountry: isset($guest['country']) ? (string) $guest['country'] : null,
            locale: (string) ($this->input('locale') ?? app()->getLocale()),
            source: BookingSource::from((string) ($this->input('source') ?? BookingSource::Widget->value)),
            paxByCode: $this->paxByCode($product),
            extraQuantities: $this->extraQuantities(),
            startTime: isset($window['local_time']) ? (string) $window['local_time'] : null,
            extraHours: 0,
            voucherCode: $this->input('voucher_code') === null ? null : (string) $this->input('voucher_code'),
            specialRequests: $this->input('special_requests') === null ? null : (string) $this->input('special_requests'),
            // GDR-9: the moment, not the tick. See the class docblock. Null
            // when the box was not on this screen at all — the checkout page
            // records its own timestamp when it is (ADR-0030), and a consent
            // stamped for a tick nobody made would be the one kind of evidence
            // worse than none.
            termsAcceptedAt: $this->boolean('terms_accepted') ? Carbon::now() : null,
            ipAddress: $this->ip(),
            userAgent: substr((string) $this->userAgent(), 0, 500),
            utm: $this->utm(),
            isTest: $this->isTestKey(),
            originUrl: $this->originUrl(),
        );
    }

    /**
     * `origin_url`, when the key may send a guest there — otherwise null.
     *
     * Only reached after validation, so anything here is already a URL of at
     * most 2000 characters; the question left is whose.
     */
    public function originUrl(): ?string
    {
        $url = $this->input('origin_url');

        if (! is_string($url) || $url === '') {
            return null;
        }

        $key = $this->attributes->get('api_key');

        return AllowedOrigin::permits($url, $key instanceof ApiKey ? $key : null) ? $url : null;
    }

    /** A present, non-blank string, or null. */
    private static function stringOrNull(mixed $value): ?string
    {
        $string = is_string($value) ? trim($value) : '';

        return $string === '' ? null : $string;
    }

    /** @return array<string, string|null> */
    private function utm(): array
    {
        /** @var array<string, mixed> $utm */
        $utm = $this->input('utm', []);

        $values = [];

        foreach (['source', 'medium', 'campaign', 'term', 'content'] as $field) {
            $values[$field] = isset($utm[$field]) ? (string) $utm[$field] : null;
        }

        return $values;
    }

    /**
     * PAY-11's sandbox flag, taken from the **key** and never from the body.
     *
     * §3.9: bookings made with a `*_test_` key are `is_test` and purged
     * nightly. A body field would let a live key mark a booking as a test and
     * have it vanish overnight, which is a way to lose a real seat.
     */
    private function isTestKey(): bool
    {
        $apiKey = $this->attributes->get('api_key');

        // `?->`, because the key is not always a row. The hosted page
        // authenticates with a **transient** `ApiKey` that is built in memory
        // and never saved, and it carried no environment — so this line was a
        // call on null and every booking started from an operator's own trip
        // page died with a 500. `HostedEmbedToken` now sets one; this stays
        // defensive because the next transient credential will be written by
        // somebody who has not read that file.
        return $apiKey instanceof ApiKey && ($apiKey->environment?->isTest() ?? false);
    }
}
