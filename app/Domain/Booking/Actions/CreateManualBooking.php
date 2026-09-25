<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Availability\Actions\HoldSeats;
use App\Domain\Booking\Data\BookingDraftData;
use App\Domain\Booking\Data\ManualBookingAdjustment;
use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\PaymentGatewayName;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Events\CapacityOverridden;
use App\Events\ManualPaymentRecorded;
use App\Exceptions\IllegalStateTransition;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
 *
 * ## Confirmed on a deposit, or on nothing yet (Mike, 2026-09-25)
 *
 * A phone booking is often not paid in full on the phone: the guest sends a
 * deposit by transfer, or pays everything on the day. Both confirm the booking
 * with an open balance, so it holds its seats and never expires; its due date
 * follows the operator's setting ({@see ComputeBalanceDueAt}), which is none
 * when the balance is collected on board.
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
     * @param  int|null  $depositCents  a deposit taken now, less than the total; confirms with the rest open
     * @param  PaymentGatewayName|null  $depositBy  how the deposit arrived: cash, POS or bank transfer
     * @param  bool  $payOnTheDay  confirm with nothing paid; the guest pays everything on the day
     *
     * @throws ValidationException when the deposit is not a deposit
     */
    public function __invoke(
        BookingDraftData $data,
        ?ManualBookingAdjustment $adjustment = null,
        ?string $capacityOverrideReason = null,
        ?PaymentGatewayName $paidBy = null,
        BookingSource $source = BookingSource::Manual,
        ?int $depositCents = null,
        ?PaymentGatewayName $depositBy = null,
        bool $payOnTheDay = false,
    ): Booking {
        if ($depositCents !== null) {
            // Before anything is written: a refused deposit leaves no draft.
            $this->guardDeposit($depositCents, $depositBy);
        }

        // The source is this Action's to set, not the caller's. A manual
        // booking that arrived claiming to be a widget booking would be
        // invisible in every report that separates the two — and
        // `BookingCreateRequest` rejects `manual` from the public API for the
        // mirror-image reason.
        $data = $this->asManual($data, $capacityOverrideReason !== null, $source);

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
        } elseif ($depositCents !== null) {
            $booking = $this->takeDeposit($booking, $depositCents, $depositBy ?? PaymentGatewayName::Cash);
        } elseif ($payOnTheDay) {
            $booking = $this->confirmUnpaid($booking);
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
    private function asManual(BookingDraftData $data, bool $overriding, BookingSource $source): BookingDraftData
    {
        return new BookingDraftData(
            product: $data->product,
            date: $data->date,
            guestName: $data->guestName,
            guestEmail: $data->guestEmail,
            guestPhone: $data->guestPhone,
            guestCountry: $data->guestCountry,
            locale: $data->locale,
            // Manual, or the quay (2026-09-24): never a guest-initiated source.
            source: $source === BookingSource::Quay ? BookingSource::Quay : BookingSource::Manual,
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
            originUrl: $data->originUrl,
            departure: $data->departure,
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
        // Refused before the money is written, not after. A trip sold on
        // request makes a `quote_requested` booking, which cannot be
        // confirmed; the payment used to be saved first and the confirmation
        // to throw, leaving a quote request holding a paid-in-full payment
        // that every revenue figure then counted (the demo showed €8,294 of
        // it on 25/9).
        if (! $booking->status->canTransitionTo(BookingStatus::Confirmed)) {
            throw IllegalStateTransition::forBooking($booking->status, BookingStatus::Confirmed);
        }

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

    /**
     * A deposit taken on the phone or at the desk, and the booking confirmed
     * with the rest still open (2026-09-25).
     *
     * A `Deposit` row, because here it is one: the operator agreed with the
     * guest how much now and how much later. {@see ConfirmBooking} then derives
     * the money columns from the rows (PAY-10) and the due date from the
     * operator's setting, as it does on every other confirmation.
     */
    private function takeDeposit(Booking $booking, int $depositCents, PaymentGatewayName $gateway): Booking
    {
        if (! $booking->status->canTransitionTo(BookingStatus::Confirmed)) {
            throw IllegalStateTransition::forBooking($booking->status, BookingStatus::Confirmed);
        }

        if ($depositCents >= $booking->total_cents) {
            // A "deposit" of the whole price is the whole price. The total is
            // only known once the engine has priced the draft, so this is not a
            // refusal: refusing here would leave a draft holding the seats.
            return $this->markPaid($booking, $gateway);
        }

        $payment = DB::transaction(static function () use ($booking, $depositCents, $gateway): Payment {
            $payment = new Payment;

            $payment->forceFill([
                'uuid' => (string) Str::uuid(),
                'booking_id' => $booking->getKey(),
                'gateway' => $gateway,
                'kind' => PaymentKind::Deposit,
                'amount_cents' => $depositCents,
                'status' => PaymentStatus::Succeeded,
                'idempotency_key' => (string) Str::uuid(),
                'paid_at' => now(),
            ])->save();

            $booking->forceFill(['deposit_cents' => $depositCents])->save();

            return $payment;
        });

        $booking = ($this->confirmBooking)($booking->refresh());

        // The same audit row a payment recorded later leaves (BKG-33, AUD-1).
        ManualPaymentRecorded::dispatch($booking, $depositCents, $gateway, $payment->uuid, null);

        return $booking;
    }

    /**
     * Confirmed with nothing paid: the guest pays on the day (2026-09-25).
     *
     * No payment row, because no money has arrived. The booking holds its
     * seats as a confirmed one and so never expires; the whole total is the
     * balance, collected on board or chased by the reminders, as the operator
     * has set.
     */
    private function confirmUnpaid(Booking $booking): Booking
    {
        if (! $booking->status->canTransitionTo(BookingStatus::Confirmed)) {
            throw IllegalStateTransition::forBooking($booking->status, BookingStatus::Confirmed);
        }

        $booking->forceFill(['deposit_cents' => 0])->save();

        return ($this->confirmBooking)($booking->refresh());
    }

    /** @throws ValidationException */
    private function guardDeposit(int $depositCents, ?PaymentGatewayName $gateway): void
    {
        if ($depositCents < 1) {
            throw ValidationException::withMessages([
                'deposit_amount' => [trans('bookings.payment.not_positive')],
            ]);
        }

        if ($gateway !== null && ! in_array($gateway, [PaymentGatewayName::Cash, PaymentGatewayName::Pos, PaymentGatewayName::BankTransfer], true)) {
            throw ValidationException::withMessages([
                'deposit_by' => [trans('bookings.payment.wrong_gateway')],
            ]);
        }
    }
}
