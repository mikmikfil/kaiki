<?php

declare(strict_types=1);

namespace App\Domain\Availability\Support;

use App\Domain\Availability\LocalDateTimeResolver;
use App\Enums\AvailabilityRejection;
use App\Models\Product;
use Illuminate\Support\Carbon;

/**
 * The window a private charter is asking for (spec AVL-6, AVL-30, AVL-31).
 *
 * ## Two shapes, one of which the guest controls
 *
 * A fixed-start charter proposes `default_start_time` plus `duration_minutes`,
 * and there is nothing to validate. A `flexible_start` charter lets the guest
 * pick — and AVL-31 bounds that in three ways, each of which exists to stop a
 * different kind of nonsense reaching the operator's calendar:
 *
 * - **A 15-minute grid.** Without it a guest books 09:07, the crew reads 09:07,
 *   and every downstream display has to decide whether to round. The grid is
 *   the smallest unit an operator actually schedules in.
 * - **An operating window**, 06:00–23:00 local by default. A boat that can be
 *   chartered at 03:00 is a boat whose crew finds out at 03:00.
 * - **Whole-hour extensions, up to a maximum.** `extra_hour_price_cents` is
 *   priced per hour, so half an hour has no price; and without a ceiling a
 *   guest can propose a fortnight and block the vessel calendar with one
 *   request.
 *
 * ## It returns a refusal, not an exception
 *
 * An off-grid proposal is an ordinary thing for a widget to send while a guest
 * drags a slider. Throwing would make the common case a control-flow event, and
 * the caller needs the *code* anyway to tell the guest which of the three
 * bounds they crossed.
 */
final class ProposedWindowBuilder
{
    /**
     * @return array{window: Window|null, rejection: AvailabilityRejection|null}
     */
    public static function build(
        Product $product,
        Carbon|string $localDate,
        ?string $startTime = null,
        int $extraHours = 0,
    ): array {
        $timezone = LocalDateTimeResolver::timezone();

        // A proposal only applies to a flexible charter. On a fixed-start one
        // the operator decided the time, and honouring a posted time would let
        // a widget move a departure the operator did not agree to — while
        // *refusing* it would make a widget that always posts a time unusable
        // on half the catalogue.
        $proposal = $product->flexible_start ? $startTime : null;
        $start = $proposal ?? (string) $product->default_start_time;

        if ($start === '') {
            // A charter with no default start and no proposal has no window to
            // test. Reported rather than assumed, because guessing 09:00 here
            // would put a boat on a calendar at a time nobody chose.
            return self::refuse(AvailabilityRejection::NoProposedWindow);
        }

        if ($proposal !== null) {
            $rejection = self::validateProposal($product, $proposal, $extraHours);

            if ($rejection !== null) {
                return self::refuse($rejection);
            }
        }

        $resolved = LocalDateTimeResolver::resolve($localDate, $start, $timezone);

        // ADR-0016's spring-forward gap. A charter at a time that does not
        // exist on that date is not available, and saying so beats inventing an
        // hour.
        if (! $resolved->existent) {
            return self::refuse(AvailabilityRejection::DstNonExistent);
        }

        $startsAt = $resolved->instantOrFail();
        $minutes = (int) $product->duration_minutes + max(0, $extraHours) * 60;

        return [
            'window' => Window::of($startsAt, LocalDateTimeResolver::endsAt($startsAt, $minutes)),
            'rejection' => null,
        ];
    }

    /** AVL-31's three bounds, in the order a guest would hit them. */
    public static function validateProposal(Product $product, string $startTime, int $extraHours): ?AvailabilityRejection
    {
        if (! self::isOnGrid($startTime)) {
            return AvailabilityRejection::OffGrid;
        }

        if (! self::isInsideOperatingWindow($product, $startTime)) {
            return AvailabilityRejection::OutsideOperatingWindow;
        }

        if ($extraHours < 0 || $extraHours > self::maxExtensionHours()) {
            return AvailabilityRejection::ExtensionTooLong;
        }

        return null;
    }

    /** Is this a time an operator would actually schedule? */
    public static function isOnGrid(string $time): bool
    {
        $parts = explode(':', trim($time));

        if (count($parts) < 2) {
            return false;
        }

        $minutes = (int) $parts[1];
        $seconds = isset($parts[2]) ? (int) $parts[2] : 0;

        return $seconds === 0 && $minutes % self::gridMinutes() === 0;
    }

    /**
     * Inside the operating window, and finishing inside it too.
     *
     * The product's own `earliest_start_time` and `latest_start_time` win where
     * set — they are what the operator typed — and the configured default
     * applies otherwise. Checking only the start would let a guest begin at
     * 22:45 and finish at 06:45, which is a night the crew did not agree to.
     */
    public static function isInsideOperatingWindow(Product $product, string $startTime): bool
    {
        $earliest = self::minutesOfDay((string) ($product->earliest_start_time ?? self::defaultEarliest()));
        $latest = self::minutesOfDay((string) ($product->latest_start_time ?? self::defaultLatest()));
        $start = self::minutesOfDay($startTime);

        return $start >= $earliest && $start <= $latest;
    }

    public static function gridMinutes(): int
    {
        return max(1, (int) config('kaiki.availability.grid_minutes', 15));
    }

    public static function maxExtensionHours(): int
    {
        return max(0, (int) config('kaiki.availability.max_extension_hours', 6));
    }

    public static function defaultEarliest(): string
    {
        return (string) config('kaiki.availability.operating_window.earliest', '06:00');
    }

    public static function defaultLatest(): string
    {
        return (string) config('kaiki.availability.operating_window.latest', '23:00');
    }

    /** @return array{window: null, rejection: AvailabilityRejection} */
    private static function refuse(AvailabilityRejection $rejection): array
    {
        return ['window' => null, 'rejection' => $rejection];
    }

    private static function minutesOfDay(string $time): int
    {
        $parts = explode(':', trim($time));

        return ((int) ($parts[0] ?? 0)) * 60 + ((int) ($parts[1] ?? 0));
    }
}
