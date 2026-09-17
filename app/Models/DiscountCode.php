<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Pricing\Actions\ApplyDiscountCode;
use App\Enums\BookingStatus;
use App\Enums\DiscountKind;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuid;
use Database\Factories\DiscountCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A discount code, «κουπόνι» (product owner, 2026-09-17).
 *
 * ## Why not a {@see Voucher}
 *
 * The two look alike on a checkout page — a code, some money off — and are
 * opposite things underneath:
 *
 * - A voucher is **owed**: 50 € of credit issued to one guest after a
 *   cancelled trip, spent down across bookings, its balance recomputed from a
 *   ledger under `lockForUpdate()` so it is never spent twice, reminded about
 *   before it expires, and part of what a refund can be paid in.
 * - A code is **offered**: a rule — 10%, or 20 € — that any guest may type, as
 *   many times as the operator allows, with nothing owed to anybody and no
 *   balance to protect.
 *
 * Storing codes as vouchers would give every percentage a meaningless
 * `amount_cents`, every code a `remaining_cents` the ledger would "restore" on
 * cancellation, and every voucher invariant an exception for the case it was
 * not written for. So: its own table, its own Action
 * ({@see ApplyDiscountCode}), and both can sit on one booking — the code comes
 * off first, and a voucher is spent against what is left.
 *
 * ## Uses and revenue are counted, not stored
 *
 * «Χρήσεις» and «Έσοδα που έφερε» come from the bookings that carry the code
 * and actually happened (confirmed, checked in, completed): a counter column
 * would drift the first time a booking was cancelled or a checkout abandoned.
 *
 * @property int $id
 * @property string $uuid
 * @property int $tenant_id
 * @property string $name «Εσωτερικό όνομα», never shown to a guest
 * @property string $code upper-case
 * @property DiscountKind $kind
 * @property int $value a percentage (1–100) or cents
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_until
 * @property int|null $max_uses
 * @property int|null $product_id null for every trip
 * @property bool $is_active
 */
class DiscountCode extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<DiscountCodeFactory> */
    use HasFactory;

    use HasUuid;
    use SoftDeletes;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => DiscountKind::class,
            'value' => 'integer',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'max_uses' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** Codes are typed any old way and stored one way. */
    public static function normalise(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<Booking, $this> */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * The statuses a use counts in: the booking happened, or is happening.
     *
     * @return list<string>
     */
    public static function usedStatuses(): array
    {
        return [
            BookingStatus::Confirmed->value,
            BookingStatus::CheckedIn->value,
            BookingStatus::Completed->value,
        ];
    }

    /** How much comes off `$grossCents`, never more than it. */
    public function amountFor(int $grossCents): int
    {
        $gross = max(0, $grossCents);

        return match ($this->kind) {
            // Rounded half up, to the cent, like every other figure (§1.9).
            DiscountKind::Percent => min($gross, intdiv($gross * min(100, $this->value) + 50, 100)),
            DiscountKind::Fixed => min($gross, $this->value),
        };
    }
}
