<?php

declare(strict_types=1);

namespace App\Filament\App\Support;

use App\Domain\Availability\Support\ScheduleRuleConflictFinder;
use App\Models\Departure;
use App\Models\ScheduleRule;
use Filament\Notifications\Notification;

/**
 * Tell the operator their boat is already out, in one place.
 *
 * Three screens create schedule rules — the trip's «Δρομολόγια» tab, the older
 * standalone screen, and the new-trip guide — and all three should say the same
 * thing in the same words. {@see ScheduleRuleConflictFinder} answers the
 * question; this is only how the answer is put.
 *
 * **Persistent**, because it is a fact the operator has to act on later if at
 * all, and a toast that disappears while they are typing the next rule has told
 * nobody anything.
 */
final class ScheduleConflictNotice
{
    public static function sendFor(ScheduleRule $rule): void
    {
        $conflicts = ScheduleRuleConflictFinder::forRule($rule);

        if ($conflicts->isEmpty()) {
            return;
        }

        /** @var Departure $first */
        $first = $conflicts->first();

        Notification::make()
            ->warning()
            ->title(__('availability.schedule_rule.conflict.title'))
            ->body(__('availability.schedule_rule.conflict.body', [
                'trip' => (string) $first->product->title,
                'date' => $first->local_date->isoFormat('D MMM'),
                'time' => substr((string) $first->local_time, 0, 5),
            ]))
            ->persistent()
            ->send();
    }
}
