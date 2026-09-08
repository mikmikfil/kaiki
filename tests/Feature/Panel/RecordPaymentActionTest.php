<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\PaymentGatewayName;
use App\Enums\Role;
use App\Filament\App\Resources\BookingResource\Pages\ViewBooking;
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
| reach it, that crew cannot, and that the button is not there on a booking with
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

it('gives crew no way to record money', function (): void {
    // TEN-8. Crew stand on the quay with a passenger list; they do not put
    // entries in the operator's books.
    $crew = OperatorUser::withRole(Role::Crew);

    /*
     * Deliberately a booking on **today's** departure.
     *
     * `CrewWindow` now narrows the bookings list to today and tomorrow, so a
     * booking on any other date gives a crew member a 404 before the action is
     * ever reached — and this test would pass while proving nothing about the
     * button. The row filter and the action gate are two separate guarantees
     * and this file owns the second: a booking a crew member *can* open, with a
     * balance owing, and no way to settle it.
     */
    $booking = Tenancy::forTenant($crew->tenant, function (): Booking {
        $departure = Departure::factory()
            ->at(Carbon::now('Europe/Athens')->toDateString(), '09:00')
            ->create();

        return Booking::factory()->for($departure)->create([
            'status' => BookingStatus::Confirmed,
            'total_cents' => 12000,
            'paid_cents' => 0,
            'balance_cents' => 12000,
        ]);
    });

    bookingPageAs($crew, $booking)->assertActionHidden('record_payment');
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
