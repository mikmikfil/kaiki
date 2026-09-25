<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\AcceptQuote;
use App\Domain\Booking\Actions\BuildQuote;
use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Actions\ExpireQuotes;
use App\Domain\Booking\Actions\SendQuote;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\CancelledBy;
use App\Enums\CancelReason;
use App\Enums\QuoteStatus;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Quote;
use App\Models\VesselBlock;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\get;

use Tests\Support\Booking\QuoteScenario;

/*
|--------------------------------------------------------------------------
| Audit 2: a quote goes only to a booking waiting for one, holds only a
| free boat, and closes with its booking
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Carbon::setTestNow('2026-06-01 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('refuses to hold a boat that is already chartered at that time', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($booking, $quote): void {
        Booking::factory()->perVessel()->create([
            'product_id' => $booking->product_id,
            'vessel_id' => $booking->vessel_id,
            'mode' => BookingMode::PerVessel,
            'status' => BookingStatus::Confirmed,
            'local_date' => $booking->local_date->toDateString(),
            'local_time' => (string) $booking->local_time,
            'starts_at_utc' => $booking->starts_at_utc,
            'ends_at_utc' => $booking->ends_at_utc,
        ]);

        expect(fn () => app(SendQuote::class)($quote, holdVessel: true))
            ->toThrow(RuntimeException::class, (string) __('quotes.quote.actions.send.vessel_taken'));

        expect($quote->refresh()->status)->toBe(QuoteStatus::Draft)
            ->and(VesselBlock::query()->count())->toBe(0);
    });
})->group('fast');

it('refuses to hold a boat over a sailing with seats sold, and leaves it on sale', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($booking, $quote): void {
        $sailing = Departure::factory()->create([
            'vessel_id' => $booking->vessel_id,
            'starts_at_utc' => $booking->starts_at_utc,
            'ends_at_utc' => $booking->ends_at_utc,
            'local_date' => $booking->local_date->toDateString(),
            'seats_sold' => 8,
        ]);

        expect(fn () => app(SendQuote::class)($quote, holdVessel: true))
            ->toThrow(RuntimeException::class);

        expect($sailing->refresh()->is_blocked)->toBeFalse()
            ->and(VesselBlock::query()->count())->toBe(0);
    });
})->group('fast');

it('still sends without the hold on a busy boat', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($booking, $quote): void {
        Departure::factory()->create([
            'vessel_id' => $booking->vessel_id,
            'starts_at_utc' => $booking->starts_at_utc,
            'ends_at_utc' => $booking->ends_at_utc,
            'local_date' => $booking->local_date->toDateString(),
            'seats_sold' => 8,
        ]);

        app(SendQuote::class)($quote);

        expect($quote->refresh()->status)->toBe(QuoteStatus::Sent);
    });
})->group('fast');

it('refuses to send a quote for a booking no longer waiting for one', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($booking, $quote): void {
        $booking->forceFill(['status' => BookingStatus::PendingPayment])->save();

        expect(fn () => app(SendQuote::class)($quote))
            ->toThrow(RuntimeException::class, (string) __('quotes.quote.actions.send.not_awaiting'));

        expect($quote->refresh()->status)->toBe(QuoteStatus::Draft);
    });
})->group('fast');

it('closes the other open versions when the guest accepts one', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    $revision = Tenancy::forTenant($tenant, function () use ($booking, $quote): Quote {
        app(SendQuote::class)($quote);

        // «Αναθεώρηση»: a draft v2, not yet sent.
        return app(BuildQuote::class)($booking->refresh());
    });

    Tenancy::forTenant($tenant, function () use ($quote, $revision): void {
        app(AcceptQuote::class)($quote->refresh());

        expect($quote->refresh()->status)->toBe(QuoteStatus::Accepted)
            ->and($revision->refresh()->status)->toBe(QuoteStatus::Expired);
    });
})->group('fast');

it('does not expire an accepted booking when a later version lapses', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($booking, $quote): void {
        app(SendQuote::class)($quote);

        // A version left open from before the fix: sent, and now lapsed, on a
        // booking the guest already accepted.
        $booking->refresh()->forceFill(['status' => BookingStatus::PendingPayment])->save();
        $quote->refresh()->forceFill(['valid_until' => now()->subMinute()])->save();
    });

    app(ExpireQuotes::class)();

    Tenancy::forTenant($tenant, function () use ($booking, $quote): void {
        expect($quote->refresh()->status)->toBe(QuoteStatus::Expired)
            ->and($booking->refresh()->status)->toBe(BookingStatus::PendingPayment);
    });
})->group('fast');

it('closes the open quote when its booking is cancelled', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($booking, $quote): void {
        app(SendQuote::class)($quote);

        app(CancelBooking::class)($booking->refresh(), CancelReason::Operator, CancelledBy::Operator);

        expect($quote->refresh()->status)->toBe(QuoteStatus::Expired);
    });

    get('/q/' . $quote->refresh()->quote_token)
        ->assertOk()
        ->assertDontSee(__('guest.quote.accept'));
})->group('fast');

it('offers no «Αποδοχή» on a quote whose booking is not waiting for it', function (): void {
    [$tenant, $booking, $quote] = QuoteScenario::drafted();

    Tenancy::forTenant($tenant, function () use ($booking, $quote): void {
        app(SendQuote::class)($quote);

        // The quote is still `sent`; the booking is not.
        $booking->refresh()->forceFill(['status' => BookingStatus::Cancelled])->save();
    });

    get('/q/' . $quote->refresh()->quote_token)
        ->assertOk()
        ->assertDontSee(__('guest.quote.accept'));
})->group('fast');
