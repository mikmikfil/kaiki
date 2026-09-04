<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Availability\Support\Window;
use App\Enums\BlockReason;
use App\Models\Concerns\BelongsToTenant;
use App\Observers\VesselBlockObserver;
use Database\Factories\VesselBlockFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Anything that occupies a boat and is not a departure
 * (`docs/data-model.md` §2.4, spec AVL-3, AVL-4).
 *
 * Hard delete and no uuid (§1.1, §1.3): a block is an operator-only object,
 * and an external one is re-created by the next poll anyway.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $vessel_id
 * @property Carbon $starts_at_utc
 * @property Carbon $ends_at_utc
 * @property Carbon $local_date
 * @property Carbon $local_end_date
 * @property bool $is_all_day
 * @property BlockReason $reason
 * @property int|null $booking_id no FK — see the migration
 * @property int|null $ical_source_id
 * @property string|null $external_uid
 * @property string|null $title
 * @property string|null $notes
 * @property int|null $created_by_user_id
 */
#[ObservedBy(VesselBlockObserver::class)]
class VesselBlock extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<VesselBlockFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_at_utc' => 'datetime',
            'ends_at_utc' => 'datetime',
            'local_date' => 'date',
            'local_end_date' => 'date',
            'is_all_day' => 'boolean',
            'reason' => BlockReason::class,
        ];
    }

    /** @return BelongsTo<Vessel, $this> */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    /** @return BelongsTo<IcalSource, $this> */
    public function icalSource(): BelongsTo
    {
        return $this->belongsTo(IcalSource::class, 'ical_source_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * The occupation this block represents.
     *
     * Always a real window, including for an all-day block — §2.4 stores one so
     * that overlap maths never special-cases, and the branch that would
     * otherwise exist is the one someone forgets on the 25-hour October day.
     */
    public function window(): Window
    {
        return Window::of($this->starts_at_utc, $this->ends_at_utc);
    }

    /**
     * @param  Builder<VesselBlock>  $query
     * @return Builder<VesselBlock>
     */
    public function scopeForVessel(Builder $query, int $vesselId): Builder
    {
        return $query->where('vessel_id', $vesselId);
    }

    /**
     * Blocks whose stored window touches `$window` — the index scan, not the
     * answer.
     *
     * The exact predicate needs the turnaround buffer, which AVL-8 keeps in the
     * application and out of the query. So this narrows using
     * `vblocks_vessel_window_idx` and the caller decides.
     *
     * @param  Builder<VesselBlock>  $query
     * @return Builder<VesselBlock>
     */
    public function scopeOverlapping(Builder $query, Window $window): Builder
    {
        return $query
            ->where('starts_at_utc', '<', $window->endUtc)
            ->where('ends_at_utc', '>', $window->startUtc);
    }
}
