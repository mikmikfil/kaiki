<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Enums\ExtraPricing;
use App\Models\Extra;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Save an extra and the products it is scoped to (spec CAT-12, PRC-9).
 *
 * ## An `on_request` extra may not carry a price
 *
 * Refused rather than ignored, and that is the whole rule. CAT-12 says these
 * carry no price and never enter a total — so a stored `price_cents` is a
 * number that is invisible in the checkout and present in the column, waiting
 * for the next piece of code that reads the column without checking the type.
 * The refusal names the field, because an operator who typed a price meant
 * something by it and needs telling which of the two to change.
 */
final class SaveExtra
{
    /**
     * @param  array<string, mixed>  $attributes  already validated by the caller
     * @param  array<int, array<string, mixed>>|null  $productOverrides  product id => override values;
     *                                                                   null leaves the scoping alone
     *
     * @throws ValidationException
     */
    public function __invoke(Extra $extra, array $attributes, ?array $productOverrides = null): Extra
    {
        $this->guardOnRequestHasNoPrice($attributes, $extra);

        return DB::transaction(function () use ($extra, $attributes, $productOverrides): Extra {
            $extra->fill($attributes);

            // Belt and braces alongside the refusal above: whatever route the
            // attributes arrived by, an `on_request` extra leaves here with a
            // null price.
            if (! $extra->pricing_type->hasPrice()) {
                $extra->price_cents = null;
            }

            $extra->save();

            if ($productOverrides !== null) {
                $extra->products()->sync($this->normaliseOverrides($productOverrides));
            }

            return $extra->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    private function guardOnRequestHasNoPrice(array $attributes, Extra $extra): void
    {
        $type = $attributes['pricing_type'] ?? $extra->pricing_type;
        $type = $type instanceof ExtraPricing ? $type : ExtraPricing::tryFrom((string) $type);

        if ($type === null || $type->hasPrice()) {
            return;
        }

        $price = $attributes['price_cents'] ?? null;

        if ($price !== null && $price !== '') {
            throw ValidationException::withMessages([
                'price_cents' => [trans('catalog.extra.validation.on_request_has_price')],
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $overrides
     * @return array<int, array<string, mixed>>
     */
    private function normaliseOverrides(array $overrides): array
    {
        $sync = [];

        foreach ($overrides as $productId => $values) {
            $sync[(int) $productId] = [
                'price_cents_override' => $this->nullableInt($values['price_cents_override'] ?? null),
                'max_qty_override' => $this->nullableInt($values['max_qty_override'] ?? null),
                // Left as null when absent, because null is "inherit" rather
                // than "false" — casting an absent value to a boolean here
                // would silently force every product to optional.
                'is_required_override' => array_key_exists('is_required_override', $values)
                    && $values['is_required_override'] !== null
                    && $values['is_required_override'] !== ''
                        ? (bool) $values['is_required_override']
                        : null,
                'sort_order' => (int) ($values['sort_order'] ?? 0),
            ];
        }

        return $sync;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
