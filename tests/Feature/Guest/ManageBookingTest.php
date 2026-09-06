<?php

declare(strict_types=1);

use App\Domain\Booking\Support\RefundEntitlement;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\PaymentKind;
use App\Enums\WeatherChoice;
use App\Models\Departure;
use App\Models\Payment;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

use Tests\Support\Booking\GuestPageScenario;
use Tests\Support\Payments\WebhookScenario;

/*
|--------------------------------------------------------------------------
| TOK-6, TOK-7, TOK-13: the page a guest actually uses
|--------------------------------------------------------------------------
|
| Two assertions carry this file.
|
| **The amount shown equals the amount charged.** TOK-6 requires the exact
| refund *"computed from the policy snapshot"* to be on screen before the guest
| confirms, and that is the one number they will check afterwards. Both come
| from one call to `RefundEntitlement::forCancellation()`, so they cannot drift
| apart — and this asserts the property rather than the implementation.
|
| **A double submit cancels once.** TOK-13. Nothing in the controller implements
| that; `CancelBooking` returns early on a booking that is already cancelled, so
| the guarantee holds for the API and the panel too.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    // The fuller fake: this page both **refunds** and **mints a checkout
    // session**, and `CancellationScenario`'s covers only the first. A missing
    // recording here does not fail loudly — it makes a network call.
    WebhookScenario::fakeGatewayResponses();
    Carbon::setTestNow('2026-06-20 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('shows the summary, the meeting point and what was paid', function (): void {
    [, $booking] = GuestPageScenario::booking(paidCents: 12000);

    get('/b/' . $booking->manage_token)
        ->assertOk()
        ->assertSee($booking->reference)
        ->assertSee('120,00');
});

it('shows the exact refund it will pay, and pays exactly that', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 12000);

    $expected = Tenancy::forTenant($tenant, fn (): int => RefundEntitlement::forCancellation($booking)->totalCents);

    // Half of €120 on the 7-day rung: the number on the page.
    expect($expected)->toBe(6000);

    get('/b/' . $booking->manage_token)->assertOk()->assertSee('60,00');

    post('/b/' . $booking->manage_token . '/cancel')->assertRedirect();

    Tenancy::forTenant($tenant, function () use ($expected): void {
        // And the number that was actually put through. The guest checks this
        // one; a page that promised €60 and refunded €30 is a support call and
        // a chargeback.
        expect(Payment::query()->where('kind', PaymentKind::Refund->value)->sole()->amount_cents)
            ->toBe($expected);
    });
});

it('cancels once, however many times the button is pressed', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 12000);

    post('/b/' . $booking->manage_token . '/cancel')->assertRedirect();
    post('/b/' . $booking->manage_token . '/cancel')->assertRedirect();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // TOK-13, asserted the way the requirement words it — by submitting
        // twice. One refund row, and the seats released once.
        expect(Payment::query()->where('kind', PaymentKind::Refund->value)->count())->toBe(1)
            ->and(Departure::query()->findOrFail($booking->departure_id)->seats_sold)->toBe(0);
    });
});

it('offers cancel with a plain sentence when no refund is due, and still frees the seat', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 12000);

    // Two days out: under the standard ladder the 2-day rung gives 0%.
    Carbon::setTestNow('2026-07-02 09:00:00');

    $response = get('/b/' . $booking->manage_token)->assertOk();

    // TOK-7: **shown**, saying plainly that nothing comes back. Hiding it would
    // leave a guest who cannot come with no way to release the place, and an
    // operator with an empty seat they could have resold.
    expect($response->getContent())->toContain(__('guest.booking.cancel.no_refund'));

    post('/b/' . $booking->manage_token . '/cancel')->assertRedirect();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect($booking->refresh()->status)->toBe(BookingStatus::Cancelled)
            ->and(Departure::query()->findOrFail($booking->departure_id)->seats_sold)->toBe(0)
            ->and(Payment::query()->where('kind', PaymentKind::Refund->value)->count())->toBe(0);
    });
});

it('does not offer cancel after the boat has gone', function (): void {
    [, $booking] = GuestPageScenario::booking();

    Carbon::setTestNow('2026-07-05 09:00:00');

    $response = get('/b/' . $booking->manage_token)->assertOk();

    // CXL-4: after departure this is the operator's to record by hand, with its
    // own trail. The page says so rather than hiding the section.
    expect($response->getContent())->toContain(__('guest.booking.cancel.past'));

    post('/b/' . $booking->manage_token . '/cancel')->assertRedirect();

    expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
});

it('mints a balance session when the guest presses pay', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 4000, balanceCents: 8000);

    post('/b/' . $booking->manage_token . '/pay-balance')->assertRedirect();

    Tenancy::forTenant($tenant, function (): void {
        // ADR-0004 Option D: priced at the moment the guest opened the page,
        // not at confirmation — which is why the email links here rather than
        // at a gateway URL that would have expired weeks ago.
        expect(Payment::query()->where('kind', PaymentKind::Balance->value)->sole()->amount_cents)
            ->toBe(8000);
    });
});

it('does not mint a second session on a double press', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 4000, balanceCents: 8000);

    post('/b/' . $booking->manage_token . '/pay-balance');
    post('/b/' . $booking->manage_token . '/pay-balance');

    Tenancy::forTenant($tenant, function (): void {
        // TOK-13's "never double-charges". Two pending rows would sit in the
        // operator's stuck-payment feed competing with real ones.
        expect(Payment::query()->where('kind', PaymentKind::Balance->value)->count())->toBe(1);
    });
});

it('records a weather choice with its evidence, and only the first one', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 12000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill([
            'status' => BookingStatus::Cancelled,
            'cancel_reason' => CancelReason::Weather,
            'cancelled_at' => now(),
            'weather_choice_due_at' => now()->addDays(14),
        ])->save();
    });

    post('/b/' . $booking->manage_token . '/weather-choice', ['choice' => 'voucher'])->assertRedirect();
    post('/b/' . $booking->manage_token . '/weather-choice', ['choice' => 'refund'])->assertRedirect();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // CXL-7's evidence, and its idempotency: the conditional update means a
        // second click changes nothing and moves no money.
        expect($booking->refresh()->weather_choice)->toBe(WeatherChoice::Voucher)
            ->and($booking->weather_choice_ip)->not->toBeNull()
            ->and(Payment::query()->where('kind', PaymentKind::Refund->value)->count())->toBe(0);
    });
});

it('lets the guest correct their own contact details and nothing else', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking();

    post('/b/' . $booking->manage_token . '/contact', [
        'guest_name' => 'Ελένη Παπαδοπούλου',
        'guest_email' => 'eleni@example.gr',
        'guest_phone' => '+306944000111',
        // Not a field the form has, and not a field the controller reads. A
        // page that let a guest edit the party would let them edit the price.
        'pax_total' => 99,
    ])->assertRedirect();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect($booking->refresh()->guest_name)->toBe('Ελένη Παπαδοπούλου')
            ->and($booking->guest_email)->toBe('eleni@example.gr')
            ->and($booking->pax_total)->toBe(2);
    });
});

it('refuses every action on a token that does not resolve', function (): void {
    foreach (['cancel', 'pay-balance', 'weather-choice', 'contact'] as $action) {
        post('/b/not-a-real-token/' . $action)->assertStatus(404);
    }
});
