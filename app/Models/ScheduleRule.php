<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Availability\Support\ScheduleRuleCapacityResolver;
use App\Domain\Availability\Support\WeekdayMask;
use App\Models\Concerns\BelongsToTenant;
use App\Observers\ScheduleRuleObserver;
use Database\Factories\ScheduleRuleFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What generates departures (`docs/data-model.md` §2.3, spec CAT-14, AVL-52).
 *
 * **No soft deletes and no uuid** (§1.1, §1.3). A rule is an operator-only
 * object with no public identity, and deleting one leaves its departures
 * standing — they may hold bookings, and a cascade here would be a cancelled
 * holiday.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property int|null $vessel_id null = inherit the product's vessel
 * @property int $weekday_mask Monday = bit 0 … Sunday = bit 6
 * @property string $start_time tenant-local
 * @property Carbon $valid_from
 * @property Carbon|null $valid_until null = open-ended
 * @property int|null $capacity_override
 * @property int $generate_days_ahead
 * @property bool $is_active
 * @property Carbon|null $last_generated_on
 */
#[ObservedBy(ScheduleRuleObserver::class)]
class ScheduleRule extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ScheduleRuleFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'weekday_mask' => 'integer',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'capacity_override' => 'integer',
            'generate_days_ahead' => 'integer',
            'is_active' => 'boolean',
            'last_generated_on' => 'date',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Vessel, $this> */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    /**
     * @param  Builder<ScheduleRule>  $query
     * @return Builder<ScheduleRule>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Would this rule produce a departure on `$date`?
     *
     * Three conditions, all local dates: the rule is on, the date is inside the
     * validity window, and the weekday is in the mask. `valid_until` null means
     * open-ended — generation is still bounded by `generate_days_ahead`, which
     * is #27's concern rather than this method's.
     */
    public function coversDate(Carbon $date): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $day = $date->copy()->startOfDay();

        if ($day->lessThan($this->valid_from->copy()->startOfDay())) {
            return false;
        }

        // Inclusive, as §2.3 says: a rule valid until the 15th runs on the 15th.
        if ($this->valid_until !== null && $day->greaterThan($this->valid_until->copy()->startOfDay())) {
            return false;
        }

        return WeekdayMask::covers($this->weekday_mask, $day);
    }

    /**
     * The next local dates this rule would produce, for the panel preview.
     *
     * **Dates, not instants.** The conversion to a UTC departure time is
     * AVL-16's single authority (#26) and does not happen here — the preview
     * answers "which days", which is the question an operator checking a
     * weekday mask is actually asking.
     *
     * @return list<Carbon>
     */
    public function nextDates(Carbon $from, int $limit = 10): array
    {
        if (! $this->is_active) {
            return [];
        }

        $start = $from->copy()->startOfDay()->max($this->valid_from->copy()->startOfDay());

        return WeekdayMask::nextDates(
            $this->weekday_mask,
            $start,
            $limit,
            $this->valid_until?->copy()->startOfDay(),
        );
    }

    /** Seats a departure from this rule would get (AVL-55). */
    public function effectiveCapacity(): int
    {
        return ScheduleRuleCapacityResolver::capacity($this);
    }

    /** The boat it actually sails (§2.3). */
    public function effectiveVesselId(): ?int
    {
        return ScheduleRuleCapacityResolver::vesselId($this);
    }
}
