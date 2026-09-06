<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QuoteStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use App\Support\Tenancy;
use Database\Factories\QuoteFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An offer an operator made (`docs/data-model.md` §2.5, §4.4, spec BKG-24…27).
 *
 * ## Not a hold, and that is the single most important behaviour in quote mode
 *
 * §4.4's own note says so: *"the boat is not reserved while the guest thinks
 * about it. Acceptance re-checks availability and can fail."* Nothing on this
 * model touches a counter, and {@see self::canBeAccepted()} deliberately does
 * **not** answer the availability question — that is a locked read on the
 * vessel calendar, taken at the moment of acceptance, and answering it from
 * here would be answering it from a row written days ago.
 *
 * ## `quote_token` is the credential
 *
 * `/q/{token}` carries no tenant, so the token *is* the tenant resolution — the
 * same shape as `bookings.manage_token`, and the same reason it is globally
 * unique with no tenant prefix. {@see self::findByToken()} is the one lookup
 * that runs outside tenancy, and it is a textbook `withoutTenancy()` case:
 * this is what *resolves* the tenant.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property int $booking_id
 * @property int $version
 * @property QuoteStatus $status
 * @property string $quote_token
 * @property int $subtotal_cents
 * @property int $discount_cents
 * @property int $total_cents
 * @property int $deposit_cents
 * @property int $vat_rate_bp
 * @property Carbon $valid_until
 * @property string|null $message
 * @property string|null $terms
 * @property Carbon|null $sent_at
 * @property Carbon|null $viewed_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $declined_at
 * @property string|null $decline_reason
 * @property Carbon|null $expired_at
 * @property int|null $created_by_user_id
 */
class Quote extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<QuoteFactory> */
    use HasFactory;

    use HasUuid;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'version' => 'integer',
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'total_cents' => 'integer',
            'deposit_cents' => 'integer',
            'vat_rate_bp' => 'integer',
            'valid_until' => 'datetime',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return HasMany<QuoteLineItem, $this> */
    public function lineItems(): HasMany
    {
        return $this->hasMany(QuoteLineItem::class)->orderBy('sort_order');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Has this offer run out?
     *
     * Read from `valid_until` rather than from the status, so a quote is dead
     * the instant it lapses rather than the moment the sweeper next runs — the
     * same read-side rule the seat hold and the voucher both follow, and for
     * the same reason: a backlogged queue must never make a stale offer
     * acceptable.
     */
    public function hasLapsed(): bool
    {
        return $this->valid_until->isPast();
    }

    /**
     * Could the guest accept this, on the evidence this row carries?
     *
     * **Availability is not part of the answer**, deliberately. §4.4 requires
     * acceptance to re-check the vessel window under a lock, and a boolean
     * computed here would be a promise made from a row written days ago. This
     * says only that the offer is live and undecided; `AcceptQuote` says
     * whether the boat is.
     */
    public function canBeAccepted(): bool
    {
        return $this->status->isOpen() && ! $this->hasLapsed();
    }

    /**
     * Resolve a quote from its token, with no tenant in context.
     *
     * `quotes_quote_token_unique` is global and deliberately has no tenant
     * prefix — the same textbook `withoutTenancy()` case as
     * {@see ApiKey::findByPrefix()} and {@see Payment::findByGatewayRef()}:
     * this lookup is what *resolves* the tenant.
     */
    public static function findByToken(string $token): ?self
    {
        if (trim($token) === '') {
            return null;
        }

        return Tenancy::withoutTenancy(
            static fn (): ?self => static::query()->where('quote_token', $token)->first(),
        );
    }

    /**
     * Quotes the expiry sweeper should look at (§4.4).
     *
     * `sent` only. A `draft` has never been shown to anybody, so letting it
     * lapse would expire an offer the guest was never made — the operator
     * simply has an unfinished quote, which is not the sweeper's business.
     *
     * @param  Builder<Quote>  $query
     * @return Builder<Quote>
     */
    public function scopeLapsed(Builder $query): Builder
    {
        return $query
            ->where('status', QuoteStatus::Sent->value)
            ->where('valid_until', '<', now());
    }
}
