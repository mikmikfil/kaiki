<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VoucherReason;
use App\Enums\VoucherStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\VoucherFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;

/**
 * Operator-issued credit (`docs/data-model.md` §2.5).
 *
 * ## `remaining_cents` is never decremented
 *
 * §1.9 and the **[LOCK]** marker. It is *recomputed* from `voucher_redemptions`
 * inside a transaction holding `lockForUpdate()` on this row. A decrement looks
 * equivalent and is not: two concurrent checkouts both read €50 remaining, both
 * subtract €50, and the operator has given away €100. That is the overselling
 * bug in a different table, and it is covered by the same MySQL-only
 * concurrency test file.
 *
 * The ledger and the arithmetic arrive with confirmation (#81) and with the
 * pricing rules (PRC-18 to PRC-22). This table is here because
 * `bookings.voucher_id` is a real foreign key and SQLite cannot add one later.
 *
 * ## Expiry is a status *and* a timestamp, and neither is redundant
 *
 * `expires_at` is when it stops being spendable; `status = expired` is the
 * sweeper having noticed. {@see self::isSpendable()} consults both, so a
 * voucher is unusable the moment it expires rather than the moment a job next
 * runs — the same read-side rule the seat hold follows, for the same reason.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $code
 * @property int $amount_cents
 * @property int $remaining_cents
 * @property string $currency
 * @property VoucherStatus $status
 * @property Carbon $issued_at
 * @property Carbon|null $expires_at
 * @property int|null $issued_for_booking_id
 * @property VoucherReason $reason
 * @property string|null $notes
 * @property Carbon|null $expiry_reminder_sent_at
 */
class Voucher extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<VoucherFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => VoucherStatus::class,
            'reason' => VoucherReason::class,
            'amount_cents' => 'integer',
            'remaining_cents' => 'integer',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'expiry_reminder_sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    /**
     * The booking this voucher was issued *for*, if any.
     *
     * Not a relation, because there is no foreign key to hang one on — this is
     * the weak side of the cycle with `bookings` and always will be (§0, §6).
     * A method rather than a `belongsTo` so that the absence is visible at the
     * call site instead of looking like an ordinary relation that happens to
     * skip referential integrity.
     */
    public function issuedForBooking(): ?Booking
    {
        if ($this->issued_for_booking_id === null) {
            return null;
        }

        // `withoutGlobalScope(SoftDeletingScope::class)` on purpose: a voucher
        // issued for a booking that was later soft-deleted still has to be able
        // to say what it was issued for, or the nightly reconciler reports it
        // as an orphan every night forever.
        return Booking::query()
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->find($this->issued_for_booking_id);
    }

    /** Has this voucher passed its expiry, whatever the sweeper has done? */
    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Can this voucher be applied to a booking right now?
     *
     * All three conditions, because each fails differently and an operator
     * asked "why did the code not work" needs the right one.
     */
    public function isSpendable(): bool
    {
        return $this->status->isSpendable()
            && ! $this->hasExpired()
            && $this->remaining_cents > 0;
    }
}
