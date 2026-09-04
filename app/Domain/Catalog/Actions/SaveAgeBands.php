<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Enums\AgeBandPricing;
use App\Models\AgeBand;
use App\Models\Product;
use App\Support\Locale\LocaleResolver;
use App\Support\Locale\TranslationValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Save a product's age bands **as a set** (spec CAT-7, CAT-8).
 *
 * ## Why the whole set, and never one band
 *
 * Every CAT-8 rule is set-level. *"Bands must not overlap"* needs the others to
 * compare against. *"Exactly one base band"* cannot be judged from the row
 * being saved — a row with `is_base = false` is only wrong if no *other* row
 * has it. *"At least one counted band"* is the same shape.
 *
 * A per-row validator would therefore have to load the siblings anyway, and
 * would still be wrong in the one case that matters: saving two rows in
 * sequence passes through an intermediate state where the set is invalid. That
 * is not hypothetical — it is what a repeater does on every submit, and it is
 * why the replace happens inside one transaction.
 *
 * ## The set is replaced, not merged
 *
 * Same reasoning as #23's refund ladder: a band the operator deleted should be
 * gone, and merging leaves an invisible category still resolving passengers.
 * `code` is stable and unique per product, so a re-import updates rather than
 * duplicates — which is what makes an importer run idempotent.
 *
 * Bands already referenced by a booking are safe because a booking holds the
 * band **snapshot** in `pax_breakdown`, not a live join.
 */
final class SaveAgeBands
{
    /**
     * @param  list<array<string, mixed>>  $bands
     * @return list<AgeBand>
     *
     * @throws ValidationException
     */
    public function __invoke(Product $product, array $bands): array
    {
        $this->validateSet($bands);

        return DB::transaction(function () use ($product, $bands): array {
            // Deleted rather than upserted: the set the operator submitted *is*
            // the set, and a band they removed must stop resolving passengers.
            $product->ageBands()->forceDelete();

            $saved = [];

            foreach (array_values($bands) as $index => $band) {
                $saved[] = AgeBand::query()->create([
                    'product_id' => $product->getKey(),
                    'code' => $band['code'],
                    'label' => $band['label'],
                    'min_age' => (int) ($band['min_age'] ?? 0),
                    'max_age' => isset($band['max_age']) && $band['max_age'] !== ''
                        ? (int) $band['max_age']
                        : null,
                    'counts_toward_capacity' => (bool) ($band['counts_toward_capacity'] ?? true),
                    'pricing_mode' => $band['pricing_mode'] ?? AgeBandPricing::Multiplier->value,
                    'price_multiplier_bp' => $this->multiplierFor($band),
                    'is_base' => (bool) ($band['is_base'] ?? false),
                    'requires_adult' => (bool) ($band['requires_adult'] ?? false),
                    'sort_order' => (int) ($band['sort_order'] ?? $index),
                ]);
            }

            return $saved;
        });
    }

    /**
     * Every CAT-8 rule, reported together.
     *
     * All of them at once rather than the first failure, because an operator
     * setting up bands for the first time typically has two problems and
     * fixing them one round trip at a time is how a form earns its reputation.
     *
     * @param  list<array<string, mixed>>  $bands
     *
     * @throws ValidationException
     */
    public function validateSet(array $bands): void
    {
        $errors = [];

        if ($bands === []) {
            // A product with no bands cannot say who may board or what they
            // pay. Refused rather than defaulted, because inventing an "adult"
            // band would put a price on something nobody chose.
            $errors[] = trans('catalog.age_band.validation.empty');

            throw ValidationException::withMessages(['age_bands' => $errors]);
        }

        $errors = array_merge(
            $errors,
            $this->overlapErrors($bands),
            $this->baseBandErrors($bands),
            $this->countedBandErrors($bands),
            $this->pricingErrors($bands),
            $this->labelErrors($bands),
            $this->codeErrors($bands),
        );

        if ($errors !== []) {
            throw ValidationException::withMessages(['age_bands' => $errors]);
        }
    }

    /**
     * CAT-8: ranges must not overlap.
     *
     * Named pair by pair, because "your bands overlap" on a set of five leaves
     * the operator to find which two.
     *
     * @param  list<array<string, mixed>>  $bands
     * @return list<string>
     */
    private function overlapErrors(array $bands): array
    {
        $errors = [];
        $count = count($bands);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($this->rangesOverlap($bands[$i], $bands[$j])) {
                    $errors[] = trans('catalog.age_band.validation.overlap', [
                        'first' => $this->describe($bands[$i]),
                        'second' => $this->describe($bands[$j]),
                    ]);
                }
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function rangesOverlap(array $a, array $b): bool
    {
        $aMin = (int) ($a['min_age'] ?? 0);
        $bMin = (int) ($b['min_age'] ?? 0);

        // A null upper bound is "no upper bound", so it overlaps anything above
        // its minimum — which is exactly the adult band's relationship with a
        // senior band somebody adds later without thinking.
        $aMax = isset($a['max_age']) && $a['max_age'] !== '' ? (int) $a['max_age'] : PHP_INT_MAX;
        $bMax = isset($b['max_age']) && $b['max_age'] !== '' ? (int) $b['max_age'] : PHP_INT_MAX;

        return $aMin <= $bMax && $bMin <= $aMax;
    }

    /**
     * CAT-8: exactly one base band. Zero and two get **different** messages,
     * because they are different mistakes with different fixes.
     *
     * @param  list<array<string, mixed>>  $bands
     * @return list<string>
     */
    private function baseBandErrors(array $bands): array
    {
        $base = array_values(array_filter($bands, static fn (array $b): bool => (bool) ($b['is_base'] ?? false)));

        if ($base === []) {
            return [trans('catalog.age_band.validation.no_base')];
        }

        if (count($base) > 1) {
            return [trans('catalog.age_band.validation.many_base', [
                'bands' => implode(', ', array_map($this->describe(...), $base)),
            ])];
        }

        return [];
    }

    /**
     * CAT-8: at least one band consumes a seat.
     *
     * A product whose every band is `counts_toward_capacity = false` sells
     * infinite passengers on a finite boat.
     *
     * @param  list<array<string, mixed>>  $bands
     * @return list<string>
     */
    private function countedBandErrors(array $bands): array
    {
        foreach ($bands as $band) {
            if ((bool) ($band['counts_toward_capacity'] ?? true)) {
                return [];
            }
        }

        return [trans('catalog.age_band.validation.none_counted')];
    }

    /**
     * CAT-8: a `multiplier` band needs its multiplier.
     *
     * The passing counterpart is deliberately permissive: a band that does not
     * count toward capacity may still carry a non-zero multiplier (PRC-7).
     * **Not counting toward capacity does not imply free** — an infant may cost
     * €10 and occupy no seat, and refusing that would force operators to lie in
     * one direction or the other.
     *
     * @param  list<array<string, mixed>>  $bands
     * @return list<string>
     */
    private function pricingErrors(array $bands): array
    {
        $errors = [];

        foreach ($bands as $band) {
            $mode = AgeBandPricing::tryFrom((string) ($band['pricing_mode'] ?? AgeBandPricing::Multiplier->value));

            if ($mode === null) {
                $errors[] = trans('catalog.age_band.validation.pricing_mode', ['band' => $this->describe($band)]);

                continue;
            }

            $multiplier = $band['price_multiplier_bp'] ?? null;

            if ($mode->requiresMultiplier() && ($multiplier === null || $multiplier === '')) {
                $errors[] = trans('catalog.age_band.validation.multiplier_required', [
                    'band' => $this->describe($band),
                ]);
            }
        }

        return $errors;
    }

    /**
     * §1.6, checked here because this model has no search observer to do it.
     *
     * A band labelled only in Greek is a blank in the English booking form's
     * passenger picker — and the picker is where a guest chooses what to pay.
     *
     * @param  list<array<string, mixed>>  $bands
     * @return list<string>
     */
    private function labelErrors(array $bands): array
    {
        $errors = [];

        foreach ($bands as $band) {
            $label = $band['label'] ?? [];

            foreach (LocaleResolver::required() as $locale) {
                $value = is_array($label) ? ($label[$locale] ?? null) : null;

                if (TranslationValue::isBlank($value)) {
                    $errors[] = trans('catalog.age_band.validation.label_locale', [
                        'band' => $this->describe($band),
                        'locale' => $locale,
                    ]);
                }
            }
        }

        return $errors;
    }

    /**
     * The unique index would catch this, but as a constraint violation rather
     * than a sentence — and it is the mistake a repeater makes most often,
     * because duplicating a row keeps its code.
     *
     * @param  list<array<string, mixed>>  $bands
     * @return list<string>
     */
    private function codeErrors(array $bands): array
    {
        $codes = array_map(static fn (array $b): string => (string) ($b['code'] ?? ''), $bands);
        $duplicates = array_unique(array_diff_assoc($codes, array_unique($codes)));

        if ($duplicates === []) {
            return [];
        }

        return [trans('catalog.age_band.validation.duplicate_code', [
            'codes' => implode(', ', $duplicates),
        ])];
    }

    /** @param array<string, mixed> $band */
    private function multiplierFor(array $band): ?int
    {
        $mode = AgeBandPricing::tryFrom((string) ($band['pricing_mode'] ?? AgeBandPricing::Multiplier->value));

        // A `fixed` band's multiplier is meaningless, and storing a stale one
        // would resolve if the mode were ever switched back.
        if ($mode?->requiresMultiplier() !== true) {
            return null;
        }

        $value = $band['price_multiplier_bp'] ?? null;

        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * How an operator would recognise the band in a message: its label if it
     * has one, otherwise its code.
     *
     * @param  array<string, mixed>  $band
     */
    private function describe(array $band): string
    {
        $label = $band['label'] ?? null;

        if (is_array($label)) {
            foreach (LocaleResolver::installed() as $locale) {
                if (! TranslationValue::isBlank($label[$locale] ?? null)) {
                    return (string) $label[$locale];
                }
            }
        }

        return (string) ($band['code'] ?? '?');
    }
}
