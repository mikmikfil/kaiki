<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BookingMode;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\CancelledBy;
use App\Enums\CancelReason;
use App\Enums\GuestDetailsStatus;
use App\Enums\WeatherChoice;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The aggregate root (`docs/data-model.md` §2.5, §4.1).
 *
 * ## The draft row is the hold
 *
 * ADR-0005 Option A, and the single most important thing to understand about
 * this table. A hold is not a cache entry with a TTL; it is `status = draft`
 * plus `hold_expires_at > now()`, in the database, and a Redis restart cannot
 * release one because the hold was never in Redis. The cache lock that
 * `HoldSeats` takes is a five-second mutex around the counter write and has
 * nothing to do with the fifteen-minute hold.
 *
 * ## Expiry is decided here, not by the sweeper
 *
 * AVL-38 asks for expiry twice over, and the reason is asymmetric: a sweeper
 * that falls behind must never cause an oversell, and a sweeper that runs early
 * must never release a hold a read still counts. So {@see self::holdsSeats()}
 * is the authority and the sweeper merely tidies the counter — every
 * availability read reaches the same conclusion on its own, with the scheduler
 * stopped.
 *
 * ## Nothing here writes a status
 *
 * §4's convention: transitions are performed by Actions in `app/Domain`, which
 * assert against {@see BookingStatus::canTransitionTo()}. A model method that
 * sets a status is how a booking reaches `confirmed` without any of the things
 * confirmation is supposed to do.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $reference
 * @property int $product_id
 * @property int|null $vessel_id
 * @property int|null $departure_id
 * @property BookingMode $mode
 * @property BookingStatus $status
 * @property BookingSource $source
 * @property string $locale
 * @property Carbon $local_date
 * @property string $local_time
 * @property Carbon $starts_at_utc
 * @property Carbon $ends_at_utc
 * @property string|null $guest_name
 * @property string|null $guest_email
 * @property string|null $guest_phone
 * @property int $pax_total
 * @property int $pax_capacity_total
 * @property array<int, array<string, mixed>> $pax_breakdown
 * @property array<int, array<string, mixed>> $extras_snapshot
 * @property array<string, mixed>|null $policy_snapshot
 * @property array<string, mixed>|null $price_snapshot
 * @property int $subtotal_cents
 * @property int $extras_cents
 * @property int $discount_cents
 * @property int $total_cents
 * @property int $deposit_cents
 * @property int $paid_cents
 * @property int $balance_cents
 * @property int $refunded_cents
 * @property int $vat_rate_bp
 * @property string $vat_category
 * @property int $vat_cents
 * @property int|null $voucher_id
 * @property GuestDetailsStatus $guest_details_status
 * @property Carbon|null $balance_due_at
 * @property WeatherChoice|null $weather_choice
 * @property Carbon|null $weather_choice_at
 * @property string|null $weather_choice_ip
 * @property Carbon|null $weather_choice_due_at
 * @property Carbon|null $weather_choice_reminded_at
 * @property Carbon|null $guest_details_deadline_at
 * @property string|null $guest_details_token
 * @property string $manage_token
 * @property Carbon|null $hold_expires_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $cancelled_at
 * @property CancelledBy|null $cancelled_by
 * @property CancelReason|null $cancel_reason
 * @property Carbon|null $terms_accepted_at
 * @property bool $is_test
 * @property Carbon|null $checked_in_at
 * @property Carbon|null $completed_at
 * @property bool $no_show
 * @property string|null $eticket_path
 * @property string|null $eticket_hash
 * @property Carbon|null $eticket_generated_at
 */
class Booking extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

    /**
     * Operator-only text, kept out of anything serialised.
     *
     * `internal_notes` reaching a guest page would be a breach of exactly the
     * kind #86's tokenised pages make easy: those pages render a booking, and a
     * field that is hidden by default cannot be rendered by forgetting.
     *
     * @var list<string>
     */
    protected $hidden = ['internal_notes'];

    /**
     * No JSON defaults in the schema — MySQL 8 refuses a literal default on a
     * JSON column — so the two NOT NULL snapshots default here.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'pax_breakdown' => '[]',
        'extras_snapshot' => '[]',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'mode' => BookingMode::class,
            'status' => BookingStatus::class,
            'source' => BookingSource::class,
            'cancelled_by' => CancelledBy::class,
            'cancel_reason' => CancelReason::class,
            'guest_details_status' => GuestDetailsStatus::class,
            'local_date' => 'date',
            'starts_at_utc' => 'datetime',
            'ends_at_utc' => 'datetime',
            'pax_breakdown' => 'array',
            'extras_snapshot' => 'array',
            'policy_snapshot' => 'array',
            'price_snapshot' => 'array',
            'pax_total' => 'integer',
            'pax_capacity_total' => 'integer',
            'subtotal_cents' => 'integer',
            'extras_cents' => 'integer',
            'discount_cents' => 'integer',
            'total_cents' => 'integer',
            'deposit_cents' => 'integer',
            'paid_cents' => 'integer',
            'balance_cents' => 'integer',
            'refunded_cents' => 'integer',
            'vat_rate_bp' => 'integer',
            'vat_cents' => 'integer',
            'hold_expires_at' => 'datetime',
            'balance_due_at' => 'datetime',
            'weather_choice' => WeatherChoice::class,
            'weather_choice_at' => 'datetime',
            'weather_choice_due_at' => 'datetime',
            'weather_choice_reminded_at' => 'datetime',
            'guest_details_deadline_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'completed_at' => 'datetime',
            'no_show' => 'boolean',
            'is_test' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Departure, $this> */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    /** @return BelongsTo<Vessel, $this> */
    public function vessel(): BelongsTo
    {
        return $this->belongsTo(Vessel::class);
    }

    /** @return BelongsTo<Voucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /** @return HasMany<BookingGuest, $this> */
    public function guests(): HasMany
    {
        return $this->hasMany(BookingGuest::class);
    }

    /** @return HasMany<BookingExtra, $this> */
    public function extras(): HasMany
    {
        return $this->hasMany(BookingExtra::class);
    }

    /**
     * Is this booking currently holding seats?
     *
     * **The authority for AVL-38's read-side expiry.** Both halves matter: the
     * status says the seats are held rather than sold, and the timestamp says
     * the hold is still alive. A booking whose `hold_expires_at` has passed
     * holds nothing the instant it passes, whether or not the sweeper has run
     * and whether or not `departures.seats_held` has caught up.
     */
    public function holdsSeats(): bool
    {
        return $this->status->holdsSeats()
            && $this->hold_expires_at !== null
            && $this->hold_expires_at->isFuture();
    }

    /** Has the hold run out while the booking still thinks it has one? */
    public function holdHasExpired(): bool
    {
        return $this->status->holdsSeats()
            && $this->hold_expires_at !== null
            && $this->hold_expires_at->isPast();
    }

    /** Seats committed rather than held (BKG-9). */
    public function committingSeats(): bool
    {
        return $this->status->committingSeats();
    }

    /**
     * Drafts whose hold has run out — the sweeper's query, and nothing else's.
     *
     * Reads `bookings_hold_expiry_idx`, which deliberately does not lead with
     * `tenant_id` because this runs across every operator at once.
     *
     * @param  Builder<Booking>  $query
     * @return Builder<Booking>
     */
    public function scopeWithExpiredHold(Builder $query): Builder
    {
        return $query
            ->where('status', BookingStatus::Draft->value)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<', now());
    }

    /**
     * Is a balance overdue right now (PRC-27.5)?
     *
     * Both halves: there has to be something to pay, and the date has to have
     * passed. A confirmed booking with `balance_cents = 0` carries a null due
     * date, and one that is cancelled carries a stale one — neither belongs in
     * the "Υπόλοιπα" bucket.
     */
    public function balanceIsOverdue(): bool
    {
        return $this->balance_cents > 0
            && $this->balance_due_at !== null
            && $this->balance_due_at->isPast()
            && $this->status->isLive();
    }

    /**
     * Is this a private-charter booking still occupying its vessel's window?
     *
     * An unexpired draft blocks a window exactly as a confirmed booking does —
     * §7.2 and the `bookings_vessel_window_idx` that serves it. Two guests
     * cannot both be at the checkout for the same boat on the same afternoon.
     */
    public function occupiesVesselWindow(): bool
    {
        return $this->mode !== BookingMode::PerSeat
            && ($this->holdsSeats() || ($this->status->isLive() && $this->status->committingSeats()));
    }
}
