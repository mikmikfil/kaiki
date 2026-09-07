<?php

declare(strict_types=1);

namespace App\Domain\Hosted\Support;

use App\Domain\Media\Support\ImagePayload;
use App\Enums\BookingMode;
use App\Models\Departure;
use App\Models\Port;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Format\MoneyFormatter;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * The `Product` and `Event` graph a trip's hosted page carries (HOS-2, FIXED).
 *
 * ## Both types, not one of them
 *
 * A boat trip is a product with a price *and* a series of dated occurrences,
 * and search engines use the two shapes differently: `Product` earns the price
 * and the review stars, `Event` earns the date, the place and the "tickets"
 * treatment. Emitting only `Product` loses every departure; emitting only
 * `Event` loses the price. So this is a `@graph` with one `Product` and one
 * `Event` per upcoming departure, and the `Event`s point back at the `Product`'s
 * `@id` rather than repeating it.
 *
 * ## A quote product has no `offers`, anywhere
 *
 * BKG-24 and brand decision 4 of 2026-09-04 are one rule seen from two sides:
 * a quote-mode trip shows no price to a guest, so it must not put one into a
 * search result either. `Offer` is omitted rather than emitted with a zero or a
 * null price — a `Product` with `price: 0` is a free boat trip as far as Google
 * is concerned, and that is a listing an operator cannot undo.
 *
 * The VAT rate is not in the graph for the same reason it is not on the page:
 * the price is VAT-inclusive (PRC-13), the page says so in words, and the rate
 * belongs on the invoice, which is M6.
 *
 * ## The `Event` times carry the operator's offset, not the server's
 *
 * `starts_at_utc` is an instant; a search engine showing "18:30" needs to know
 * where. Every date here is rendered in the tenant's timezone with its offset
 * (`2026-09-12T18:30:00+03:00`), which is the same instant said in the way the
 * guest will experience it — and the only form that survives a DST change
 * unambiguously.
 *
 * ## Nothing here is asserted by grepping
 *
 * `ProductJsonLdTest` parses the block. A document that does not parse is
 * discarded by Google without a word, so a test matching the string `"Event"`
 * would pass on markup that achieves nothing at all.
 */
final class ProductJsonLd
{
    /**
     * The graph for one product page, or null when there is nothing to say.
     *
     * @param  Collection<int, Departure>  $departures
     */
    public static function json(
        Tenant $tenant,
        Product $product,
        Collection $departures,
        string $locale,
        ?int $fromPriceCents,
    ): ?HtmlString {
        $url = HostedUrl::product($tenant, $product, $locale);
        $productId = $url . '#product';

        $nodes = [self::product($tenant, $product, $url, $productId, $locale, $fromPriceCents)];

        foreach ($departures as $departure) {
            $nodes[] = self::event($tenant, $product, $departure, $url, $productId, $fromPriceCents);
        }

        return JsonLd::encode([
            '@context' => 'https://schema.org',
            '@graph' => $nodes,
        ]);
    }

    /**
     * The trip itself.
     *
     * `description` is the summary rather than the full description: this is a
     * search snippet, and the long text is on the page for the person who
     * clicked. Falls back to the description when an operator has written no
     * summary, because an absent description is a weaker result than a long one.
     *
     * @return array<string, mixed>
     */
    private static function product(
        Tenant $tenant,
        Product $product,
        string $url,
        string $productId,
        string $locale,
        ?int $fromPriceCents,
    ): array {
        $images = array_column(ImagePayload::collection($product->images, $locale), 'url');

        return [
            '@type' => 'Product',
            '@id' => $productId,
            'name' => $product->title,
            'url' => $url,
            ...JsonLd::when('description', BlockText::line($product->summary ?? $product->description, 300)),
            ...($images === [] ? [] : ['image' => $images]),
            'brand' => [
                '@type' => 'Organization',
                'name' => $tenant->name,
                'url' => HostedUrl::operator($tenant, $locale),
            ],
            // The trip's own identity in the operator's catalogue. A `sku` is
            // what a search engine dedupes on when the same trip is syndicated
            // to a WordPress site (WPP-6) and to this page, and the slug is
            // stable per tenant where the uuid is opaque.
            'sku' => $product->slug,
            ...self::offers($product, $url, $fromPriceCents),
        ];
    }

    /**
     * One departure.
     *
     * `eventAttendanceMode` is stated explicitly: without it Google guesses,
     * and since 2020 the guess for an event with no venue has been "online" —
     * a boat trip listed as a webinar.
     *
     * @return array<string, mixed>
     */
    private static function event(
        Tenant $tenant,
        Product $product,
        Departure $departure,
        string $url,
        string $productId,
        ?int $fromPriceCents,
    ): array {
        $timezone = self::timezone($tenant);
        $seats = $departure->seatsAvailable();

        return [
            '@type' => 'Event',
            '@id' => $url . '#departure-' . $departure->uuid,
            'name' => $product->title,
            'url' => $url,
            'startDate' => $departure->starts_at_utc->copy()->setTimezone($timezone)->toIso8601String(),
            'endDate' => $departure->ends_at_utc->copy()->setTimezone($timezone)->toIso8601String(),
            'eventStatus' => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'location' => self::location($tenant, $product),
            'organizer' => [
                '@type' => 'Organization',
                'name' => $tenant->name,
                'url' => HostedUrl::operator($tenant),
            ],
            'isAccessibleForFree' => false,
            // The dated occurrence *of* the product above, rather than a second
            // copy of it. `subjectOf` on the Product would say the same thing in
            // the direction search engines read less reliably.
            'about' => ['@id' => $productId],
            ...self::eventOffers($product, $url, $fromPriceCents, $seats, $departure),
        ];
    }

    /**
     * Where the boat leaves from.
     *
     * The meeting point, or the vessel's home port when the operator has not
     * set one — the same fallback the page itself renders, because a guest and a
     * crawler disagreeing about the pier is the one error here that ends with
     * somebody standing in the wrong harbour.
     *
     * @return array<string, mixed>
     */
    private static function location(Tenant $tenant, Product $product): array
    {
        $port = $product->meetingPoint ?? $product->vessel?->homePort;

        if (! $port instanceof Port) {
            // A `Place` is required, and the operator's own city is the truest
            // thing left to say. Omitting `location` entirely makes the whole
            // `Event` ineligible rather than imprecise.
            return [
                '@type' => 'Place',
                'name' => $tenant->name,
                ...self::address($tenant->city, $tenant->postcode, $tenant->address_line1),
            ];
        }

        return [
            '@type' => 'Place',
            'name' => $port->name,
            ...self::address($tenant->city, $tenant->postcode, $port->address),
            ...($port->lat !== null && $port->lng !== null ? [
                'geo' => [
                    '@type' => 'GeoCoordinates',
                    'latitude' => (float) $port->lat,
                    'longitude' => (float) $port->lng,
                ],
            ] : []),
        ];
    }

    /**
     * A `PostalAddress`, with the country the whole product is written for.
     *
     * @return array<string, mixed>
     */
    private static function address(?string $city, ?string $postcode, ?string $street): array
    {
        return [
            'address' => [
                '@type' => 'PostalAddress',
                'addressCountry' => 'GR',
                ...JsonLd::when('streetAddress', $street),
                ...JsonLd::when('addressLocality', $city),
                ...JsonLd::when('postalCode', $postcode),
            ],
        ];
    }

    /**
     * The `Product`'s offer, or nothing at all.
     *
     * `lowPrice` on an `AggregateOffer` rather than a flat `Offer`, because
     * `price_from_cents` is exactly that — the cheapest age band on the
     * cheapest active rate plan (#33) — and stating it as *the* price would put
     * a number in a search result that a family of four will never be charged.
     *
     * @return array<string, mixed>
     */
    private static function offers(Product $product, string $url, ?int $fromPriceCents): array
    {
        if ($product->mode === BookingMode::Quote || $fromPriceCents === null) {
            return [];
        }

        return [
            'offers' => [
                '@type' => 'AggregateOffer',
                'lowPrice' => self::amount($fromPriceCents),
                'priceCurrency' => MoneyFormatter::currency(),
                'url' => $url,
                'availability' => 'https://schema.org/InStock',
            ],
        ];
    }

    /**
     * A departure's offer, with the availability that departure actually has.
     *
     * `SoldOut` when no seat is left is the one piece of structured data on this
     * page that changes hour to hour, and it is worth emitting: a guest sent
     * from a search result to a full boat is a wasted click for them and a
     * support message for the operator.
     *
     * @return array<string, mixed>
     */
    private static function eventOffers(
        Product $product,
        string $url,
        ?int $fromPriceCents,
        int $seats,
        Departure $departure,
    ): array {
        if ($product->mode === BookingMode::Quote || $fromPriceCents === null) {
            return [];
        }

        return [
            'offers' => [
                '@type' => 'Offer',
                'price' => self::amount($fromPriceCents),
                'priceCurrency' => MoneyFormatter::currency(),
                'url' => $url,
                'availability' => $seats > 0
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/SoldOut',
                // When the offer stops being valid, which for a boat trip is
                // when it sails. An offer with no `validThrough` stays in a
                // search index after the departure has gone.
                'validThrough' => $departure->starts_at_utc->copy()->toIso8601String(),
            ],
        ];
    }

    /**
     * Cents as a decimal string.
     *
     * A string rather than a float: `45.10` in JSON is a binary fraction, and
     * schema.org's own guidance is to send the number as text so the value that
     * arrives is the value that was meant. `MoneyFormatter` is not used here —
     * its output is localised (`45,10 €`), and this field is machine-read.
     */
    private static function amount(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private static function timezone(Tenant $tenant): string
    {
        $timezone = $tenant->timezone;

        return is_string($timezone) && $timezone !== '' ? $timezone : 'Europe/Athens';
    }
}
