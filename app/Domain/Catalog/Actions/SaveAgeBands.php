<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Enums\AgeBandPricing;
use App\Models\AgeBand;
use App\Models\Product;
use App\Support\Locale\LocaleResolver;
use App\Support\Locale\TranslationValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Save a product's age bands **as a set** (spec CAT-7, CAT-8).
 *
 * ## Why the whole set, and never one band
 *
 * Every CAT-8 rule is set-level. *"Exactly one base band"* cannot be judged from the row
 * being saved — a row with `is_base = false` is only wrong if no *other* row
 * has it. *"At least one counted band"* is the same shape.
 *
 * A per-row validator would therefore have to load the siblings anyway, and
 * would still be wrong in the one case that matters: saving two rows in
 * sequence passes through an intermediate state where the set is invalid. That
 * is not hypothetical — it is what a repeater does on every submit, and it is
 * why the replace happens inside one transaction.
 *
 * ## The set is replaced, but a band that stays keeps its row
 *
 * Same reasoning as #23's refund ladder: a band the operator deleted should be
 * gone, and merging leaves an invisible category still resolving passengers.
 * `code` is stable and unique per product, so a re-import updates rather than
 * duplicates — which is what makes an importer run idempotent.
 *
 * **Matched by `code` and updated in place, not deleted and recreated**
 * (2026-09-17). Recreating gave every band a new id on every save of the trip
 * form, and `rate_plan_prices.age_band_id` cascades on delete: pressing «Save»
 * on a trip silently wiped every price it had, and nulled the band on its
 * booked guests. Only a band whose code left the set is deleted now, and its
 * prices go with it, which is right.
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
        $bands = self::withCodes($bands);

        $this->validateSet($bands);

        return DB::transaction(function () use ($product, $bands): array {
            // Soft-deleted rows too: the unique index counts them, so a code
            // coming back must reuse its row rather than collide with it.
            $existing = AgeBand::query()
                ->withTrashed()
                ->where('product_id', $product->getKey())
                ->get()
                ->keyBy('code');

            $saved = [];
            $kept = [];

            foreach (array_values($bands) as $index => $band) {
                $code = (string) $band['code'];
                $kept[] = $code;

                $attributes = [
                    'product_id' => $product->getKey(),
                    'code' => $code,
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
                    // Absent means "decide from the ages" (AgeBand::isDocumentFree).
                    'no_document' => array_key_exists('no_document', $band) && $band['no_document'] !== null
                        ? (bool) $band['no_document']
                        : null,
                    'sort_order' => (int) ($band['sort_order'] ?? $index),
                ];

                /** @var AgeBand|null $row */
                $row = $existing->get($code);

                if ($row === null) {
                    $saved[] = AgeBand::query()->create($attributes);

                    continue;
                }

                if ($row->trashed()) {
                    $row->restore();
                }

                $row->fill($attributes)->save();
                $saved[] = $row;
            }

            // The set the operator submitted *is* the set: a band they removed
            // must stop resolving passengers, and its prices go with it.
            $existing
                ->reject(static fn (AgeBand $row): bool => in_array($row->code, $kept, true))
                ->each(static fn (AgeBand $row): ?bool => $row->forceDelete());

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

        // No overlap rule any more (product owner, 2026-09-17): a category such
        // as ΑΜΕΑ covers the same ages as «Ενήλικας». Nothing resolves a band
        // from an age alone: the guest picks the category, and the date of
        // birth is checked against that category's own range.
        $errors = array_merge(
            $errors,
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
     * A code for every band that arrived without one.
     *
     * The trip form no longer asks for «Κωδικός» (2026-09-17): the operator
     * names the category and the code is made from the name, English first
     * because it transliterates cleanly. A band that already has a code keeps
     * it, which is what keeps its prices attached across saves.
     *
     * @param  list<array<string, mixed>>  $bands
     * @return list<array<string, mixed>>
     */
    public static function withCodes(array $bands): array
    {
        $taken = array_filter(array_map(static fn (array $b): string => trim((string) ($b['code'] ?? '')), $bands));

        foreach ($bands as $i => $band) {
            if (trim((string) ($band['code'] ?? '')) !== '') {
                continue;
            }

            $label = is_array($band['label'] ?? null) ? $band['label'] : [];
            $stem = Str::slug((string) ($label['en'] ?? ''), '_')
                ?: Str::slug((string) ($label['el'] ?? ''), '_', 'el')
                ?: 'band';
            $stem = substr($stem, 0, 28);

            $code = $stem;

            for ($n = 2; in_array($code, $taken, true); $n++) {
                $code = $stem . '_' . $n;
            }

            $bands[$i]['code'] = $code;
            $taken[] = $code;
        }

        return array_values($bands);
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
