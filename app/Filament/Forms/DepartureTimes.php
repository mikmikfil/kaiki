<?php

declare(strict_types=1);

namespace App\Filament\Forms;

use Closure;
use Filament\Forms\Components\Field;

/**
 * Departure times as a row of chips: «09:00 ×  11:30 ×  + Ώρα» (#11, 24/9).
 *
 * Mike, 23/9: *«οι ώρες αναχώρησης είναι λίγο πεταμένες ως design»*. They were
 * a repeater of time pickers, a box and a bin per time, so three times took
 * three boxes for fifteen characters. Approved mockup:
 * `docs/mockups/departure-times.html`.
 *
 * ## The state is a plain list of «HH:MM»
 *
 * That is what the repeater's `simple()` handed both writers once dehydrated
 * — `ScheduleRulesRelationManager::createForEachTime()` and
 * `CreateProduct`'s schedule loop — so neither changed. A draft saved before
 * this field, or a test written for the repeater, still holds the repeater's
 * raw shape (`[uuid => ['time' => '09:00']]`); {@see self::normalise()} reads
 * both, on the way in and on the way out.
 *
 * Typed «930», «9» or «09:30» in the browser; the script there and
 * {@see self::parse()} here agree on what each means, and the server check is
 * the one that counts.
 */
class DepartureTimes extends Field
{
    protected string $view = 'filament.forms.departure-times';

    /** The sibling CheckboxList whose ticks the «N αναχωρήσεις» line counts. */
    protected string $daysField = 'days';

    protected function setUp(): void
    {
        parent::setUp();

        $this->default([]);

        $this->afterStateHydrated(static function (DepartureTimes $component, mixed $state): void {
            $component->state(self::normalise($state));
        });

        $this->dehydrateStateUsing(static fn (mixed $state): array => self::normalise($state));

        $this->rule(static fn (): Closure => static function (string $attribute, mixed $value, Closure $fail): void {
            $seen = [];

            foreach (self::items($value) as $raw) {
                $time = self::parse($raw);

                if ($time === null) {
                    $fail(__('availability.schedule_rule.form.start_times.invalid'));

                    return;
                }

                if (isset($seen[$time])) {
                    $fail(__('availability.schedule_rule.form.start_times.duplicate', ['time' => $time]));

                    return;
                }

                $seen[$time] = true;
            }
        });
    }

    public function daysField(string $name): static
    {
        $this->daysField = $name;

        return $this;
    }

    /** Where the ticked days live in Livewire, for the script's count. */
    public function getDaysStatePath(): string
    {
        return $this->getContainer()->getStatePath() . '.' . $this->daysField;
    }

    /** @return list<string> sorted, distinct «HH:MM», anything unreadable dropped */
    public static function normalise(mixed $state): array
    {
        $times = array_values(array_unique(array_filter(array_map(self::parse(...), self::items($state)))));
        sort($times);

        return $times;
    }

    /**
     * «930» → 09:30, «9» → 09:00, «09:30» or «09:30:00» → 09:30, and the
     * picker's «2026-09-22 09:30:00» too. Null for anything that is not a time.
     */
    public static function parse(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        if (preg_match('/(?:^|\s)(\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $match) === 1) {
            [$h, $m] = [(int) $match[1], (int) $match[2]];
        } elseif (preg_match('/^\d{1,4}$/', $value) === 1) {
            $digits = strlen($value) <= 2 ? str_pad($value, 2, '0', STR_PAD_LEFT) . '00' : str_pad($value, 4, '0', STR_PAD_LEFT);
            [$h, $m] = [(int) substr($digits, 0, 2), (int) substr($digits, 2)];
        } else {
            return null;
        }

        return $h <= 23 && $m <= 59 ? sprintf('%02d:%02d', $h, $m) : null;
    }

    /** @return list<mixed> the raw times, from either shape */
    private static function items(mixed $state): array
    {
        return array_values(array_map(
            static fn (mixed $item): mixed => is_array($item) ? ($item['time'] ?? null) : $item,
            is_array($state) ? $state : [],
        ));
    }
}
