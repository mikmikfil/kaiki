<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\RecordManualPayment;
use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Enums\PaymentGatewayName;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| BKG-33, OPS-5: money that arrived by hand
|--------------------------------------------------------------------------
|
| The phone call on Tuesday and the cash on Saturday morning. `CreateManualBooking`
| already covers the walk-up who pays as the booking is made; this covers every
| other cash booking, which until now stayed unpaid in the system for ever.
|
| Two failures here are silent and expensive. Setting `paid_cents` instead of
| writing a payment leaves a booking the accountant cannot explain. And writing
| `Full` for a cash top-up on a booking that already paid a deposit online
| doubles the amount every later report reads — which is exactly what a naive
| "mark as paid" does.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Carbon::setTestNow('2026-07-08 10:00:00');
});

function payFor(Booking $booking, int $cents, PaymentGatewayName $gateway = PaymentGatewayName::Cash, ?string $reference = null): Booking
{
    return app(RecordManualPayment::class)($booking, $cents, $gateway, $reference, null);
}

it('writes a payment row rather than editing the booking', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'total_cents' => 12000,
            'paid_cents' => 0,
            'balance_cents' => 12000,
        ]);

        payFor($booking, 12000);

        $payment = Payment::query()->where('booking_id', $booking->getKey())->firstOrFail();

        // PAY-10: the column is derived from the row, not the other way round.
        // A booking whose `paid_cents` says paid with nothing behind it is one
        // the nightly invariant check fails on and nobody can explain.
        expect($payment->amount_cents)->toBe(12000)
            ->and($payment->status)->toBe(PaymentStatus::Succeeded)
            ->and($payment->gateway)->toBe(PaymentGatewayName::Cash)
            ->and($payment->paid_at)->not->toBeNull()
            ->and($booking->refresh()->paid_cents)->toBe(12000)
            ->and($booking->balance_cents)->toBe(0);
    });
});

it('records a top-up as a balance, never as a second full payment', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'total_cents' => 12000,
            'paid_cents' => 0,
            'balance_cents' => 12000,
        ]);

        // A deposit taken online.
        Payment::factory()->for($booking)->create([
            'kind' => PaymentKind::Deposit,
            'amount_cents' => 3000,
            'paid_at' => now(),
        ]);

        $booking->forceFill(['paid_cents' => 3000, 'balance_cents' => 9000])->save();

        payFor($booking, 9000);

        $cash = Payment::query()
            ->where('booking_id', $booking->getKey())
            ->where('gateway', PaymentGatewayName::Cash->value)
            ->firstOrFail();

        // `Full` here would say 9000 was the whole price, and every report that
        // reads `kind` would double the deposit.
        expect($cash->kind)->toBe(PaymentKind::Balance)
            ->and($booking->refresh()->paid_cents)->toBe(12000)
            ->and($booking->balance_cents)->toBe(0);
    });
});

it('refuses more than is owed, and says how much that is', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'total_cents' => 12000,
            'paid_cents' => 0,
            'balance_cents' => 12000,
        ]);

        expect(fn () => payFor($booking, 15000))->toThrow(ValidationException::class);

        // Nothing written. An accepted overpayment would leave a booking that
        // has paid too much with no way to say so.
        expect(Payment::query()->where('booking_id', $booking->getKey())->count())->toBe(0);
    });
});

it('refuses nothing, and refuses a gateway that has a gateway behind it', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'total_cents' => 12000,
            'balance_cents' => 12000,
        ]);

        expect(fn () => payFor($booking, 0))->toThrow(ValidationException::class);

        // A card payment arrives by webhook and can be reconciled against a
        // settlement file. Recording one by hand would put money in the books
        // that nothing corroborates.
        expect(fn () => payFor($booking, 100, PaymentGatewayName::Viva))->toThrow(ValidationException::class);
    });
});

it('refuses to record money against a cancelled booking', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Cancelled,
            'total_cents' => 12000,
            'balance_cents' => 12000,
        ]);

        expect(fn () => payFor($booking, 12000))->toThrow(ValidationException::class);
    });
});

it('confirms a booking that paying it settles, and leaves a part payment pending', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        $half = Booking::factory()->pendingPayment()->create([
            'total_cents' => 12000,
            'paid_cents' => 0,
            'balance_cents' => 12000,
        ]);

        payFor($half, 6000);

        // Half paid in cash is still pending payment, which is what it is.
        expect($half->refresh()->status)->toBe(BookingStatus::PendingPayment)
            ->and($half->balance_cents)->toBe(6000);

        payFor($half, 6000);

        expect($half->refresh()->status)->toBe(BookingStatus::Confirmed)
            ->and($half->balance_cents)->toBe(0);
    });
});

it('does not push an already-confirmed booking through a transition it cannot make', function (): void {
    // The most ordinary case there is — an operator collecting the balance in
    // cash on the morning of the trip — and `confirmed` cannot transition to
    // `confirmed`, so a naive implementation throws on it.
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'total_cents' => 12000,
            'paid_cents' => 4000,
            'balance_cents' => 8000,
            'balance_due_at' => now()->addDays(3),
        ]);

        // The deposit as a **row**, not only as a column. The columns are
        // derived from the rows (PAY-10), so a fixture that sets one without
        // the other is a fixture describing a booking that cannot exist.
        Payment::factory()->for($booking)->create([
            'kind' => PaymentKind::Deposit,
            'amount_cents' => 4000,
            'paid_at' => now()->subDay(),
        ]);

        payFor($booking, 8000, PaymentGatewayName::BankTransfer, 'REF-991');

        expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->balance_cents)->toBe(0)
            // PRC-27.2: a settled booking loses its due date, or the reminder
            // scheduler goes on chasing a guest who has already paid.
            ->and($booking->balance_due_at)->toBeNull();
    });
});

it('leaves a trail, because nothing else corroborates this money', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'total_cents' => 5000,
            'paid_cents' => 0,
            'balance_cents' => 5000,
        ]);

        payFor($booking, 5000, PaymentGatewayName::BankTransfer, 'NBG-4471');

        $entry = AuditLog::query()->where('action', AuditAction::PaymentRecorded->value)->first();

        expect($entry)->not->toBeNull()
            ->and($entry?->subject_label)->toBe($booking->reference)
            ->and($entry?->context['amount_cents'] ?? null)->toBe(5000)
            ->and($entry?->context['reference'] ?? null)->toBe('NBG-4471')
            // ADR-0025 §3: scalars only. A row about money must not carry the
            // guest's name, which is exactly what it would be tempted to carry.
            ->and($entry?->context)->not->toHaveKey('guest_name');
    });
});

it('keeps the payment on the booking it was recorded against, and nobody else', function (): void {
    $mine = Tenant::factory()->create();
    $theirs = Tenant::factory()->create();

    $other = Tenancy::forTenant($theirs, fn (): Booking => Booking::factory()->create([
        'status' => BookingStatus::Confirmed,
        'total_cents' => 5000,
        'paid_cents' => 0,
        'balance_cents' => 5000,
    ]));

    Tenancy::forTenant($mine, function (): void {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'total_cents' => 5000,
            'paid_cents' => 0,
            'balance_cents' => 5000,
        ]);

        payFor($booking, 5000);
    });

    expect(Tenancy::forTenant($theirs, fn (): int => $other->refresh()->paid_cents))->toBe(0);
});
