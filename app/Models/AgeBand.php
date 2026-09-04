<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Catalog\Actions\SaveAgeBands;
use App\Enums\AgeBandPricing;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasKaikiTranslations;
use App\Models\Concerns\HasUuid;
use App\Observers\AgeBandObserver;
use Database\Factories\AgeBandFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A passenger category on one product (`docs/data-model.md` §2.3, spec CAT-7).
 *
 * **`counts_toward_capacity` is the flag everything downstream turns on.** An
 * infant on a parent's lap consumes no seat, so it does not reduce what the
 * availability engine may sell (AVL-23) — and it is still a person aboard for
 * the legal capacity check, and it is still priced. One boolean holds the
 * difference, and getting it backwards either oversells a departure or
 * undersells a boat.
 *
 * ## Translatable, but not searchable
 *
 * `HasKaikiTranslations` for the I18N-5 fallback and not `HasTranslatableSearch`:
 * nobody searches for an age band, and nothing sorts by its label — bands are
 * ordered by `sort_order`, which is the operator's own sequence. Two companion
 * columns maintained for no reader would be two columns to keep true.
 *
 * The consequence is that §1.6's both-locales rule is not enforced by the
 * observer here, so {@see SaveAgeBands} checks it
 * as part of the set — a band labelled only in Greek is a blank in the English
 * booking form's passenger picker.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $product_id
 * @property string $code
 * @property string $label translatable
 * @property int $min_age
 * @property int|null $max_age
 * @property bool $counts_toward_capacity
 * @property AgeBandPricing $pricing_mode
 * @property int|null $price_multiplier_bp
 * @property bool $is_base
 * @property bool $requires_adult
 * @property int $sort_order
 */
#[ObservedBy(AgeBandObserver::class)]
class AgeBand extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<AgeBandFactory> */
    use HasFactory;

    use HasKaikiTranslations;
    use HasUuid;
    use SoftDeletes;

    protected $guarded = [];

    /** @var list<string> */
    public array $translatable = ['label'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'min_age' => 'integer',
            'max_age' => 'integer',
            'counts_toward_capacity' => 'boolean',
            'pricing_mode' => AgeBandPricing::class,
            'price_multiplier_bp' => 'integer',
            'is_base' => 'boolean',
            'requires_adult' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Does this band cover `$age`?
     *
     * **Both bounds inclusive**, because "0–2" and "3–11" is how an operator
     * writes it and how a parent reads it. A null `max_age` means no upper
     * bound, which is what the adult band almost always is — the alternative is
     * an arbitrary 120 that eventually excludes somebody.
     */
    public function covers(int $age): bool
    {
        if ($age < $this->min_age) {
            return false;
        }

        return $this->max_age === null || $age <= $this->max_age;
    }

    /** Does this band's range overlap another's (CAT-8)? */
    public function overlaps(self $other): bool
    {
        $thisEnd = $this->max_age ?? PHP_INT_MAX;
        $otherEnd = $other->max_age ?? PHP_INT_MAX;

        return $this->min_age <= $otherEnd && $other->min_age <= $thisEnd;
    }

    /**
     * How this band is priced, as a sentence rather than two fields.
     *
     * `multiplier` anchors to the base band so the ladder survives a price
     * change; `fixed` gets its own `rate_plan_prices` row (#21).
     */
    public function usesMultiplier(): bool
    {
        return $this->pricing_mode->requiresMultiplier();
    }

    /** The multiplier as a fraction, for display. Pricing works in basis points. */
    public function multiplierFraction(): ?float
    {
        return $this->price_multiplier_bp === null ? null : $this->price_multiplier_bp / 10000;
    }
}
