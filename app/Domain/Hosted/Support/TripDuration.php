<?php

declare(strict_types=1);

namespace App\Domain\Hosted\Support;

/**
 * A trip's length the way a guest says it: «4 ώρες», «3 ώρες 30 λεπτά»,
 * «45 λεπτά».
 *
 * «240 λεπτά» made a guest do the division (Mike, 2026-09-16). The widget
 * formats the same way (`formatDuration` in `FourLines.tsx`), so the chip on
 * the page and the line in the booking box agree.
 */
final class TripDuration
{
    public static function format(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        if ($hours === 0) {
            return __('hosted.index.duration', ['minutes' => $minutes]);
        }

        if ($rest === 0) {
            return trans_choice('hosted.index.duration_hours', $hours, ['hours' => $hours]);
        }

        return trans_choice('hosted.index.duration_hours_minutes', $hours, ['hours' => $hours, 'minutes' => $rest]);
    }
}
