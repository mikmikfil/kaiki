<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Pricing\Actions\SaveRatePlan;
use App\Domain\Pricing\Support\RefundCalculator;
use App\Enums\DepositType;
use App\Models\Concerns\BelongsToTenant;
use App\Observers\RatePlanObserver;
use Database\Factories\RatePlanFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What a product costs for a season (`docs/data-model.md` §2.3, spec CAT-10).
 *
 * **`season_id` null is the product default** — the plan used when no season
 * contains the departure date. Exactly one of those per product, enforced by
 * {@see SaveRatePlan} because the unique index
 * provably cannot: both engines treat NULLs as distinct.
 *
 * No `uuid` (§1.1): a rate plan is an operator-only object edited inside a
 * tenant-scoped resource, and the guest sees a price rather than a plan.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property int|null $season_id null = the product default plan
 * @property string|null $name operator label, not guest-facing
 * @property int|null $vessel_price_cents `per_vessel` only
 * @property int|null $extra_hour_price_cents
 * @property DepositType $deposit_type
 * @property int|null $deposit_percent
 * @property int|null $deposit_fixed_cents
 * @property int $min_lead_time_hours
 * @property int|null $max_advance_days
 * @property int|null $min_pax_override
 * @property bool $is_active
 */
#[ObservedBy(RatePlanObserver::class)]
class RatePlan extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<RatePlanFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'balance_due_days_before_departure' => 'integer',
            'deposit_type' => DepositType::class,
            'vessel_price_cents' => 'integer',
            'extra_hour_price_cents' => 'integer',
            'deposit_percent' => 'integer',
            'deposit_fixed_cents' => 'integer',
            'min_lead_time_hours' => 'integer',
            'max_advance_days' => 'integer',
            'min_pax_override' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Season, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /** @return HasMany<RatePlanPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(RatePlanPrice::class);
    }

    /**
     * @param  Builder<RatePlan>  $query
     * @return Builder<RatePlan>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<RatePlan>  $query
     * @return Builder<RatePlan>
     */
    public function scopeDefaultPlan(Builder $query): Builder
    {
        return $query->whereNull('season_id');
    }

    /** Is this the product default rather than a seasonal plan? */
    public function isDefault(): bool
    {
        return $this->season_id === null;
    }

    /**
     * The deposit due on a total, in cents (PRC-23).
     *
     * Rounded **half up** through the same helper the refund path uses, so a
     * deposit and a refund of that deposit cannot disagree by a cent. Returns
     * the full total for `none`, because "no deposit" means the guest pays
     * everything now rather than nothing.
     */
    public function depositCents(int $totalCents): int
    {
        return match ($this->deposit_type) {
            DepositType::None => max(0, $totalCents),
            DepositType::Percent => RefundCalculator::applyPercent(
                $totalCents,
                $this->deposit_percent ?? 0,
            ),
            // Never more than the total: a €200 flat deposit on a €150 seat is
            // an operator setting rather than a licence to overcharge.
            DepositType::Fixed => min(max(0, $totalCents), $this->deposit_fixed_cents ?? 0),
        };
    }
}
