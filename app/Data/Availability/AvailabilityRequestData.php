<?php

declare(strict_types=1);

namespace App\Data\Availability;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Data;

/**
 * What a guest is asking for (spec AVL-29).
 *
 * ## The 62-day cap is a refusal, not a truncation
 *
 * AVL-29 caps a range at 62 days. Silently trimming an over-long request would
 * hand the widget a response that looks complete and is not — the calendar
 * would render empty days for dates the server simply declined to consider, and
 * the guest would conclude the boat does not sail in September.
 *
 * Sixty-two days is two months either side of any month boundary, which is what
 * a calendar widget actually paginates by.
 */
final class AvailabilityRequestData extends Data
{
    public const MAX_DAYS = 62;

    /**
     * @param  array<string, int>  $paxByCode  age band code => how many
     */
    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly array $paxByCode = [],
    ) {}

    /**
     * @param  array<string, int>  $paxByCode
     *
     * @throws ValidationException
     */
    public static function forRange(Carbon|string $from, Carbon|string $to, array $paxByCode = []): self
    {
        $start = Carbon::parse($from instanceof Carbon ? $from->toDateString() : substr((string) $from, 0, 10));
        $end = Carbon::parse($to instanceof Carbon ? $to->toDateString() : substr((string) $to, 0, 10));

        if ($end->lessThan($start)) {
            throw ValidationException::withMessages([
                'to' => [trans('availability.request.validation.inverted_range')],
            ]);
        }

        // Inclusive of both ends, which is what a guest means by "the 1st to
        // the 3rd" — so the cap is measured the same way.
        if ($start->diffInDays($end) + 1 > self::MAX_DAYS) {
            throw ValidationException::withMessages([
                'to' => [trans('availability.request.validation.range_too_long', [
                    'max' => (string) self::MAX_DAYS,
                ])],
            ]);
        }

        return new self($start, $end, $paxByCode);
    }

    /** @return list<Carbon> every local date in the range, inclusive */
    public function dates(): array
    {
        $dates = [];

        for ($date = $this->from->copy(); $date->lessThanOrEqualTo($this->to); $date->addDay()) {
            $dates[] = $date->copy();
        }

        return $dates;
    }
}
