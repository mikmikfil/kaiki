<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

use Tests\Support\Booking\GuestPageScenario;
use Tests\Support\Payments\WebhookScenario;

/*
|--------------------------------------------------------------------------
| `/c/{manage_token}` — the checkout page (amends WGT-18)
|--------------------------------------------------------------------------
|
| The assertion this file exists for is the **first** one: the total is on the
| page. The widget's old review step asked for a quote prop that nothing passed
| and nothing fetched, so it showed «Υπολογίζουμε την τιμή σας…» for ever and a
| guest pressed pay having never been shown what they were paying. Nothing
| failed — there was no test that the price was rendered at all, only tests that
| the flow completed.
|
| So: the figure comes from `price_snapshot`, it is asserted here, and it is the
| same figure the button offers to charge.
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
 * The scenario builds a confirmed booking; checkout is about the draft before it.
 *
 * Named for this file rather than `draftBooking()`, which `BookingCredentialTest`
 * already defines — Pest shares one global function namespace across the suite,
 * so two helpers with one name is a collision that only shows up when both files
 * run.
 *
 * @return array{0: Tenant, 1: Booking}
 */
function checkoutDraft(bool $documentsRequired = false): array
{
    // The scenario's `paidCents` is what it sets `total_cents` from, so it has
    // to carry the real figure — a draft built with 0 is a booking for nothing,
    // which is not what this page is for and not what it 500s on.
    [$tenant, $booking] = GuestPageScenario::booking(
        paidCents: 12000,
        documentsRequired: $documentsRequired,
    );

    Tenancy::forTenant($tenant, static function () use ($booking): void {
        $booking->forceFill([
            'status' => BookingStatus::Draft,
            // Nothing paid yet — the money is what this page is about to take.
            'paid_cents' => 0,
            'hold_expires_at' => now()->addMinutes(15),
        ])->save();
    });

    return [$tenant, $booking->refresh()];
}

it('shows the total the button is about to charge', function (): void {
    [, $booking] = checkoutDraft();

    $response = get('/c/' . $booking->manage_token)->assertOk();

    // The figure, and the reference it belongs to. Formatted the way the page
    // formats it rather than as raw cents, because a guest reading «12000» has
    // still not been shown a price.
    $response->assertSee($booking->reference, escape: false)
        ->assertSee('120,00', escape: false);
})->group('fast');

it('sends a booking that is no longer payable to its own page', function (): void {
    [$tenant, $booking] = checkoutDraft();

    Tenancy::forTenant($tenant, static function () use ($booking): void {
        $booking->forceFill(['status' => BookingStatus::Confirmed])->save();
    });

    // Not a 404 and not the checkout: the same token opens the booking page,
    // which is where somebody who already paid should land.
    get('/c/' . $booking->manage_token)
        ->assertRedirect(route('guest.booking', ['token' => $booking->manage_token]));
})->group('fast');

it('asks for the passengers only when the trip requires them', function (): void {
    [, $plain] = checkoutDraft(documentsRequired: false);

    get('/c/' . $plain->manage_token)
        ->assertOk()
        ->assertDontSee(__('guest.checkout.passengers'), escape: false);

    [, $manifest] = checkoutDraft(documentsRequired: true);

    get('/c/' . $manifest->manage_token)
        ->assertOk()
        ->assertSee(__('guest.checkout.passengers'), escape: false)
        // TOK-9's rule, carried over: the reason comes with the question.
        ->assertSee(__('guest.checkout.passengers_why'), escape: false);
})->group('fast');

it('refuses to start a payment without the terms accepted', function (): void {
    [, $booking] = checkoutDraft();

    post('/c/' . $booking->manage_token, [
        'guest_name' => 'Δοκιμή Δοκιμή',
        'guest_email' => 'test@example.com',
    ])->assertSessionHasErrors('terms');

    // And nothing was recorded, so a refused submission leaves no evidence of
    // an acceptance that did not happen (§2.5).
    expect($booking->refresh()->terms_accepted_at)->toBeNull();
})->group('fast');

it('records the acceptance and sends the guest to the gateway', function (): void {
    [, $booking] = checkoutDraft();

    $response = post('/c/' . $booking->manage_token, [
        'guest_name' => 'Δοκιμή Δοκιμή',
        'guest_email' => 'new@example.com',
        'guest_phone' => '+306900000000',
        'terms' => '1',
    ]);

    $booking->refresh();

    // The evidence pair §2.5 describes, which M6 renders the ναυλοσύμφωνο
    // against: when they accepted, and from where.
    expect($booking->terms_accepted_at)->not->toBeNull()
        ->and($booking->ip_address)->not->toBeNull()
        // The details typed here are the details on the booking.
        ->and($booking->guest_email)->toBe('new@example.com');

    // Away from this site, to the gateway. BKG-5's order is unchanged.
    $response->assertRedirect();
})->group('fast');
