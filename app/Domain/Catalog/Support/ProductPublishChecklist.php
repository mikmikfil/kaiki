<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Support;

use App\Enums\BookingMode;
use App\Models\Product;
use App\Models\RatePlan;
use App\Support\Locale\LocaleResolver;

/**
 * What a product is still missing before it may be published (spec CAT-15).
 *
 * ## Keys, not sentences
 *
 * Every method here returns requirement **keys** — `vessel`, `age_bands`,
 * `rate_plan` — and never a rendered string. The panel turns a key into a line
 * of Greek, the API will turn the same key into an error code, and a test
 * asserts on the key rather than on wording that a copy edit would break.
 *
 * ## Read-only, and that is the point
 *
 * CAT-15 is a gate at the moment of publishing and a **checklist the operator
 * watches while building**. The second use is the reason this is a pure reader
 * rather than a method on the Action: the form shows it live, on a product that
 * has not been saved and may never be, and a checker that could write would
 * eventually be given something to write.
 *
 * ## What "a resolvable rate plan" means here
 *
 * Any active plan for the product, seasonal or default. Not "a plan that
 * resolves for tomorrow" — that is #33's question, it depends on a date, and a
 * checklist that changed its answer overnight because a season ended would tell
 * an operator their published trip had become unpublishable. The narrow reading
 * is the honest one: a product with no plan at all cannot be priced under any
 * reading, and that is what CAT-15 is guarding against.
 */
final class ProductPublishChecklist
{
    public const VESSEL = 'vessel';

    public const MEETING_POINT = 'meeting_point';

    public const AGE_BANDS = 'age_bands';

    public const RATE_PLAN = 'rate_plan';

    public const CANCELLATION_POLICY = 'cancellation_policy';

    public const TITLE_LOCALES = 'title_locales';

    /**
     * Every requirement CAT-15 names, in the order the operator meets them.
     *
     * @return list<string>
     */
    public static function requirements(): array
    {
        return [
            self::VESSEL,
            self::MEETING_POINT,
            self::AGE_BANDS,
            self::RATE_PLAN,
            self::CANCELLATION_POLICY,
            self::TITLE_LOCALES,
        ];
    }

    /**
     * The requirements this product does **not** meet.
     *
     * Empty means publishable. Ordered as `requirements()` is, so the checklist
     * an operator reads twice does not reshuffle itself between reads.
     *
     * @return list<string>
     */
    public static function unmet(Product $product): array
    {
        return array_values(array_filter(
            self::requirements(),
            static fn (string $requirement): bool => ! self::satisfies($product, $requirement),
        ));
    }

    public static function isPublishable(Product $product): bool
    {
        return self::unmet($product) === [];
    }

    /** Is this one requirement met? */
    public static function satisfies(Product $product, string $requirement): bool
    {
        return match ($requirement) {
            self::VESSEL => $product->vessel_id !== null,
            self::MEETING_POINT => $product->meeting_point_id !== null,
            self::AGE_BANDS => self::hasAgeBands($product),
            self::RATE_PLAN => self::hasRatePlan($product),
            self::CANCELLATION_POLICY => self::hasCancellationPolicy($product),
            self::TITLE_LOCALES => self::hasTitleInEveryLocale($product),
            default => true,
        };
    }

    /**
     * **`per_seat` only**, as CAT-15 says in its own parenthesis.
     *
     * A whole-boat charter sells the boat, not passenger categories — requiring
     * a band there would make every operator invent an "Adult" band that
     * nothing reads, and an invented row is worse than an absent one because it
     * looks like a decision.
     */
    private static function hasAgeBands(Product $product): bool
    {
        if ($product->mode !== BookingMode::PerSeat) {
            return true;
        }

        return $product->exists && $product->ageBands()->exists();
    }

    private static function hasRatePlan(Product $product): bool
    {
        if (! $product->exists) {
            return false;
        }

        return RatePlan::query()
            ->where('product_id', $product->getKey())
            ->active()
            ->exists();
    }

    /**
     * The product's own policy, or the tenant default that #23 guarantees.
     *
     * Read through the model's own resolution rather than a second `??` here:
     * two answers to "which policy applies" is how a product passes the
     * checklist and then cancels under a different one.
     */
    private static function hasCancellationPolicy(Product $product): bool
    {
        return $product->effectiveCancellationPolicy() !== null;
    }

    /**
     * A title in every **required** locale, from `LocaleResolver::required()`.
     *
     * That list is deliberately not the installed one: EXT-7 promises adding
     * German is a lang-file addition, and checking installed locales here would
     * make shipping those files instantly unpublish every product in the
     * database. Hardcoding `['el', 'en']` would be wrong the other way — the
     * day the required set grows, this check would silently keep passing.
     */
    private static function hasTitleInEveryLocale(Product $product): bool
    {
        foreach (LocaleResolver::required() as $locale) {
            $title = $product->getTranslation('title', $locale, false);

            if (! is_string($title) || trim($title) === '') {
                return false;
            }
        }

        return true;
    }
}
