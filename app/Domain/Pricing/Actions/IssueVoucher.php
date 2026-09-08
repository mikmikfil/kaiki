<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Actions;

use App\Domain\Booking\Support\RefundEntitlement;
use App\Enums\VoucherReason;
use App\Enums\VoucherStatus;
use App\Models\Booking;
use App\Models\Voucher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Credit issued against a cancelled booking (spec CXL-8, PRC-19.3).
 *
 * ## One place that knows how long a voucher lasts
 *
 * CXL-8: *"A voucher issued for a force-majeure cancellation expires
 * `force_majeure_voucher_months` (default 18) after issue, from the policy
 * snapshot."* That default appeared in four places — the column, the factory,
 * `CancellationPolicyData::fromSnapshot()` and `RestoreVoucher` — and in the
 * fourth it was **12**, which is the failure mode a repeated literal always
 * has: three copies agree and nobody reads the fourth. A guest whose voucher
 * was issued through the expired-voucher branch of PRC-19.3 got six months less
 * than the policy they accepted said, and nothing would have reported it.
 *
 * Now the months come from the booking's own frozen snapshot through
 * {@see RefundEntitlement::policyOf()}, which
 * carries §3.3's default in the one place a snapshot is read.
 *
 * ## The expiry is set at issue and never extended
 *
 * PRC-19.3's other half. Restoring value to a **live** voucher does not push
 * its expiry out, and an expired one is not revived — a new voucher is issued
 * instead, linked back through `issued_for_booking_id`. Extending an expiry
 * would quietly rewrite a term the guest already accepted, in their favour
 * today and against them the first time an operator relies on it.
 */
final class IssueVoucher
{
    /**
     * @param  int  $cents  the credit; nothing is issued below one cent
     * @param  string|null  $note  the operator's own words, stored in `notes`
     */
    public function __invoke(
        Booking $booking,
        int $cents,
        VoucherReason $reason = VoucherReason::ForceMajeure,
        ?string $note = null,
        string $currency = 'EUR',
    ): ?Voucher {
        if ($cents < 1) {
            return null;
        }

        $months = self::validityMonthsFor($booking);

        /** @var Voucher $voucher */
        $voucher = Voucher::query()->create([
            'uuid' => (string) Str::uuid(),
            'code' => self::mintCode(),
            'amount_cents' => $cents,
            'remaining_cents' => $cents,
            'currency' => $currency,
            'status' => VoucherStatus::Active,
            'issued_at' => now(),
            'expires_at' => now()->addMonths($months),
            // The trail from the cancelled booking to the credit it produced.
            // §2.5's FK-less side of the `vouchers` ↔ `bookings` cycle, which is
            // why this is an id rather than a relation save.
            'issued_for_booking_id' => $booking->getKey(),
            'reason' => $reason,
            'notes' => $note,
        ]);

        return $voucher;
    }

    /**
     * A voucher an operator issued out of goodwill, against no booking (OPS-16).
     *
     * ## Why this is not the method above with a nullable argument
     *
     * The expiry rule is the difference, and it is the whole reason the two are
     * separate. {@see self::__invoke()} reads the validity from the **booking's
     * frozen policy snapshot** — the terms the guest accepted when they paid,
     * which is the only defensible source for a credit issued because that
     * booking was cancelled.
     *
     * A goodwill voucher has no such booking and therefore no such promise. Its
     * validity comes from configuration, and pretending otherwise — by passing
     * a nullable booking into a method whose docblock is about honouring a
     * snapshot — would make the one important sentence in that method untrue
     * half the time.
     *
     * ## `expires_at` may be null, and that is a real choice
     *
     * An operator apologising for a bad afternoon often means "whenever you
     * like". PRC-21 evaluates expiry at end of day in the tenant timezone and
     * {@see Voucher::hasExpired()} is false for a null, so a voucher with no
     * expiry simply never ages out. The form offers a default and lets it be
     * cleared.
     */
    public function goodwill(
        int $cents,
        VoucherReason $reason = VoucherReason::Goodwill,
        ?string $note = null,
        ?Carbon $expiresAt = null,
        ?int $issuedByUserId = null,
        string $currency = 'EUR',
    ): ?Voucher {
        if ($cents < 1) {
            return null;
        }

        /** @var Voucher $voucher */
        $voucher = Voucher::query()->create([
            'uuid' => (string) Str::uuid(),
            'code' => self::mintCode(),
            'amount_cents' => $cents,
            'remaining_cents' => $cents,
            'currency' => $currency,
            'status' => VoucherStatus::Active,
            'issued_at' => now(),
            'expires_at' => $expiresAt,
            // No booking. The column is nullable precisely for this.
            'issued_for_booking_id' => null,
            'reason' => $reason,
            'notes' => $note,
            'issued_by_user_id' => $issuedByUserId,
        ]);

        return $voucher;
    }

    /** The default life of a goodwill voucher, in months. */
    public static function goodwillMonths(): int
    {
        $months = config('kaiki.vouchers.goodwill_months', 12);

        return is_int($months) && $months > 0 ? $months : 12;
    }

    /**
     * The validity, from the booking's frozen snapshot (CXL-8).
     *
     * Read through the snapshot reader rather than off the array directly, so
     * a booking whose snapshot predates the field gets §3.3's default — 18 —
     * rather than zero months, which would issue a voucher that expired the
     * instant it was created.
     */
    public static function validityMonthsFor(Booking $booking): int
    {
        return RefundEntitlement::policyOf($booking)->forceMajeureVoucherMonths;
    }

    /**
     * A voucher code.
     *
     * Not the booking reference's alphabet: a voucher code is typed into a
     * booking form by a guest reading it off an email, so the shape that
     * matters is "obviously a voucher" rather than "collision-proof at 24
     * million" — and `vouchers.code` carries its own unique index, which is
     * what actually settles a collision.
     */
    private static function mintCode(): string
    {
        return 'GIFT-' . strtoupper(Str::random(8));
    }
}
