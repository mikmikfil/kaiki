<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\CancelDeparture;
use App\Enums\BookingStatus;
use App\Enums\DepartureCancelReason;
use App\Enums\NotificationTemplate;
use App\Events\WeatherChoiceRequested;
use App\Mail\GuestMail;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Booking\CancellationScenario;

/*
|--------------------------------------------------------------------------
| A cancelled sailing writes only to people who had booked it (2026-09-25)
|--------------------------------------------------------------------------
|
| A departure carries more than bookings: a draft someone left in the widget,
| a quote request, a checkout nobody paid. Cancelling the sailing cancels
| those too, quietly. «Η κράτησή σας ακυρώθηκε» and the weather choice go only
| to a booking that was confirmed or paid something.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    CancellationScenario::fakeGatewayResponses();
    Carbon::setTestNow('2026-06-20 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A confirmed booking, plus a live draft and a quote request on the same
 * sailing, both with an email and neither ever booked.
 *
 * @return array{0: Tenant, 1: Booking, 2: Booking, 3: Booking}
 */
function sailingWithStrays(): array
{
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);

    [$draft, $quote] = Tenancy::forTenant($tenant, function () use ($booking): array {
        $departure = Departure::query()->findOrFail($booking->departure_id);

        $draft = Booking::factory()->holding($departure)->create(['guest_email' => 'walked-away@example.gr']);
        $quote = Booking::factory()->forDeparture($departure)->create([
            'status' => BookingStatus::QuoteRequested,
            'confirmed_at' => null,
            'paid_cents' => 0,
            'guest_email' => 'just-asking@example.gr',
        ]);

        return [$draft, $quote];
    });

    return [$tenant, $booking, $draft, $quote];
}

it('asks only the real booking about the weather', function (): void {
    Event::fake([WeatherChoiceRequested::class]);

    [$tenant, $booking, $draft, $quote] = sailingWithStrays();

    Tenancy::forTenant($tenant, function () use ($booking, $draft, $quote): void {
        app(CancelDeparture::class)(
            departure: Departure::query()->findOrFail($booking->departure_id),
            reason: DepartureCancelReason::Weather,
        );

        expect($booking->refresh()->weather_choice_due_at)->not->toBeNull()
            // Cancelled all the same, but never asked.
            ->and($draft->refresh()->status)->toBe(BookingStatus::Cancelled)
            ->and($draft->weather_choice_due_at)->toBeNull()
            ->and($quote->refresh()->status)->toBe(BookingStatus::Cancelled)
            ->and($quote->weather_choice_due_at)->toBeNull();
    });

    Event::assertDispatchedTimes(WeatherChoiceRequested::class, 1);
    Event::assertDispatched(
        WeatherChoiceRequested::class,
        fn (WeatherChoiceRequested $event): bool => $event->bookingId === $booking->getKey(),
    );
})->group('fast');

it('emails «ακυρώθηκε» only to the real booking', function (): void {
    Mail::fake();

    [$tenant, $booking, $draft, $quote] = sailingWithStrays();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(CancelDeparture::class)(
            departure: Departure::query()->findOrFail($booking->departure_id),
            reason: DepartureCancelReason::Operator,
        );
    });

    $sent = Mail::sent(GuestMail::class, static fn (GuestMail $mail): bool => $mail->template === NotificationTemplate::BookingCancelled);

    expect($sent)->toHaveCount(1)
        ->and($sent->first()?->booking->getKey())->toBe($booking->getKey());

    Tenancy::forTenant($tenant, function () use ($draft, $quote): void {
        expect($draft->refresh()->status)->toBe(BookingStatus::Cancelled)
            ->and($quote->refresh()->status)->toBe(BookingStatus::Cancelled);
    });
})->group('fast');
