<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Pricing\Support\SeasonCandidateResolver;
use App\Models\Season;
use App\Models\SeasonDateRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Save a season with its date ranges (spec CAT-9, PRC-3, PRC-4).
 *
 * Two rules, both set-level, both impossible to express as a column
 * constraint — and the second is the one PRC-4 turns from a read-time
 * tie-break into a save-time refusal.
 *
 * ## Ranges within one season may not overlap
 *
 * `docs/data-model.md` §2.3. Two ranges of the same season covering the same
 * day is not ambiguous — the season either applies or it does not — but it is
 * always a mistake, usually a repeater row duplicated and half-edited, and
 * leaving it in makes the "narrowest matching range" tie-break answer with a
 * width the operator never intended.
 *
 * ## Two seasons cannot tie on a date
 *
 * PRC-4, and the interesting one. Ranges may overlap **across** seasons — that
 * is the entire point of `priority`, and it is how "August" sits inside
 * "Summer". What is refused is an overlap where the priorities are **equal**,
 * because then nothing in the operator's own configuration decides which price
 * applies.
 *
 * The engine still orders deterministically (see
 * {@see SeasonCandidateResolver}), but that is
 * defence in depth for rows that arrived another way. Relying on it as the
 * primary mechanism would mean an operator's prices are decided by a row id
 * they never see, and the first they hear of it is a guest quoting a different
 * figure.
 */
final class SaveSeason
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array{starts_on: string, ends_on: string}>|null  $ranges
     *                                                                        null leaves the ranges alone; an empty array clears them
     *
     * @throws ValidationException
     */
    public function __invoke(Season $season, array $attributes, ?array $ranges = null): Season
    {
        $proposed = $ranges ?? $this->existingRanges($season);

        $this->guardSelfOverlap($proposed);

        return DB::transaction(function () use ($season, $attributes, $ranges, $proposed): Season {
            $season->fill($attributes);
            $season->save();

            if ($ranges !== null) {
                $season->dateRanges()->delete();

                foreach ($ranges as $range) {
                    SeasonDateRange::query()->create([
                        'season_id' => $season->getKey(),
                        'starts_on' => $range['starts_on'],
                        'ends_on' => $range['ends_on'],
                    ]);
                }
            }

            // Checked **after** the write and inside the transaction, so the
            // comparison is against the state that would actually exist. Doing
            // it before would miss a tie the save itself creates, and doing it
            // outside would race another operator's save.
            $this->guardPriorityTies($season->refresh()->load('dateRanges'), $proposed);

            return $season;
        });
    }

    /**
     * @param  list<array{starts_on: string, ends_on: string}>  $ranges
     *
     * @throws ValidationException
     */
    private function guardSelfOverlap(array $ranges): void
    {
        $errors = [];
        $count = count($ranges);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($this->rangesOverlap($ranges[$i], $ranges[$j])) {
                    $errors[] = trans('pricing.season.validation.self_overlap', [
                        'first' => $this->describeRange($ranges[$i]),
                        'second' => $this->describeRange($ranges[$j]),
                    ]);
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['date_ranges' => $errors]);
        }
    }

    /**
     * PRC-4: refuse a save that would leave two seasons tied on any date.
     *
     * Only the **boundary dates** of the proposed ranges are tested, not every
     * day in them. That is not an approximation: two ranges overlap if and only
     * if one contains the other's start or end, so checking four dates per pair
     * is exhaustive — and checking every day would mean 365 queries for a
     * year-long season.
     *
     * @param  list<array{starts_on: string, ends_on: string}>  $ranges
     *
     * @throws ValidationException
     */
    private function guardPriorityTies(Season $season, array $ranges): void
    {
        $errors = [];

        foreach ($this->boundaryDates($ranges) as $date) {
            $candidates = SeasonCandidateResolver::candidates($date);
            $tied = SeasonCandidateResolver::tiedWith($candidates, $season);

            foreach ($tied as $other) {
                $errors[trans('pricing.season.validation.priority_tie', [
                    'other' => $other->name,
                    'date' => $date->toDateString(),
                    'priority' => $season->priority,
                ])] = true;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['priority' => array_keys($errors)]);
        }
    }

    /**
     * @param  list<array{starts_on: string, ends_on: string}>  $ranges
     * @return list<Carbon>
     */
    private function boundaryDates(array $ranges): array
    {
        $dates = [];

        foreach ($ranges as $range) {
            $dates[$range['starts_on']] = Carbon::parse($range['starts_on']);
            $dates[$range['ends_on']] = Carbon::parse($range['ends_on']);
        }

        return array_values($dates);
    }

    /**
     * @param  array{starts_on: string, ends_on: string}  $a
     * @param  array{starts_on: string, ends_on: string}  $b
     */
    private function rangesOverlap(array $a, array $b): bool
    {
        return $a['starts_on'] <= $b['ends_on'] && $b['starts_on'] <= $a['ends_on'];
    }

    /**
     * @return list<array{starts_on: string, ends_on: string}>
     */
    private function existingRanges(Season $season): array
    {
        if (! $season->exists) {
            return [];
        }

        return $season->dateRanges()
            ->get()
            ->map(fn (SeasonDateRange $range): array => [
                'starts_on' => $range->starts_on->toDateString(),
                'ends_on' => $range->ends_on->toDateString(),
            ])
            ->all();
    }

    /** @param array{starts_on: string, ends_on: string} $range */
    private function describeRange(array $range): string
    {
        return "{$range['starts_on']} – {$range['ends_on']}";
    }
}
