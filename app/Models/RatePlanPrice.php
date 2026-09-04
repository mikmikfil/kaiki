<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Observers\RatePlanPriceObserver;
use Database\Factories\RatePlanPriceFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One age band's price on one rate plan (`docs/data-model.md` §2.3).
 *
 * `per_seat` only, per person, integer cents. No uuid and no soft deletes: it
 * is an internal join with no public identity, and a removed price is removed —
 * a booking already taken carries its own `price_snapshot`.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $rate_plan_id
 * @property int $age_band_id
 * @property int $price_cents per person
 */
#[ObservedBy(RatePlanPriceObserver::class)]
class RatePlanPrice extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<RatePlanPriceFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['price_cents' => 'integer'];
    }

    /** @return BelongsTo<RatePlan, $this> */
    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    /** @return BelongsTo<AgeBand, $this> */
    public function ageBand(): BelongsTo
    {
        return $this->belongsTo(AgeBand::class);
    }
}
