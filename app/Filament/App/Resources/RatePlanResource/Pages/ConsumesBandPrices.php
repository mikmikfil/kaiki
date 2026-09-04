<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\RatePlanResource\Pages;

use App\Domain\Pricing\Actions\SaveRatePlan;
use App\Filament\App\Resources\RatePlanResource;
use App\Models\Product;
use App\Models\RatePlan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Hands the form's state to the domain Action, band prices and all.
 *
 * The repeater is **not** declared with `relationship()`. Its rows are the
 * product's age bands rather than free-form items, and the coverage rule —
 * every fixed band and the base band must have a price — has to be checked
 * against the set the save would produce, in the same transaction. Letting
 * Filament write the rows separately would put the check and the rows in
 * different transactions.
 *
 * Shared by create and edit because both have exactly the same job.
 */
trait ConsumesBandPrices
{
    /** @param array<string, mixed> $data */
    protected function saveRatePlan(Model $record, array $data): RatePlan
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $data['band_prices'] ?? [];
        unset($data['band_prices']);

        $product = Product::query()->findOrFail((int) $data['product_id']);

        $bandPrices = [];

        foreach ($rows as $row) {
            $price = $row['price_cents'] ?? null;

            // A blank row is "no price for this band", not "zero" — the Action
            // decides whether that is allowed, from the band's pricing mode.
            if ($price === null || $price === '') {
                continue;
            }

            $bandPrices[(int) $row['age_band_id']] = (int) $price;
        }

        foreach (['season_id', 'name', 'deposit_percent', 'deposit_fixed_cents', 'max_advance_days', 'min_pax_override'] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        /** @var RatePlan $record */
        try {
            return app(SaveRatePlan::class)($record, $product, $data, $bandPrices);
        } catch (ValidationException $exception) {
            throw $this->attachToForm($exception);
        }
    }

    /**
     * Re-key the Action's errors onto the form's fields.
     *
     * The Action reports on `season_id`, because that is what the column is
     * called everywhere else — the API, the importer and the exception message
     * all agree. Livewire looks for `data.season_id`, and without the prefix
     * the message renders as an unattached banner while the field that caused
     * it stays unmarked: the operator reads "this trip already has a default
     * price" and has to guess which control to change.
     */
    private function attachToForm(ValidationException $exception): ValidationException
    {
        $prefix = $this->getFormStatePath();

        if ($prefix === null || $prefix === '') {
            return $exception;
        }

        $messages = [];

        foreach ($exception->errors() as $key => $bag) {
            $messages["{$prefix}.{$key}"] = $bag;
        }

        return ValidationException::withMessages($messages);
    }

    /**
     * Rebuild the repeater rows from the plan's product when the form loads.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function fillBandPrices(array $data, RatePlan $record): array
    {
        $existing = $record->prices()
            ->pluck('price_cents', 'age_band_id')
            ->all();

        /** @var array<int, int|null> $existing */
        $data['band_prices'] = RatePlanResource::bandPriceRows($record->product_id, $existing);

        return $data;
    }
}
