<?php

declare(strict_types=1);

use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Enums\PaymentGatewayName;
use App\Enums\Role;
use App\Filament\App\Resources\BookingResource\Pages\ViewBooking;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| OPS-5: the screen an operator uses when the cash arrives
|--------------------------------------------------------------------------
|
| `RecordManualPaymentTest` proves the action. This proves the operator can
| reach it, that crew reach only the whole balance (since 2026-09-25), and that the button is not there on a booking with
| nothing owing — a "record a payment" button on a settled booking is an
| invitation to record a second one.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Carbon::setTestNow('2026-07-08 10:00:00');
});

function bookingPageAs(User $user, Booking $booking): Testable
{
    tenancy()->initialize($user->tenant);

    // `Booking` does not use `HasUuid`, so its panel route key is the primary
    // key — unlike the catalogue resources. Written out rather than assumed,
    // because passing the uuid here fails as "no query results", which reads
    // like a tenancy problem and is not one.
    return Livewire::actingAs($user)->test(ViewBooking::class, ['record' => $booking->getRouteKey()]);
}

it('records the cash an operator collected on the boat', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    $booking = Tenancy::forTenant($user->tenant, fn (): Booking => Booking::factory()->create([
        'status' => BookingStatus::Confirmed,
        'total_cents' => 12000,
        'paid_cents' => 0,
        'balance_cents' => 12000,
    ]));

    bookingPageAs($user, $booking)
        ->callAction('record_payment', [
            'amount' => 120,
            'gateway' => PaymentGatewayName::Cash->value,
            'reference' => 'Receipt 41',
        ])
        ->assertHasNoActionErrors();

    Tenancy::forTenant($user->tenant, function () use ($booking): void {
        expect($booking->refresh()->balance_cents)->toBe(0)
            ->and(Payment::query()->where('booking_id', $booking->getKey())->value('gateway_ref'))->toBe('Receipt 41');
    });
});

it('turns euros into cents without losing one', function (): void {
    // `(int) (1.15 * 100)` is 114. A cent per booking, silently, for ever.
    $user = OperatorUser::withRole(Role::Owner);

    $booking = Tenancy::forTenant($user->tenant, fn (): Booking => Booking::factory()->create([
        'status' => BookingStatus::Confirmed,
        'total_cents' => 115,
        'paid_cents' => 0,
        'balance_cents' => 115,
    ]));

    bookingPageAs($user, $booking)
        ->callAction('record_payment', [
            'amount' => 1.15,
            'gateway' => PaymentGatewayName::Cash->value,
        ])
        ->assertHasNoActionErrors();

    expect(Tenancy::forTenant($user->tenant, fn (): int => $booking->refresh()->paid_cents))->toBe(115);
});

it('hides the button when there is nothing owing', function (): void {
    // A "record a payment" button on a settled booking is an invitation to
    // record a second one.
    $user = OperatorUser::withRole(Role::Owner);

    $booking = Tenancy::forTenant($user->tenant, fn (): Booking => Booking::factory()->create([
        'status' => BookingStatus::Confirmed,
        'total_cents' => 12000,
        'paid_cents' => 12000,
        'balance_cents' => 0,
    ]));

    bookingPageAs($user, $booking)->assertActionHidden('record_payment');
});

/**
 * A booking a crew member *can* open, with a balance owing.
 *
 * Deliberately on **today's** departure. `CrewWindow` narrows the bookings list
 * to today and tomorrow, so a booking on any other date gives a crew member a
 * 404 before the action is ever reached — and a test would pass while proving
 * nothing about the button.
 */
function crewBookingOwing(User $crew): Booking
{
    return Tenancy::forTenant($crew->tenant, function (): Booking {
        $departure = Departure::factory()
            ->at(Carbon::now('Europe/Athens')->toDateString(), '09:00')
            ->create();

        $booking = Booking::factory()->for($departure)->create([
            'status' => BookingStatus::Confirmed,
            'starts_at_utc' => $departure->starts_at_utc,
            'ends_at_utc' => $departure->ends_at_utc,
            'total_cents' => 12000,
            'deposit_cents' => 3600,
            'paid_cents' => 3600,
            'balance_cents' => 8400,
        ]);

        // The deposit paid online. `paid_cents` is derived from payment rows
        // (PAY-10), so the row has to exist for the balance to stay 8400.
        Payment::factory()->deposit(3600)->create(['booking_id' => $booking->getKey()]);

        return $booking;
    });
}

it('gives crew the open balance to collect, and no other amount', function (): void {
    // Reversed on 2026-09-25 (Mike, plan Β2). Until then this test was «gives
    // crew no way to record money»: TEN-8 kept crew out of the books entirely.
    // Operators who collect the balance on the boat need crew to say «paid», so
    // crew now get «Πληρώθηκε» — the whole balance, cash or POS — and still not
    // «Καταχώριση πληρωμής», which takes any amount and a bank transfer.
    $crew = OperatorUser::withRole(Role::Crew);
    $booking = crewBookingOwing($crew);

    bookingPageAs($crew, $booking)
        ->assertActionHidden('record_payment')
        ->assertActionVisible('collect_balance')
        ->callAction('collect_balance', ['gateway' => PaymentGatewayName::Pos->value])
        ->assertHasNoActionErrors();

    Tenancy::forTenant($crew->tenant, function () use ($booking, $crew): void {
        $payment = Payment::query()->where('booking_id', $booking->getKey())->where('gateway', PaymentGatewayName::Pos->value)->sole();

        expect($booking->refresh()->balance_cents)->toBe(0)
            ->and($payment->amount_cents)->toBe(8400)
            ->and($payment->gateway)->toBe(PaymentGatewayName::Pos)
            // Who pressed it: the one corroboration cash on a boat has.
            ->and(AuditLog::query()->where('action', AuditAction::PaymentRecorded->value)->value('user_id'))
            ->toBe($crew->getKey());
    });
});

it('shows an owner the full form, not the crew button', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $booking = crewBookingOwing($owner);

    bookingPageAs($owner, $booking)
        ->assertActionVisible('record_payment')
        ->assertActionHidden('collect_balance');
});

it('still gives crew no refund and no cancel', function (): void {
    $crew = OperatorUser::withRole(Role::Crew);
    $booking = crewBookingOwing($crew);

    // A refund is made by cancelling or by removing people, and both stay
    // behind ManageBookings.
    bookingPageAs($crew, $booking)
        ->assertActionHidden('cancel_booking')
        ->assertActionHidden('remove_guests')
        ->assertActionHidden('record_payment');
});

it('gives crew no way to record money on a booking outside the boarding window', function (): void {
    // TEN-8 as it was, for everything that is not today's boat.
    $crew = OperatorUser::withRole(Role::Crew);

    $booking = Tenancy::forTenant($crew->tenant, function (): Booking {
        $departure = Departure::factory()
            ->at(Carbon::now('Europe/Athens')->addDay()->toDateString(), '20:00')
            ->create();

        return Booking::factory()->for($departure)->create([
            'status' => BookingStatus::Confirmed,
            'starts_at_utc' => $departure->starts_at_utc,
            'ends_at_utc' => $departure->ends_at_utc,
            'total_cents' => 12000,
            'paid_cents' => 0,
            'balance_cents' => 12000,
        ]);
    });

    bookingPageAs($crew, $booking)
        ->assertActionHidden('record_payment')
        ->assertActionHidden('collect_balance');
});

it('refuses an amount larger than the balance, on the form', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    $booking = Tenancy::forTenant($user->tenant, fn (): Booking => Booking::factory()->create([
        'status' => BookingStatus::Confirmed,
        'total_cents' => 12000,
        'paid_cents' => 0,
        'balance_cents' => 12000,
    ]));

    bookingPageAs($user, $booking)
        ->callAction('record_payment', [
            'amount' => 500,
            'gateway' => PaymentGatewayName::Cash->value,
        ]);

    expect(Tenancy::forTenant($user->tenant, fn (): int => Payment::query()->where('booking_id', $booking->getKey())->count()))
        ->toBe(0);
});
