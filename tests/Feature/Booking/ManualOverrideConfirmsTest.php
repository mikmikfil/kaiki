<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\CreateManualBooking;
use App\Domain\Booking\Actions\StartCheckout;
use App\Domain\Booking\Data\BookingDraftData;
use App\Enums\BookingStatus;
use App\Enums\PaymentGatewayName;
use App\Exceptions\CapacityExceeded;
use App\Models\Booking;
use App\Models\Departure;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\postJson;

use Tests\Support\Api\BookingApiScenario;
use Tests\Support\Api\CatalogRequest;

/*
|--------------------------------------------------------------------------
| BKG-32's override becomes a booking (audit, 2026-09-25)
|--------------------------------------------------------------------------
|
| The override let the hold through — three on a sailing of two — and then the
| confirmation refused to move those three seats from held to sold, because
| the counter update asked for room the hold already occupied. The operator
| saw an error page, the cash payment stayed on a draft, and the same happened
| on a payment link. Seats wholly held now move without asking for room again.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-25 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @param array<string, mixed> $fixture */
function overrideDraft(array $fixture, int $adults): BookingDraftData
{
    return new BookingDraftData(
        product: $fixture['product'],
        date: $fixture['departure']->local_date->copy(),
        guestName: 'Γιώργος Παπαδάκης',
        guestEmail: 'giorgos@example.gr',
        paxByCode: ['adult' => $adults],
        termsAcceptedAt: Carbon::now(),
        departure: $fixture['departure'],
    );
}

it('confirms an overridden booking paid in cash, three on a sailing of two', function (): void {
    $fixture = BookingApiScenario::bookable(capacity: 2);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $booking = app(CreateManualBooking::class)(
            overrideDraft($fixture, 3),
            capacityOverrideReason: 'Οικογένεια, χωράνε',
            paidBy: PaymentGatewayName::Cash,
        );

        $departure = Departure::query()->findOrFail($fixture['departure']->getKey());

        expect($booking->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->paid_cents)->toBe($booking->total_cents)
            ->and($departure->seats_sold)->toBe(3)
            ->and($departure->seats_held)->toBe(0);
    });
})->group('fast');

it('confirms an overridden booking paid on the day', function (): void {
    $fixture = BookingApiScenario::bookable(capacity: 2);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $booking = app(CreateManualBooking::class)(
            overrideDraft($fixture, 3),
            capacityOverrideReason: 'Οικογένεια, χωράνε',
            payOnTheDay: true,
        );

        expect($booking->status)->toBe(BookingStatus::Confirmed)
            ->and(Departure::query()->findOrFail($fixture['departure']->getKey())->seats_sold)->toBe(3);
    });
})->group('fast');

it('takes an overridden booking to the gateway on a payment link', function (): void {
    $fixture = BookingApiScenario::bookable(capacity: 2);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $booking = app(CreateManualBooking::class)(
            overrideDraft($fixture, 3),
            capacityOverrideReason: 'Οικογένεια, χωράνε',
        );

        $result = app(StartCheckout::class)($booking);

        expect($result['booking']->status)->toBe(BookingStatus::PendingPayment)
            ->and(Departure::query()->findOrFail($fixture['departure']->getKey())->seats_sold)->toBe(3);
    });
})->group('fast');

it('does not strand another guest whose seats were held before the override', function (): void {
    $fixture = BookingApiScenario::bookable(capacity: 2);

    // A guest holds both seats online.
    $uuid = (string) postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    )->assertCreated()->json('data.uuid');

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture, $uuid): void {
        // The operator squeezes one more on by phone: three held on two.
        app(CreateManualBooking::class)(overrideDraft($fixture, 1), capacityOverrideReason: 'Ο παππούς');

        expect(Departure::query()->findOrFail($fixture['departure']->getKey())->seats_held)->toBe(3);

        // The guest pays for the seats they already hold.
        $result = app(StartCheckout::class)(Booking::query()->where('uuid', $uuid)->sole());

        expect($result['booking']->status)->toBe(BookingStatus::PendingPayment)
            ->and(Departure::query()->findOrFail($fixture['departure']->getKey())->seats_sold)->toBe(2);
    });
})->group('fast');

it('still refuses seats that are not held when there is no room', function (): void {
    $fixture = BookingApiScenario::bookable(capacity: 2);

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture): void {
        $booking = app(CreateManualBooking::class)(overrideDraft($fixture, 2));

        // Its hold lapsed and the sweeper released it; somebody else sold out.
        $booking->forceFill(['hold_expires_at' => Carbon::now()->subMinute()])->save();
        Departure::query()->whereKey($fixture['departure']->getKey())->update(['seats_held' => 0, 'seats_sold' => 2]);

        expect(fn () => app(StartCheckout::class)($booking->refresh()))
            ->toThrow(CapacityExceeded::class);
    });
})->group('fast');
