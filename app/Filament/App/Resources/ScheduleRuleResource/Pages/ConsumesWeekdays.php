<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ScheduleRuleResource\Pages;

use App\Domain\Availability\Support\WeekdayMask;
use App\Domain\Catalog\Actions\SaveScheduleRule;
use App\Models\Product;
use App\Models\ScheduleRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Translates seven checkboxes into the bitmask, and back.
 *
 * The conversion is {@see WeekdayMask}'s, called from here rather than written
 * here: Monday is bit 0 and PHP's own `date('w')` disagrees, and a second
 * conversion is how the panel comes to show a different day from the one the
 * generator produces.
 *
 * Shared by create and edit because both have exactly the same job, and the
 * copy-pasted second version is the one that stops calling the Action.
 */
trait ConsumesWeekdays
{
    /** @param array<string, mixed> $data */
    protected function saveScheduleRule(Model $record, array $data): ScheduleRule
    {
        /** @var array<int, int|string> $days */
        $days = (array) ($data['weekdays'] ?? []);
        unset($data['weekdays']);

        $data['weekday_mask'] = WeekdayMask::fromDays($days);

        foreach (['vessel_id', 'valid_until', 'capacity_override'] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        $product = Product::query()->findOrFail((int) $data['product_id']);

        /** @var ScheduleRule $record */
        try {
            return app(SaveScheduleRule::class)($record, $product, $data);
        } catch (ValidationException $exception) {
            throw $this->attachToForm($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function fillWeekdays(array $data, ScheduleRule $record): array
    {
        $data['weekdays'] = WeekdayMask::toDays($record->weekday_mask);

        return $data;
    }

    /**
     * The Action reports on `weekday_mask`; the form has `weekdays`.
     *
     * Re-keyed so the refusal marks the checkbox list the operator can act on,
     * rather than a field the form does not render.
     */
    private function attachToForm(ValidationException $exception): ValidationException
    {
        $prefix = $this->getFormStatePath();

        if ($prefix === null || $prefix === '') {
            return $exception;
        }

        $messages = [];

        foreach ($exception->errors() as $key => $bag) {
            $field = $key === 'weekday_mask' ? 'weekdays' : $key;
            $messages["{$prefix}.{$field}"] = $bag;
        }

        return ValidationException::withMessages($messages);
    }
}
