<?php

declare(strict_types=1);

namespace App\Domain\Availability\Actions;

use App\Domain\Availability\LocalDateTimeResolver;
use App\Domain\Availability\Support\LocalDay;
use App\Enums\BlockReason;
use App\Models\Vessel;
use App\Models\VesselBlock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Take a boat out of service for a window (spec AVL-3, AVL-4, AVL-35).
 *
 * ## An all-day block is a real window, computed through the tenant's calendar
 *
 * §2.4 stores 00:00 → 23:59:59 local rather than a null window plus a flag,
 * *"so overlap maths never special-cases"*. The subtlety is that "all day" is
 * **not** 24 hours: on the last Sunday of October in Athens it is 25, and on
 * the last Sunday of March it is 23. So the window is built from
 * {@see LocalDay}, which resolves both boundaries through the one conversion
 * authority.
 *
 * Getting this wrong is a maintenance block that ends an hour before the boat
 * is actually back, on one specific day a year, in the direction that lets a
 * booking through.
 *
 * ## The end is exclusive in maths and inclusive to an operator
 *
 * An operator marking "the 4th to the 6th" means all three days. The stored
 * window therefore runs to the start of the 7th, and `local_end_date` holds the
 * 6th — the number they typed. Storing the 7th would be arithmetically tidy and
 * would read as a mistake on every screen.
 */
final class CreateVesselBlock
{
    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    public function __invoke(Vessel $vessel, array $attributes): VesselBlock
    {
        $reason = $this->reason($attributes);
        $allDay = (bool) ($attributes['is_all_day'] ?? false);

        [$starts, $ends] = $allDay
            ? $this->allDayWindow($attributes)
            : $this->timedWindow($attributes);

        if ($ends->lessThanOrEqualTo($starts)) {
            throw ValidationException::withMessages([
                'ends_at' => [trans('availability.block.validation.inverted_window')],
            ]);
        }

        $timezone = LocalDateTimeResolver::timezone();

        return DB::transaction(fn (): VesselBlock => VesselBlock::query()->create([
            'vessel_id' => $vessel->getKey(),
            'starts_at_utc' => $starts,
            'ends_at_utc' => $ends,
            'local_date' => LocalDateTimeResolver::localDate($starts, $timezone),
            // Inclusive, because it is the date the operator typed. The stored
            // window runs past it to the start of the next day.
            'local_end_date' => $this->inclusiveEndDate($ends, $timezone),
            'is_all_day' => $allDay,
            'reason' => $reason,
            'title' => $attributes['title'] ?? null,
            'notes' => $attributes['notes'] ?? null,
            'booking_id' => $attributes['booking_id'] ?? null,
            'ical_source_id' => $attributes['ical_source_id'] ?? null,
            'external_uid' => $attributes['external_uid'] ?? null,
            'created_by_user_id' => $attributes['created_by_user_id'] ?? null,
        ]));
    }

    /**
     * Every local day in the range, in full (AVL-4).
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: Carbon, 1: Carbon}
     *
     * @throws ValidationException
     */
    private function allDayWindow(array $attributes): array
    {
        $timezone = LocalDateTimeResolver::timezone();

        $from = $this->dateString($attributes, 'local_date');
        $to = $this->dateString($attributes, 'local_end_date') ?? $from;

        if ($from === null || $to === null) {
            throw ValidationException::withMessages([
                'local_date' => [trans('availability.block.validation.dates_required')],
            ]);
        }

        // Both boundaries through `LocalDay`, so a 23- or 25-hour day is
        // covered in full rather than by an assumed 24.
        $first = LocalDay::of($from, $timezone);
        $last = LocalDay::of($to, $timezone);

        return [$first->startUtc, $last->endUtcExclusive];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: Carbon, 1: Carbon}
     *
     * @throws ValidationException
     */
    private function timedWindow(array $attributes): array
    {
        $timezone = LocalDateTimeResolver::timezone();

        $date = $this->dateString($attributes, 'local_date');
        $endDate = $this->dateString($attributes, 'local_end_date') ?? $date;
        $startTime = (string) ($attributes['start_time'] ?? '');
        $endTime = (string) ($attributes['end_time'] ?? '');

        if ($date === null || $startTime === '' || $endTime === '') {
            throw ValidationException::withMessages([
                'start_time' => [trans('availability.block.validation.times_required')],
            ]);
        }

        $start = LocalDateTimeResolver::resolve($date, $startTime, $timezone);
        $end = LocalDateTimeResolver::resolve((string) $endDate, $endTime, $timezone);

        // ADR-0016: a block cannot start or end at a time that does not exist.
        // Unlike a departure there is a sensible alternative — the operator
        // moves it by half an hour — so the refusal names the field.
        if (! $start->existent || ! $end->existent) {
            throw ValidationException::withMessages([
                'start_time' => [trans('availability.block.validation.dst_nonexistent')],
            ]);
        }

        return [$start->instantOrFail(), $end->instantOrFail()];
    }

    /**
     * The last local day the block actually covers.
     *
     * The stored end is exclusive, so midnight belongs to the next day and the
     * inclusive date is one second earlier. An operator who typed "to the 6th"
     * must read "to the 6th" back.
     */
    private function inclusiveEndDate(Carbon $endsAtUtc, string $timezone): string
    {
        return LocalDateTimeResolver::localDate($endsAtUtc->copy()->subSecond(), $timezone);
    }

    /** @param array<string, mixed> $attributes */
    private function reason(array $attributes): BlockReason
    {
        $reason = $attributes['reason'] ?? BlockReason::Manual;

        return $reason instanceof BlockReason
            ? $reason
            : (BlockReason::tryFrom((string) $reason) ?? BlockReason::Manual);
    }

    /** @param array<string, mixed> $attributes */
    private function dateString(array $attributes, string $key): ?string
    {
        $value = $attributes[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof Carbon ? $value->toDateString() : substr((string) $value, 0, 10);
    }
}
