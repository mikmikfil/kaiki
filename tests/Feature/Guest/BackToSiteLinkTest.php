<?php

declare(strict_types=1);

use App\Domain\Hosted\Support\HostedUrl;
use App\Enums\BookingStatus;
use App\Enums\HostedSiteMode;
use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\get;

use Tests\Support\Booking\GuestPageScenario;
use Tests\Support\Payments\WebhookScenario;

/*
|--------------------------------------------------------------------------
| «← Επιστροφή στην ιστοσελίδα» on `/c/` and `/b/` (2026-09-11)
|--------------------------------------------------------------------------
|
| Three answers, in order: the page the guest came from; else the operator's
| hosted home page, when it is served; else no link. The third matters as much
| as the first — a bookings-only operator's home page is a 404 (ADR-0029), and
| a link to a 404 is worse than none.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    WebhookScenario::fakeGatewayResponses();
    Carbon::setTestNow('2026-06-20 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A booking in the given state, with the operator's site mode set.
 *
 * @return array{0: Tenant, 1: Booking}
 */
function backLinkBooking(
    BookingStatus $status,
    ?string $originUrl = null,
    HostedSiteMode $mode = HostedSiteMode::Full,
): array {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 12000);

    $tenant->forceFill(['hosted_site_mode' => $mode])->save();

    Tenancy::forTenant($tenant, static function () use ($booking, $status, $originUrl): void {
        $booking->forceFill([
            'status' => $status,
            'origin_url' => $originUrl,
            'hold_expires_at' => $status === BookingStatus::Draft ? now()->addMinutes(15) : null,
            'paid_cents' => $status === BookingStatus::Draft ? 0 : 12000,
        ])->save();
    });

    return [$tenant->refresh(), $booking->refresh()];
}

it('links the checkout page back to the page the guest came from', function (): void {
    [, $booking] = backLinkBooking(BookingStatus::Draft, 'https://aegean-blue.example/trips/sunset');

    get('/c/' . $booking->manage_token)
        ->assertOk()
        ->assertSee(__('guest.back_to_site'), escape: false)
        ->assertSee('href="https://aegean-blue.example/trips/sunset"', escape: false);
})->group('fast');

it('links the booking page back to the page the guest came from', function (): void {
    [, $booking] = backLinkBooking(BookingStatus::Confirmed, 'https://aegean-blue.example/trips/sunset');

    get('/b/' . $booking->manage_token)
        ->assertOk()
        ->assertSee(__('guest.back_to_site'), escape: false)
        ->assertSee('href="https://aegean-blue.example/trips/sunset"', escape: false);
})->group('fast');

it('falls back to the operator\'s hosted home page when nothing was sent', function (): void {
    [$tenant, $draft] = backLinkBooking(BookingStatus::Draft);

    get('/c/' . $draft->manage_token)
        ->assertOk()
        ->assertSee('href="' . HostedUrl::operator($tenant) . '"', escape: false);

    [$other, $confirmed] = backLinkBooking(BookingStatus::Confirmed);

    get('/b/' . $confirmed->manage_token)
        ->assertOk()
        ->assertSee('href="' . HostedUrl::operator($other) . '"', escape: false);
})->group('fast');

it('shows no link at all when there is nowhere to go back to', function (): void {
    // Bookings only: the home page is not served, so the fallback is a 404.
    [, $draft] = backLinkBooking(BookingStatus::Draft, mode: HostedSiteMode::BookingsOnly);

    get('/c/' . $draft->manage_token)
        ->assertOk()
        ->assertDontSee(__('guest.back_to_site'), escape: false);

    [, $confirmed] = backLinkBooking(BookingStatus::Confirmed, mode: HostedSiteMode::BookingsOnly);

    get('/b/' . $confirmed->manage_token)
        ->assertOk()
        ->assertDontSee(__('guest.back_to_site'), escape: false);
})->group('fast');

it('prefers the page the guest came from even when the home page is not served', function (): void {
    [, $booking] = backLinkBooking(
        BookingStatus::Confirmed,
        'https://aegean-blue.example/',
        HostedSiteMode::BookingsOnly,
    );

    get('/b/' . $booking->manage_token)
        ->assertOk()
        ->assertSee('href="https://aegean-blue.example/"', escape: false);
})->group('fast');
