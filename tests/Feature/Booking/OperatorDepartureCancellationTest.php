<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\CancelDeparture;
use App\Enums\DepartureCancelReason;
use App\Enums\NotificationChannel;
use App\Enums\NotificationTemplate;
use App\Models\Departure;
use App\Models\NotificationLog;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Booking\CancellationScenario;

/*
|--------------------------------------------------------------------------
| The operator calls a departure off (product owner, 2026-09-17)
|--------------------------------------------------------------------------
|
| Two things were wrong. The refund followed the guest's own cancellation
| policy, so a departure cancelled close to the date could refund nothing; and
| nobody was told, because the cancellation email had a template and no sender.
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

it('refunds everything paid when the operator cancels, whatever the guest policy says', function (): void {
    // A policy that refunds nothing inside two days, and a departure that is
    // close: the guest's own cancellation would get 0%.
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000, ladder: [15 => 50, 7 => 0]);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(CancelDeparture::class)(
            departure: Departure::query()->findOrFail($booking->departure_id),
            reason: DepartureCancelReason::MinPax,
        );

        expect($booking->refresh()->refunded_cents)->toBe(10000);
    });
})->group('fast');

it('emails each guest that their booking is cancelled', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(CancelDeparture::class)(
            departure: Departure::query()->findOrFail($booking->departure_id),
            reason: DepartureCancelReason::Operator,
        );

        expect(NotificationLog::alreadySent($booking->getKey(), NotificationTemplate::BookingCancelled, NotificationChannel::Mail))
            ->toBeTrue();
    });
})->group('fast');

it('does not send the cancellation email for weather, which asks the guest to choose instead', function (): void {
    [$tenant, $booking] = CancellationScenario::make(paidCents: 10000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(CancelDeparture::class)(
            departure: Departure::query()->findOrFail($booking->departure_id),
            reason: DepartureCancelReason::Weather,
        );

        expect(NotificationLog::alreadySent($booking->getKey(), NotificationTemplate::BookingCancelled, NotificationChannel::Mail))
            ->toBeFalse();
    });
})->group('fast');
