<?php

declare(strict_types=1);

namespace App\Filament\App\Support;

use App\Domain\Availability\Actions\MoveDeparturesToRuleVessel;
use App\Models\Departure;
use App\Models\Product;
use App\Models\ScheduleRule;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * A schedule's boat changed: move its unsold sailings now, and tell the
 * operator which ones stayed on the old boat (audit 2, ADR-0009).
 *
 * The move itself is {@see MoveDeparturesToRuleVessel}'s, which the generator
 * also runs; it is run here too so the operator reads the answer on the save
 * rather than after a queue. Sailings with bookings never move by themselves,
 * so they are listed, in a notice that stays until it is closed.
 */
final class VesselMoveNotice
{
    private const LISTED = 10;

    /** After a rule is saved; `$vesselBefore` is its boat before, null for a new rule. */
    public static function afterRuleSave(ScheduleRule $rule, ?int $vesselBefore): void
    {
        if ($vesselBefore === null || $vesselBefore === $rule->effectiveVesselId()) {
            return;
        }

        self::run(collect([$rule]));
    }

    /** After a trip is saved: its rules with no boat of their own follow its boat. */
    public static function afterProductSave(Product $product, ?int $vesselBefore): void
    {
        $now = $product->vessel_id === null ? null : (int) $product->vessel_id;

        if ($vesselBefore === null || $now === null || $vesselBefore === $now) {
            return;
        }

        self::run($product->scheduleRules()->whereNull('vessel_id')->get());
    }

    /** @param Collection<int, ScheduleRule> $rules */
    private static function run(Collection $rules): void
    {
        $moved = 0;

        /** @var Collection<int, Departure> $kept */
        $kept = collect();

        foreach ($rules as $rule) {
            $result = app(MoveDeparturesToRuleVessel::class)($rule);
            $moved += $result['moved'];
            $kept = $kept->merge($result['kept']);
        }

        if ($moved > 0) {
            Notification::make()
                ->success()
                ->title(trans_choice('availability.vessel_move.moved', $moved, ['count' => $moved]))
                ->send();
        }

        if ($kept->isEmpty()) {
            return;
        }

        $lines = $kept->sortBy('starts_at_utc')->take(self::LISTED)->map(static fn (Departure $departure): string => (string) __('availability.vessel_move.line', [
            'date' => $departure->local_date->toDateString(),
            'time' => substr((string) $departure->local_time, 0, 5),
            'sold' => (string) $departure->seats_sold,
        ]));

        if ($kept->count() > self::LISTED) {
            $lines->push((string) __('availability.vessel_move.more', ['count' => $kept->count() - self::LISTED]));
        }

        Notification::make()
            ->warning()
            ->title(trans_choice('availability.vessel_move.kept', $kept->count(), ['count' => $kept->count()]))
            ->body(collect([(string) __('availability.vessel_move.help')])
                ->merge($lines)
                ->map(static fn (string $line): string => e($line))
                ->implode('<br>'))
            ->persistent()
            ->send();
    }
}
