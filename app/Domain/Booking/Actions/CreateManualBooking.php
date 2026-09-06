<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Availability\Actions\HoldSeats;
use App\Domain\Booking\Data\BookingDraftData;
use App\Domain\Booking\Data\ManualBookingAdjustment;
use App\Enums\BookingSource;
use App\Enums\PaymentGatewayName;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Events\CapacityOverridden;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A booking taken on the phone or at a desk (spec BKG-30 to BKG-33).
 *
 * ## Same engine, and that is the requirement rather than a convenience
 *
 * BKG-31: *"A manual booking goes through the **same availability and pricing
 * engine**."* So this delegates to {@see CreateBookingDraft} rather than
 * writing its own row — an operator quoting a price on the phone must be
 * quoting the price the website would have quoted, and a second insert path
 * would drift from the first in exactly the fields that matter (the VAT split,
 * the pax breakdown, the frozen policy).
 *
 * ## What a manual booking is allowed to do that a guest is not
 *
 * BKG-32: *"may exceed `min_lead_time_hours` and `max_advance_days` … but MUST
 * NOT exceed capacity or the legal `capacity_max`. Capacity override requires
 * an explicit confirmation and is logged."*
 *
 * Those two sentences contradict each other read plainly, and the reconciliation
 * is recorded in `docs/BUILD-LOG.md`: the **legal** `capacity_max` is a
 * certificate and has no override at all; the **departure's** capacity is a
 * commercial number the operator chose and can be exceeded with an explicit
 * confirmation and an audit row. An operator may squeeze one more person onto a
 * boat they under-sold. They may not sail illegally full.
 *
 * The lead-time and advance rules need no code here: they are enforced by the
 * availability *calendar*, which decides what a guest is shown, and never by
 * the draft Action. A manual booking simply does not go through the calendar.
 *
 * ## Cash and bank are payments, not a different kind of booking
 *
 * BKG-33: they create ordinary `Payment` rows, never call a gateway, and are
 * excluded from reconciliation. {@see PaymentGatewayName::isExternal()} is what
 * excludes them, so the exclusion is a property of the row rather than a filter
 * somebody has to remember to write.
 */
final class CreateManualBooking
{
    public function __construct(
        private readonly CreateBookingDraft $createDraft,
        private readonly HoldSeats $holdSeats,
        private readonly ConfirmBooking $confirmBooking,
    ) {}

    /**
     * @param  ManualBookingAdjustment|null  $adjustment  BKG-31's discount or total override
     * @param  string|null  $capacityOverrideReason  BKG-32's explicit confirmation; null means the ordinary limits apply
     * @param  PaymentGatewayName|null  $paidBy  `cash` or `bank_transfer` to mark it paid at once (BKG-33)
     */
    public function __invoke(
        BookingDraftData $data,
        ?ManualBookingAdjustment $adjustment = null,
        ?string $capacityOverrideReason = null,
        ?PaymentGatewayName $paidBy = null,
    ): Booking {
        // The source is this Action's to set, not the caller's. A manual
        // booking that arrived claiming to be a widget booking would be
        // invisible in every report that separates the two — and
        // `BookingCreateRequest` rejects `manual` from the public API for the
        // mirror-image reason.
        $data = $this->asManual($data, $capacityOverrideReason !== null);

        $booking = ($this->createDraft)($data);

        if ($adjustment instanceof ManualBookingAdjustment && ! $adjustment->isEmpty()) {
            $this->applyAdjustment($booking, $adjustment);
        }

        if ($capacityOverrideReason !== null && $booking->departure !== null) {
            // The draft's own hold was skipped above, so this is the only hold
            // and it is the one that carries the override.
            ($this->holdSeats)($booking, $booking->departure, allowOvercapacity: true);

            // After the hold, not before: an override that was refused by the
            // legal ceiling never happened and must leave no row saying it did.
            CapacityOverridden::dispatch($booking, $capacityOverrideReason);
        }

        if ($paidBy !== null) {
            $booking = $this->markPaid($booking, $paidBy);
        }

        return $booking->refresh();
    }

    /**
     * Source `manual`, and — when overriding — no hold from the draft Action.
     *
     * `CreateBookingDraft` takes the hold itself, and it takes it *without* an
     * override, so a party that does not fit would be refused before this
     * Action ever reached its own hold. Skipping it there and taking it here is
     * the only ordering in which the override can apply at all.
     */
    private function asManual(BookingDraftData $data, bool $overriding): BookingDraftData
    {
        return new BookingDraftData(
            product: $data->product,
            date: $data->date,
            guestName: $data->guestName,
            guestEmail: $data->guestEmail,
            guestPhone: $data->guestPhone,
            guestCountry: $data->guestCountry,
            locale: $data->locale,
            source: BookingSource::Manual,
            paxByCode: $data->paxByCode,
            extraQuantities: $data->extraQuantities,
            startTime: $data->startTime,
            extraHours: $data->extraHours,
            voucherCode: $data->voucherCode,
            specialRequests: $data->specialRequests,
            // An operator taking a booking on the phone read the terms aloud or
            // did not; either way the consent is theirs to record, and stamping
            // one automatically would manufacture evidence (GDR-9).
            termsAcceptedAt: $data->termsAcceptedAt,
            ipAddress: $data->ipAddress,
            userAgent: $data->userAgent,
            utm: $data->utm,
            isTest: $data->isTest,
            skipHold: $overriding,
        );
    }

    /**
     * BKG-31's adjustment, in the snapshot and on the columns.
     *
     * Both, and neither alone. The **snapshot** is the record of what was
     * decided and why; the **columns** are what every report, every invoice and
     * every gateway session read. A snapshot without the columns would be a
     * documented decision nothing acted on, and columns without the snapshot
     * would be a total nobody can explain.
     */
    private function applyAdjustment(Booking $booking, ManualBookingAdjustment $adjustment): void
    {
        $computed = $booking->total_cents;
        $applied = $adjustment->applyTo($computed);

        $snapshot = $booking->price_snapshot ?? [];
        $snapshot['operator_adjustments'] = $adjustment->toSnapshot($computed);

        $booking->forceFill([
            'price_snapshot' => $snapshot,
            'discount_cents' => $booking->discount_cents + ($computed - $applied),
            'total_cents' => $applied,
            // Nothing has been paid yet, so the balance is the whole of it.
            'balance_cents' => $applied,
            // A deposit larger than the agreed total is not a deposit.
            'deposit_cents' => min($booking->deposit_cents, $applied),
        ])->save();
    }

    /**
     * BKG-33: cash or bank transfer, recorded as a payment and confirmed.
     *
     * The `Payment` row is ordinary in every respect except its gateway, which
     * is what keeps it out of reconciliation — there is no third party to
     * reconcile against, because the money arrived at a desk or in a bank
     * account.
     *
     * `idempotency_key` is still minted (PAY-9). It deduplicates nothing here
     * and costs one uuid; a payments table where the column is sometimes null
     * is a table every later query has to special-case.
     */
    private function markPaid(Booking $booking, PaymentGatewayName $gateway): Booking
    {
        DB::transaction(static function () use ($booking, $gateway): void {
            $payment = new Payment;

            $payment->forceFill([
                'uuid' => (string) Str::uuid(),
                'booking_id' => $booking->getKey(),
                'gateway' => $gateway,
                'kind' => PaymentKind::Full,
                'amount_cents' => $booking->total_cents,
                'status' => PaymentStatus::Succeeded,
                'idempotency_key' => (string) Str::uuid(),
                'paid_at' => now(),
            ])->save();
        });

        return ($this->confirmBooking)($booking->refresh());
    }
}
