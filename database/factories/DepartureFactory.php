<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Availability\LocalDateTimeResolver;
use App\Enums\DepartureStatus;
use App\Models\Departure;
use App\Models\Product;
use App\Models\Vessel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Departure>
 *
 * Every state goes through {@see LocalDateTimeResolver}, because the model
 * refuses a row whose three time columns disagree — a factory that assembled
 * them by hand would be the first thing to trip the guard, and the second thing
 * somebody would "fix" by loosening it.
 */
class DepartureFactory extends Factory
{
    protected $model = Departure::class;

    /**
     * An ordinary summer morning. Fixed dates, never faked: every assertion in
     * the suite is about which side of a boundary an instant falls.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'vessel_id' => Vessel::factory(),
            'schedule_rule_id' => null,
            'capacity' => 12,
            'min_pax' => 4,
            'seats_sold' => 0,
            'seats_held' => 0,
            'status' => DepartureStatus::Scheduled,
            'is_blocked' => false,
            ...$this->timeColumns('2026-07-04', '09:00', 480),
        ];
    }

    /** A departure at a given local date and time, in the tenant timezone. */
    public function at(string $localDate, string $localTime, int $durationMinutes = 480): self
    {
        return $this->state(fn (): array => $this->timeColumns($localDate, $localTime, $durationMinutes));
    }

    public function guaranteed(): self
    {
        return $this->state(fn (): array => ['status' => DepartureStatus::Guaranteed]);
    }

    public function cancelled(): self
    {
        return $this->state(fn (): array => [
            'status' => DepartureStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }

    public function withSeats(int $sold, int $held = 0): self
    {
        return $this->state(fn (): array => ['seats_sold' => $sold, 'seats_held' => $held]);
    }

    /**
     * The three time columns, built together and never separately.
     *
     * Not called `times()`: `Factory::times()` already exists and means
     * something entirely different — how many models to make.
     *
     * @return array<string, mixed>
     */
    private function timeColumns(string $localDate, string $localTime, int $durationMinutes): array
    {
        $timezone = LocalDateTimeResolver::timezone();
        $resolved = LocalDateTimeResolver::resolve($localDate, $localTime, $timezone);
        $starts = $resolved->instantOrFail();

        // The local pair is rendered back from the chosen instant rather than
        // echoed from the input: on the ambiguous October date those are the
        // same string, and on any other date a mismatch would mean the resolver
        // was wrong — which the model would then refuse, loudly.
        return [
            'local_date' => LocalDateTimeResolver::localDate($starts, $timezone),
            'local_time' => LocalDateTimeResolver::localTime($starts, $timezone),
            'starts_at_utc' => $starts,
            'ends_at_utc' => LocalDateTimeResolver::endsAt($starts, $durationMinutes),
            'dst_ambiguous' => $resolved->ambiguous,
        ];
    }
}
