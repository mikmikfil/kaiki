<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\VoucherRedemptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One voucher movement (`docs/data-model.md` §2.5, PRC-19.4, ADR-0017).
 *
 * ## `vouchers.remaining_cents` must always be reconstructible from these rows
 *
 * PRC-19.4 in as many words, and `VoucherLedgerTest` asserts it after every
 * shape of movement — apply, partial redeem, cancel, reverse. A denormalised
 * balance that can only be believed is a balance that is eventually wrong, and
 * the operator finds out when a guest tries to spend money that is not there.
 *
 * {@see self::consumedFor()} is the reconstruction, in one place.
 *
 * ## A reversal amends this row rather than adding an opposite one
 *
 * PRC-19.4 says *"cancellation writes a reversal row"*, and §2.5's unique index
 * on (`tenant_id`, `voucher_id`, `booking_id`) makes a second row for the same
 * pair impossible. The index is the more valuable of the two: it is what stops
 * one voucher being applied twice to one booking, which is a real double-spend.
 *
 * So `reversed_at` and `reversed_amount_cents` live here, and the movement, its
 * direction, its amount and its time are all still recorded and never deleted.
 * The spec was reconciled to this by #81 with the reason in `CHANGELOG.md`.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $voucher_id
 * @property int $booking_id
 * @property int $amount_cents
 * @property Carbon $redeemed_at
 * @property Carbon|null $reversed_at
 * @property int $reversed_amount_cents
 * @property string|null $reason
 */
class VoucherRedemption extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<VoucherRedemptionFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'reversed_amount_cents' => 'integer',
            'redeemed_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Voucher, $this> */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** What this movement is still holding: taken, less what went back. */
    public function netCents(): int
    {
        return max(0, $this->amount_cents - $this->reversed_amount_cents);
    }

    /**
     * Everything this voucher currently has spent, across every booking.
     *
     * The ledger side of `remaining_cents`, and the only definition of it.
     * A caller that summed `amount_cents` alone would count reversed movements
     * as still spent and quietly shrink an operator's voucher every time a
     * guest cancelled.
     */
    public static function consumedFor(int $voucherId): int
    {
        $rows = static::query()
            ->where('voucher_id', $voucherId)
            ->get(['amount_cents', 'reversed_amount_cents']);

        $consumed = 0;

        foreach ($rows as $row) {
            $consumed += $row->netCents();
        }

        return $consumed;
    }

    /**
     * What voucher value one booking is currently carrying (PRC-19.2).
     *
     * The ADR-0017 split reads this rather than `bookings.discount_cents`, and
     * the difference is not cosmetic. §2.5 defines `discount_cents` as *"voucher
     * + manual discount"* — a manual discount is a **price reduction**, not
     * consideration the guest handed over, and a pro-rata split that counted it
     * would refund a guest money nobody ever paid.
     *
     * #84's issue says so outright: compute the split from the ledger, *"which
     * is reconstructible, rather than from a denormalised column"*. Net of
     * reversals, so a partially restored booking is not restored twice.
     */
    public static function usedByBooking(int $bookingId): int
    {
        $rows = static::query()
            ->where('booking_id', $bookingId)
            ->get(['amount_cents', 'reversed_amount_cents']);

        $used = 0;

        foreach ($rows as $row) {
            $used += $row->netCents();
        }

        return $used;
    }
}
