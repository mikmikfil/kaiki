<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Data\Availability\DepartureAvailabilityData;
use App\Data\Availability\VesselWindowData;
use App\Domain\Availability\LocalDateTimeResolver;
use App\Enums\AvailabilityDayStatus;
use App\Enums\WindowUnavailableReason;
use App\Models\Product;
use App\Support\Format\MoneyFormatter;
use Illuminate\Support\Carbon;

/**
 * One calendar date, as `docs/api.md`'s `AvailabilityDay` (spec AVL-29,
 * AVL-30, WGT-16).
 *
 * Not a `JsonResource`: there is no model behind a day. It wraps the engine's
 * `AvailabilityDayData` or `VesselWindowData` and produces the contract's
 * shape, which is a different set of fields with a different vocabulary — the
 * DTOs' own `toArray()` serve the panel and the engine's tests and must keep
 * doing so.
 *
 * ## Every requested date is present, including the ones with nothing on them
 *
 * WGT-16. A calendar that omits unavailable dates cannot grey them out, so it
 * renders them as though nothing were known — and a guest reading a blank
 * September concludes the operator has stopped running rather than that the
 * boat is booked. `departures` and `windows` are both always present, one of
 * them empty, so a client never branches on a missing key.
 *
 * ## Only bookable departures reach `departures[]`
 *
 * The contract is explicit: *"Only departures whose vessel window is free,
 * whose status is `scheduled` or `guaranteed`, that are not blocked and that
 * pass lead-time and advance rules appear here."* The refused ones are not
 * discarded silently — they decide the day's `status`, which is where WGT-16's
 * explanation lives. Publishing a rejected departure with its engine reason
 * would leak the operator's calendar in the detail
 * {@see WindowUnavailableReason} exists to prevent.
 */
final class AvailabilityDayResource
{
    /**
     * @param  list<DepartureAvailabilityData>  $departures
     * @return array<string, mixed>
     */
    public static function perSeat(string $localDate, array $departures, Product $product): array
    {
        $bookable = array_values(array_filter(
            $departures,
            static fn (DepartureAvailabilityData $d): bool => $d->available,
        ));

        $status = AvailabilityDayStatus::forDepartures($departures);

        return [
            'local_date' => $localDate,
            'status' => $status->value,
            'from_price_cents' => self::cheapest($bookable),
            'currency' => MoneyFormatter::currency(),
            'departures' => array_map(
                static fn (DepartureAvailabilityData $d): array => self::departure($d, $product),
                $bookable,
            ),
            'windows' => [],
        ];
    }

    /** @return array<string, mixed> */
    public static function perVessel(VesselWindowData $window, Product $product): array
    {
        $status = AvailabilityDayStatus::forWindow($window);

        return [
            'local_date' => $window->localDate,
            'status' => $status->value,
            'from_price_cents' => $window->available ? $window->priceFromCents : null,
            'currency' => MoneyFormatter::currency(),
            'departures' => [],
            // A day with no window at all still carries one entry, so a client
            // can render "not chartered on this date" from the same structure
            // it renders an available day from.
            'windows' => [self::window($window, $product)],
        ];
    }

    /**
     * A `mode: quote` date (`AvailabilityDay`: *"for `mode: quote` both are
     * empty and `status` is `on_request`"*).
     *
     * No price, ever — BKG-24 and WGT-13. A quote product is sold by the
     * operator answering an enquiry, and a number here would be one the
     * operator never agreed to.
     *
     * @return array<string, mixed>
     */
    public static function onRequest(string $localDate): array
    {
        return [
            'local_date' => $localDate,
            'status' => AvailabilityDayStatus::OnRequest->value,
            'from_price_cents' => null,
            'currency' => MoneyFormatter::currency(),
            'departures' => [],
            'windows' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function departure(DepartureAvailabilityData $departure, Product $product): array
    {
        return [
            'uuid' => $departure->uuid,
            'window' => self::localWindow(
                (string) $departure->localDate,
                $departure->localTime,
                $departure->startsAtUtc,
                $departure->endsAtUtc,
                $departure->dstAmbiguous,
            ),
            'check_in_local_time' => self::checkIn($departure, $product),
            // Only `scheduled` and `guaranteed` are bookable, so the flag is
            // the whole of the status here — a cancelled departure was removed
            // by the engine (AVL-28) and never reaches this method.
            'status' => $departure->isGuaranteed ? 'guaranteed' : 'scheduled',
            'capacity' => $departure->capacity,
            'seats_available' => max(0, $departure->seatsRemaining),
            'is_guaranteed' => $departure->isGuaranteed,
            'seats_to_guarantee' => $departure->seatsToGuarantee(),
            'from_price_cents' => $departure->priceFromCents,
            'currency' => MoneyFormatter::currency(),
            'vessel' => $product->vessel !== null
                ? (new VesselSummaryResource($product->vessel))->resolve()
                : null,
        ];
    }

    /** @return array<string, mixed> */
    private static function window(VesselWindowData $window, Product $product): array
    {
        return [
            'window' => $window->startsAtUtc instanceof Carbon
                ? self::localWindow(
                    $window->localDate,
                    (string) $window->startsAtLocal,
                    $window->startsAtUtc,
                    $window->endsAtUtc,
                    false,
                )
                : null,
            'duration_minutes' => $product->duration_minutes,
            'flexible_start' => $product->flexible_start,
            'earliest_start_local_time' => self::wallTime($product->earliest_start_time),
            'latest_start_local_time' => self::wallTime($product->latest_start_time),
            'is_available' => $window->available,
            'unavailable_reason' => WindowUnavailableReason::fromRejection($window->rejection)?->value,
            'from_price_cents' => $window->available ? $window->priceFromCents : null,
            'currency' => MoneyFormatter::currency(),
            'vessel' => $product->vessel !== null
                ? (new VesselSummaryResource($product->vessel))->resolve()
                : null,
        ];
    }

    /**
     * The contract's `LocalWindow` — both halves, so the client never converts
     * a timezone itself.
     *
     * @return array<string, mixed>
     */
    private static function localWindow(
        string $localDate,
        string $localTime,
        ?Carbon $startsAt,
        ?Carbon $endsAt,
        bool $dstAmbiguous,
    ): array {
        return [
            'local_date' => $localDate,
            'local_time' => substr($localTime, 0, 5),
            'starts_at' => $startsAt?->toIso8601ZuluString(),
            'ends_at' => $endsAt?->toIso8601ZuluString(),
            'timezone' => LocalDateTimeResolver::timezone(),
            // ADR-0016: on the October repeat the local time exists twice and
            // the first occurrence was chosen. The flag is what lets a client
            // say so rather than showing a time that is quietly ambiguous.
            'dst_ambiguous' => $dstAmbiguous,
        ];
    }

    /** Departure start minus `check_in_offset_minutes`, in local time. */
    private static function checkIn(DepartureAvailabilityData $departure, Product $product): ?string
    {
        if (! $departure->startsAtUtc instanceof Carbon) {
            return null;
        }

        return $departure->startsAtUtc
            ->copy()
            ->subMinutes($product->check_in_offset_minutes)
            ->setTimezone(LocalDateTimeResolver::timezone())
            ->format('H:i');
    }

    /** @param list<DepartureAvailabilityData> $departures */
    private static function cheapest(array $departures): ?int
    {
        $prices = array_values(array_filter(array_map(
            static fn (DepartureAvailabilityData $d): ?int => $d->priceFromCents,
            $departures,
        ), static fn (?int $p): bool => $p !== null));

        return $prices === [] ? null : min($prices);
    }

    private static function wallTime(?string $time): ?string
    {
        return $time !== null ? substr($time, 0, 5) : null;
    }
}
