<?php

declare(strict_types=1);

namespace App\Filament\App\Resources\ProductResource\Pages;

use App\Domain\Catalog\Actions\SaveAgeBands;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Enums\BookingMode;
use App\Enums\ProductStatus;
use App\Models\AgeBand;
use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Hands the form to the two Actions, in the one order that works.
 *
 * **Product first, then bands.** A band needs a `product_id`, so a new product
 * has to exist before its bands can; and the CAT-15 checklist asks whether the
 * product has bands, which means the product's own save cannot be the last
 * word. So the product is saved, the bands are saved, and then — only if the
 * operator asked for `active` — the product is saved again so the checklist
 * runs against the set that now exists.
 *
 * Without that second pass, publishing a brand-new product with its bands
 * filled in would be refused for having no bands, on the same submit that
 * created them.
 *
 * The bands go to {@see SaveAgeBands} as a whole set, because every CAT-8 rule
 * is set-level and cannot be judged one row at a time.
 */
trait ConsumesAgeBands
{
    /** @param array<string, mixed> $data */
    protected function saveProductWithBands(Model $record, array $data): Product
    {
        /** @var list<array<string, mixed>> $bands */
        $bands = $data['age_bands'] ?? [];
        unset($data['age_bands']);

        $wantsActive = ($data['status'] ?? null) === ProductStatus::Active->value;

        // Saved as a draft first when the operator asked to publish, so the
        // checklist is judged after the bands land rather than before.
        $firstPass = $data;

        if ($wantsActive) {
            $firstPass['status'] = ProductStatus::Draft->value;
        }

        /** @var Product $record */
        $product = app(SaveProduct::class)($record, $firstPass);

        if ($product->mode === BookingMode::PerSeat) {
            try {
                app(SaveAgeBands::class)($product, $this->normaliseBands($bands));
            } catch (ValidationException $exception) {
                throw $this->attachToForm($exception);
            }
        }

        if (! $wantsActive) {
            return $product;
        }

        try {
            return app(SaveProduct::class)($product->refresh(), ['status' => $data['status']]);
        } catch (ValidationException $exception) {
            throw $this->attachToForm($exception);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $bands
     * @return list<array<string, mixed>>
     */
    private function normaliseBands(array $bands): array
    {
        $normalised = [];

        foreach (array_values($bands) as $index => $band) {
            $band['sort_order'] = $index;

            foreach (['max_age', 'price_multiplier_bp'] as $key) {
                if (($band[$key] ?? null) === '') {
                    $band[$key] = null;
                }
            }

            $normalised[] = $band;
        }

        return $normalised;
    }

    /**
     * Fill the repeater from the product's own bands.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function fillAgeBands(array $data, Product $record): array
    {
        $data['age_bands'] = $record->ageBands()
            ->get()
            ->map(static fn (AgeBand $band): array => [
                'code' => $band->code,
                'label' => $band->getTranslations('label'),
                'min_age' => $band->min_age,
                'max_age' => $band->max_age,
                'counts_toward_capacity' => $band->counts_toward_capacity,
                'pricing_mode' => $band->pricing_mode->value,
                'price_multiplier_bp' => $band->price_multiplier_bp,
                'is_base' => $band->is_base,
                'requires_adult' => $band->requires_adult,
            ])
            ->values()
            ->all();

        return $data;
    }

    /**
     * Re-key an Action's errors onto the form's fields.
     *
     * The Actions report on `status` and `bands`, which is what the API and the
     * importer will see. Livewire looks for `data.status`, and without the
     * prefix the operator gets a message with no field attached to it.
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
}
