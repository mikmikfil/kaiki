<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExtraPricing;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasKaikiTranslations;
use App\Models\Concerns\HasTranslatableSearch;
use App\Models\Concerns\HasUuid;
use App\Models\Contracts\TranslatableSearchable;
use Database\Factories\ExtraFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An add-on sold alongside a trip (`docs/data-model.md` §2.3, spec CAT-12).
 *
 * **Scoping is the pivot, not a column.** Tenant-wide with no pivot rows means
 * every product; add pivot rows and it means those products only. One
 * mechanism, and it is what lets a shared "transfer from your hotel" cost more
 * on the full-day trip without becoming two extras an operator has to keep in
 * step.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $name translatable
 * @property string|null $description translatable
 * @property ExtraPricing $pricing_type
 * @property int|null $price_cents null for `on_request`
 * @property int|null $vat_rate_id overrides the product's rate for this line
 * @property int|null $max_qty null = unlimited
 * @property bool $is_tenant_wide
 * @property bool $is_required
 * @property bool $counts_toward_capacity reserved; always false in MVP
 * @property bool $prices_all_pax PRC-9: multiply by total persons rather than counted pax
 * @property bool $is_active
 * @property string|null $image_path
 * @property int $sort_order
 */
class Extra extends Model implements TranslatableSearchable
{
    use BelongsToTenant;

    /** @use HasFactory<ExtraFactory> */
    use HasFactory;

    use HasKaikiTranslations;
    use HasTranslatableSearch;
    use HasUuid;
    use SoftDeletes;

    protected $guarded = [];

    /** @var list<string> */
    public array $translatable = ['name', 'description'];

    /** @var list<string> */
    protected array $translatableSearch = ['name', 'description'];

    /** @var list<string> */
    protected array $translatableSort = ['name'];

    /**
     * The name only. A guest picks an extra by its name in a checkout list, so
     * a blank in one language is a blank next to a price.
     *
     * @var list<string>
     */
    protected array $requiredTranslations = ['name'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pricing_type' => ExtraPricing::class,
            'price_cents' => 'integer',
            'max_qty' => 'integer',
            'is_tenant_wide' => 'boolean',
            'is_required' => 'boolean',
            'counts_toward_capacity' => 'boolean',
            'prices_all_pax' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<VatRate, $this> */
    public function vatRate(): BelongsTo
    {
        return $this->belongsTo(VatRate::class);
    }

    /**
     * The products that name this extra explicitly.
     *
     * The pivot table is named, not derived. Laravel builds a many-to-many
     * table name by sorting the two models alphabetically — `extra_product` —
     * and `docs/data-model.md` §2.3 calls it **`product_extra`**, which reads
     * the way the relationship is actually used: a product offers extras.
     * Leaving it to the convention produces a "no such table" at the first
     * sync, which is exactly how this was found.
     *
     * @return BelongsToMany<Product, $this, ProductExtra, 'pivot'>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_extra')
            ->using(ProductExtra::class)
            ->withPivot(['price_cents_override', 'max_qty_override', 'is_required_override', 'sort_order'])
            ->withTimestamps();
    }

    /**
     * @param  Builder<Extra>  $query
     * @return Builder<Extra>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Does this extra carry a price at all (CAT-12)? */
    public function hasPrice(): bool
    {
        return $this->pricing_type->hasPrice();
    }
}
