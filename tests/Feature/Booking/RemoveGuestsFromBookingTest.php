<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Actions\RemoveGuestsFromBooking;
use App\Enums\BookingStatus;
use App\Enums\CancelledBy;
use App\Enums\CancelReason;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Events\BookingGuestsRemoved;
use App\Filament\App\Resources\BookingResource\Pages\ViewBooking;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Booking\CancellationScenario;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| Taking people off a booking (product owner, 2026-09-17)
|--------------------------------------------------------------------------
|
| Four booked, three coming. Each comes off at the price they were quoted,
| their seat goes back, what was paid beyond the new total comes back, and a
| later cancellation still refunds what is left.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    CancellationScenario::fakeGatewayResponses();
    Mail::fake();

    Carbon::setTestNow('2026-06-20 00:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Three adults at €50 and a child at €30, paid in full, on a departure that
 * has their four seats sold.
 *
 * @return array{0: Tenant, 1: Booking}
 */
function familyBooking(): array
{
    [$tenant, $booking] = CancellationScenario::make(paidCents: 18000, ladder: [0 => 100]);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill([
            'pax_total' => 4,
            'pax_capacity_total' => 4,
            'pax_breakdown' => [
                ['code' => 'adult', 'label' => ['el' => 'Ενήλικας', 'en' => 'Adult'], 'qty' => 3, 'counts_toward_capacity' => true, 'unit_price_cents' => 5000, 'total_cents' => 15000],
                ['code' => 'child', 'label' => ['el' => 'Παιδί', 'en' => 'Child'], 'qty' => 1, 'counts_toward_capacity' => true, 'unit_price_cents' => 3000, 'total_cents' => 3000],
            ],
            'subtotal_cents' => 18000,
            'total_cents' => 18000,
            'deposit_cents' => 18000,
        ])->save();

        Departure::query()->whereKey($booking->departure_id)->update(['seats_sold' => 4]);
    });

    return [$tenant, $booking->refresh()];
}

it('takes one person off at their own price, gives the seat back and refunds the difference', function (): void {
    [$tenant, $booking] = familyBooking();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $result = app(RemoveGuestsFromBooking::class)($booking, ['adult' => 1]);
        $fresh = $booking->refresh();

        expect($result['removed'])->toBe(1)
            ->and($result['refund_cents'])->toBe(5000)
            ->and($fresh->total_cents)->toBe(13000)
            ->and($fresh->pax_total)->toBe(3)
            ->and($fresh->pax_capacity_total)->toBe(3)
            ->and($fresh->pax_breakdown[0]['qty'])->toBe(2)
            ->and(Departure::query()->findOrFail($booking->departure_id)->seats_sold)->toBe(3)
            // `sum()` is a string on MySQL and a number on SQLite.
            ->and((int) Payment::query()->where('booking_id', $booking->getKey())->where('kind', PaymentKind::Refund->value)->sum('amount_cents'))->toBe(5000)
            ->and($fresh->paid_cents)->toBe(13000)
            ->and($fresh->status)->toBe(BookingStatus::Confirmed);
    });
})->group('fast');

it('still refunds what is left when the smaller booking is cancelled later', function (): void {
    [$tenant, $booking] = familyBooking();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(RemoveGuestsFromBooking::class)($booking, ['child' => 1]);

        app(CancelBooking::class)(
            booking: $booking->refresh(),
            reason: CancelReason::GuestRequest,
            by: CancelledBy::Guest,
        );

        $refunded = Payment::query()
            ->where('booking_id', $booking->getKey())
            ->where('kind', PaymentKind::Refund->value)
            ->where('status', PaymentStatus::Succeeded->value)
            ->sum('amount_cents');

        expect((int) $refunded)->toBe(18000);
    });
})->group('fast');

it('refuses to remove everyone, or to leave a child without an adult', function (): void {
    [$tenant, $booking] = familyBooking();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(fn () => app(RemoveGuestsFromBooking::class)($booking, ['adult' => 3, 'child' => 1]))
            ->toThrow(ValidationException::class);

        $booking->product->ageBands()->create([
            'code' => 'child', 'label' => ['el' => 'Παιδί', 'en' => 'Child'], 'min_age' => 3, 'max_age' => 11,
            'counts_toward_capacity' => true, 'is_base' => false, 'requires_adult' => true, 'sort_order' => 2,
        ]);
        $booking->product->ageBands()->create([
            'code' => 'adult', 'label' => ['el' => 'Ενήλικας', 'en' => 'Adult'], 'min_age' => 12, 'max_age' => null,
            'counts_toward_capacity' => true, 'is_base' => true, 'requires_adult' => false, 'sort_order' => 1,
        ]);

        expect(fn () => app(RemoveGuestsFromBooking::class)($booking->refresh(), ['adult' => 3]))
            ->toThrow(ValidationException::class);

        expect($booking->refresh()->pax_total)->toBe(4);
    });
})->group('fast');

it('runs from the booking page and tells the guest', function (): void {
    Event::fake([BookingGuestsRemoved::class]);
    [$tenant, $booking] = familyBooking();
    $owner = OperatorUser::withRole(Role::Owner, $tenant);
    tenancy()->initialize($tenant);

    Livewire::actingAs($owner)
        ->test(ViewBooking::class, ['record' => $booking->getRouteKey()])
        ->assertActionVisible('remove_guests')
        ->callAction('remove_guests', data: ['remove' => ['adult' => 1, 'child' => 0]])
        ->assertHasNoActionErrors();

    expect($booking->refresh()->pax_total)->toBe(3);
    Event::assertDispatched(BookingGuestsRemoved::class, static fn (BookingGuestsRemoved $event): bool => $event->removed === 1);
})->group('fast');
