<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Availability\LocalDateTimeResolver;
use App\Domain\Availability\Support\LocalDay;
use App\Enums\BlockReason;
use App\Models\Vessel;
use App\Models\VesselBlock;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VesselBlock>
 *
 * Every window goes through {@see LocalDateTimeResolver}, so a fixture cannot
 * be the one thing in the codebase that invents its own conversion — which is
 * how a test comes to assert the wrong hour and then defends it.
 */
class VesselBlockFactory extends Factory
{
    protected $model = VesselBlock::class;

    /**
     * An afternoon of maintenance. Fixed dates, never faked: every assertion
     * about a block is about which side of a boundary it falls.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vessel_id' => Vessel::factory(),
            'reason' => BlockReason::Maintenance,
            'is_all_day' => false,
            'title' => 'Συντήρηση',
            ...$this->timedWindow('2026-07-04', '12:00', '16:00'),
        ];
    }

    /** A block at a given local date and time range. */
    public function between(string $localDate, string $startTime, string $endTime): self
    {
        return $this->state(fn (): array => $this->timedWindow($localDate, $startTime, $endTime));
    }

    /**
     * A whole-day block — 23, 24 or 25 hours, whichever that day actually is.
     */
    public function allDay(string $from, ?string $to = null): self
    {
        return $this->state(fn (): array => $this->allDayWindow($from, $to ?? $from));
    }

    public function externalIcal(): self
    {
        return $this->state(fn (): array => [
            'reason' => BlockReason::ExternalIcal,
            'external_uid' => 'evt-' . $this->faker->unique()->numberBetween(1, 99999),
            'title' => 'Airbnb',
        ]);
    }

    public function privateBooking(?int $bookingId = null): self
    {
        return $this->state(fn (): array => [
            'reason' => BlockReason::PrivateBooking,
            // No FK by design (§6) — `bookings` does not exist until M2.
            'booking_id' => $bookingId ?? 1,
        ]);
    }

    /** @return array<string, mixed> */
    private function timedWindow(string $localDate, string $startTime, string $endTime): array
    {
        $timezone = LocalDateTimeResolver::timezone();

        $starts = LocalDateTimeResolver::resolve($localDate, $startTime, $timezone)->instantOrFail();
        $ends = LocalDateTimeResolver::resolve($localDate, $endTime, $timezone)->instantOrFail();

        return [
            'starts_at_utc' => $starts,
            'ends_at_utc' => $ends,
            'local_date' => LocalDateTimeResolver::localDate($starts, $timezone),
            'local_end_date' => LocalDateTimeResolver::localDate($ends, $timezone),
            'is_all_day' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function allDayWindow(string $from, string $to): array
    {
        $timezone = LocalDateTimeResolver::timezone();

        $first = LocalDay::of($from, $timezone);
        $last = LocalDay::of($to, $timezone);

        return [
            'starts_at_utc' => $first->startUtc,
            'ends_at_utc' => $last->endUtcExclusive,
            'local_date' => $from,
            // Inclusive: the date the operator typed, not the exclusive end.
            'local_end_date' => $to,
            'is_all_day' => true,
        ];
    }
}
