<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Availability\LocalDateTimeResolver;
use App\Domain\Availability\Support\LocalDay;
use App\Enums\DepartureCancelReason;
use App\Enums\DepartureStatus;
use App\Exceptions\InconsistentDepartureTime;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Database\Factories\DepartureFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The `per_seat` sellable instance (`docs/data-model.md` §2.4).
 *
 * ## The three time columns are checked on every save (CNV-3)
 *
 * `local_date`, `local_time` and `starts_at_utc` describe one moment, and a
 * `saving` hook refuses a row where they disagree. Not paranoia: they are
 * written by the generator, by manual creation (#28) and eventually by an
 * import, and the failure mode is silent — a departure whose ticket says 09:00
 * and whose iCal entry says 08:00, discovered by a guest at the quay.
 *
 * The check is a **comparison**, not a correction. Rewriting the local pair
 * from the UTC instant would hide the bug that produced the mismatch, and the
 * October fall-back date is exactly where such a "fix" would choose the wrong
 * one of two valid answers.
 *
 * ## No soft deletes
 *
 * §2.4: cancellation is a status. Bookings must keep resolving `departure_id`
 * to render a guest's history, and generation is idempotent through
 * `departures_tenant_prod_start_uq` rather than through restoring rows.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $product_id
 * @property int $vessel_id
 * @property int|null $schedule_rule_id null = a manual one-off
 * @property Carbon $local_date
 * @property string $local_time
 * @property Carbon $starts_at_utc
 * @property Carbon $ends_at_utc
 * @property bool $dst_ambiguous
 * @property int $capacity
 * @property int $min_pax
 * @property int $seats_sold committed pax only
 * @property int $seats_held disjoint from `seats_sold`
 * @property DepartureStatus $status
 * @property DepartureCancelReason|null $cancel_reason
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by_user_id
 * @property string|null $cancellation_note
 * @property bool $is_blocked
 * @property string|null $notes
 * @property Carbon|null $completed_at
 */
class Departure extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<DepartureFactory> */
    use HasFactory;

    use HasUuid;

    protected $guarded = [];

    public static function booted(): void
    {
        static::saving(static function (Departure $departure): void {
            $departure->assertTimesAgree();
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'local_date' => 'date',
            'starts_at_utc' => 'datetime',
            'ends_at_utc' => 'datetime',
            'dst_ambiguous' => 'boolean',
            'capacity' => 'integer',
            'min_pax' => 'integer',
            'seats_sold' => 'integer',
            'seats_held' => 'integer',
            'status' => DepartureStatus::class,
            'cancel_reason' => DepartureCancelReason::class,
            'cancelled_at' => 'datetime',
            'is_blocked' => 'boolean',
            'completed_at' => 'datetime',
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

    /** @return BelongsTo<ScheduleRule, $this> */
    public function scheduleRule(): BelongsTo
    {
        return $this->belongsTo(ScheduleRule::class);
    }

    /** @return BelongsTo<User, $this> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /**
     * @param  Builder<Departure>  $query
     * @return Builder<Departure>
     */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->whereIn('status', [DepartureStatus::Scheduled, DepartureStatus::Guaranteed]);
    }

    /**
     * Every departure touching a local day, compared in UTC (AVL-13, AVL-14).
     *
     * The comparison is against `starts_at_utc` rather than `local_date`
     * because AVL-13 says interval logic compares UTC columns — the local
     * column is for the operator's filter, and a 23-hour day is exactly where
     * the two would disagree.
     *
     * @param  Builder<Departure>  $query
     * @return Builder<Departure>
     */
    public function scopeOnLocalDay(Builder $query, LocalDay $day): Builder
    {
        return $query
            ->where('starts_at_utc', '>=', $day->startUtc)
            ->where('starts_at_utc', '<', $day->endUtcExclusive);
    }

    /**
     * Seats a guest can still buy (§2.4, AVL-22.3, AVL-24).
     *
     * `capacity − seats_sold − seats_held`. The two counters are **disjoint and
     * additive**, not nested: a pax moves from held to sold at
     * `checkout_started`, and keeping holds out of `seats_sold` is what stops an
     * unpaid draft flipping the departure to `guaranteed` (§4.2) or hiding it
     * from the at-risk dashboard (§7.3).
     */
    public function seatsAvailable(): int
    {
        return max(0, $this->capacity - $this->seats_sold - $this->seats_held);
    }

    /** Has the operator committed to sailing (§4.2)? */
    public function meetsMinimum(): bool
    {
        return $this->seats_sold >= $this->min_pax;
    }

    /** The tenant timezone this departure's local columns are written in. */
    public function timezone(): string
    {
        return LocalDateTimeResolver::timezone($this->relationLoaded('tenant') ? $this->tenant : null);
    }

    /**
     * CNV-3: refuse a row whose local pair and UTC instant disagree.
     *
     * On the **ambiguous** October date the local pair renders identically from
     * either instant, so this check passes for both — which is correct. Which
     * of the two was chosen is ADR-0016's tie-break, recorded in
     * `dst_ambiguous`, and is not something a consistency check can or should
     * second-guess.
     *
     * @throws InconsistentDepartureTime
     */
    public function assertTimesAgree(): void
    {
        if ($this->starts_at_utc === null || $this->local_date === null || $this->local_time === null) {
            return;
        }

        $timezone = $this->timezone();
        $local = $this->starts_at_utc->copy()->setTimezone($timezone);

        $expected = $local->toDateString() . ' ' . $local->format('H:i:s');
        $stored = $this->local_date->toDateString() . ' ' . $this->normalisedLocalTime();

        if ($expected !== $stored) {
            throw InconsistentDepartureTime::forDeparture($this, $expected);
        }
    }

    /** `HH:MM` from a form and `HH:MM:SS` from the database are the same time. */
    private function normalisedLocalTime(): string
    {
        $time = (string) $this->local_time;

        return strlen($time) === 5 ? "{$time}:00" : $time;
    }
}
