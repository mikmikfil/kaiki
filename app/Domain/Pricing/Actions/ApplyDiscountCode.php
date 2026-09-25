<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Booking\Actions\StartCheckout;
use App\Domain\Pricing\Support\DepositCalculator;
use App\Enums\BookingStatus;
use App\Exceptions\DiscountCodeRefused;
use App\Models\Booking;
use App\Models\DiscountCode;
use App\Models\RatePlan;
use App\Support\Money\Cents;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

/**
 * Put a discount code on a booking that has not been paid for, or take it off
 * (product owner, 2026-09-17).
 *
 * ## When a code is refused
 *
 * {@see self::refusal()}, one sentence each, in the guest's language: no such
 * code, switched off, not yet valid, expired, used up, or for another trip.
 * «Used up» counts bookings that happened or are being paid for right now,
 * other than this one — a draft somebody abandoned does not spend a use.
 *
 * ## The snapshot stays the truth
 *
 * The booking's `price_snapshot` is what the checkout page prints, what the
 * gateway is asked for and what an invoice is issued from, and an invoice
 * refuses a snapshot whose net and VAT do not add up to the total (MYD-7). So
 * applying a code rewrites the snapshot's `discount_cents`, `total_cents`, its
 * VAT split and its deposit, and records what was applied under
 * `discount_code` — code, name, kind, value, amount — so the booking still
 * says why the price is lower after the code itself is edited or deleted.
 *
 * ## Only before payment
 *
 * A draft, or a checkout in flight. A confirmed booking's price is what was
 * paid; changing it here would move money nobody collected.
 *
 * A voucher, if the booking also has one, is spent afterwards against what the
 * code leaves ({@see ApplyVoucher}).
 */
final class ApplyDiscountCode
{
    /**
     * @throws DiscountCodeRefused
     */
    public function __invoke(Booking $booking, ?string $typed): ?DiscountCode
    {
        if (! in_array($booking->status, [BookingStatus::Draft, BookingStatus::PendingPayment], true)) {
            throw new DiscountCodeRefused(__('discount_codes.refused.too_late'));
        }

        if ($typed === null || trim($typed) === '') {
            $this->write($booking, null, 0);

            return null;
        }

        $code = self::find($typed);
        $refusal = $code === null
            ? __('discount_codes.refused.unknown')
            : self::refusal($code, (int) $booking->product_id, $booking->getKey());

        if ($refusal !== null || $code === null) {
            throw new DiscountCodeRefused((string) $refusal);
        }

        $this->write($booking, $code, $code->amountFor(self::gross($booking)));

        return $code;
    }

    /**
     * Is the code on this booking still good? Taken off if not.
     *
     * Asked again just before the gateway, because a code can run out or
     * expire while a guest is typing passport numbers — and the guest must see
     * the new price before paying it, not after.
     *
     * @return bool true when nothing changed
     */
    public function recheck(Booking $booking): bool
    {
        if ($booking->discount_code_id === null) {
            return true;
        }

        $code = DiscountCode::query()->find($booking->discount_code_id);

        if ($code instanceof DiscountCode && self::refusal($code, (int) $booking->product_id, $booking->getKey()) === null) {
            return true;
        }

        $this->write($booking, null, 0);

        return false;
    }

    /**
     * Take one of the code's uses, or say there is none left (2026-09-18).
     *
     * ## Why counting was not enough
     *
     * {@see self::refusal()} counts the bookings already holding a use, and two
     * guests paying for the last one at the same moment both counted the same
     * number: each saw «one left», each went to the gateway, and a code capped
     * at fifty redeemed fifty-one. Nothing in the count is wrong — it is that a
     * count taken before a decision is a guess by the time the decision lands.
     *
     * So the row is locked first. The second caller waits at the lock, and
     * counts afterwards, by which time the first has committed its
     * `pending_payment`; whoever loses is told the code is gone before paying
     * rather than after.
     *
     * Must be called **inside** the transaction that moves the booking to
     * `pending_payment` — {@see StartCheckout}
     * does exactly that, in AVL-45's lock order, after the booking itself.
     * Called outside one, the lock is released immediately and this is a count
     * again.
     */
    public static function claim(Booking $booking): bool
    {
        if ($booking->discount_code_id === null) {
            return true;
        }

        /** @var DiscountCode|null $code */
        $code = DiscountCode::query()->lockForUpdate()->find($booking->discount_code_id);

        if (! $code instanceof DiscountCode) {
            return false;
        }

        return self::refusal($code, (int) $booking->product_id, $booking->getKey()) === null;
    }

    public static function find(string $typed): ?DiscountCode
    {
        return DiscountCode::query()->where('code', DiscountCode::normalise($typed))->first();
    }

    /** Why this code cannot be used on this trip today, or null if it can. */
    public static function refusal(DiscountCode $code, int $productId, ?int $exceptBookingId = null): ?string
    {
        $today = Carbon::now(self::timezone())->toDateString();

        return match (true) {
            ! $code->is_active => __('discount_codes.refused.inactive'),
            $code->valid_from !== null && $today < $code->valid_from->toDateString() => __('discount_codes.refused.not_yet'),
            $code->valid_until !== null && $today > $code->valid_until->toDateString() => __('discount_codes.refused.expired'),
            $code->product_id !== null && $code->product_id !== $productId => __('discount_codes.refused.other_trip'),
            $code->max_uses !== null && self::usesInFlight($code, $exceptBookingId) >= $code->max_uses => __('discount_codes.refused.used_up'),
            default => null,
        };
    }

    /** Bookings holding a use: those that happened, and those being paid for. */
    private static function usesInFlight(DiscountCode $code, ?int $exceptBookingId): int
    {
        return Booking::query()
            ->where('discount_code_id', $code->getKey())
            ->whereIn('status', [...DiscountCode::usedStatuses(), BookingStatus::PendingPayment->value])
            ->when($exceptBookingId !== null, static fn ($query) => $query->whereKeyNot($exceptBookingId))
            ->count();
    }

    private static function gross(Booking $booking): int
    {
        return (int) $booking->subtotal_cents + (int) $booking->extras_cents;
    }

    private function write(Booking $booking, ?DiscountCode $code, int $amount): void
    {
        $snapshot = is_array($booking->price_snapshot) ? $booking->price_snapshot : [];
        $total = max(0, self::gross($booking) - $amount);

        $snapshot['discount_cents'] = $amount;
        $snapshot['total_cents'] = $total;
        $snapshot['discount_code'] = $code === null ? null : [
            'id' => $code->getKey(),
            'code' => $code->code,
            'name' => $code->name,
            'kind' => $code->kind->value,
            'value' => $code->value,
            'amount_cents' => $amount,
        ];

        if (is_array($snapshot['vat'] ?? null) && isset($snapshot['vat']['rate_bp'])) {
            $net = Cents::netOfInclusive($total, (int) $snapshot['vat']['rate_bp']);
            // The remainder, never rounded on its own (see ComputePrice).
            $snapshot['vat']['net_cents'] = $net;
            $snapshot['vat']['vat_cents'] = $total - $net;
        }

        $snapshot = self::rewriteDeposit($booking, $snapshot, $total);

        $booking->forceFill([
            'discount_code_id' => $code?->getKey(),
            'discount_cents' => $amount,
            'total_cents' => $total,
            'balance_cents' => max(0, $total - (int) $booking->paid_cents),
            'price_snapshot' => $snapshot,
        ])->save();
    }

    /**
     * PRC-25: the deposit is taken from the total after the discount — in the
     * snapshot the checkout page prints, and in `deposit_cents`, which is what
     * {@see StartCheckout} charges (2026-09-25). Until then only the snapshot
     * moved, and a €300 trip at 30% with a €100 code still charged €90.
     *
     * A booking paying in full (a deposit equal to its old total) stays paying
     * in full. One without a rate plan — a quote's fixed deposit — keeps its
     * deposit, capped at the new total. Fills the booking, does not save it.
     *
     * @param  array<string, mixed>  $snapshot  the snapshot being written
     * @return array<string, mixed> the snapshot, with its deposit rewritten
     */
    public static function rewriteDeposit(Booking $booking, array $snapshot, int $newTotal): array
    {
        $oldTotal = (int) $booking->getOriginal('total_cents');
        $oldDeposit = (int) $booking->deposit_cents;
        $plan = isset($snapshot['rate_plan_id']) ? RatePlan::query()->find($snapshot['rate_plan_id']) : null;

        if ($plan instanceof RatePlan && array_key_exists('deposit', $snapshot)) {
            $snapshot['deposit'] = DepositCalculator::forPlan($plan, $newTotal, (bool) (Tenancy::current()->deposits_enabled ?? false));
        }

        $deposit = match (true) {
            $oldDeposit >= $oldTotal => $newTotal,
            $plan instanceof RatePlan && is_array($snapshot['deposit'] ?? null) => (int) $snapshot['deposit']['amount_cents'],
            default => min($oldDeposit, $newTotal),
        };

        $booking->forceFill(['deposit_cents' => max(0, $deposit)]);

        return $snapshot;
    }

    private static function timezone(): string
    {
        $timezone = Tenancy::current()?->timezone;

        return is_string($timezone) && $timezone !== '' ? $timezone : (string) config('kaiki.defaults.timezone');
    }
}
