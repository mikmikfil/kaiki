<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\MintBalanceSession;
use App\Enums\BookingStatus;
use App\Enums\IntegrationProvider;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\IntegrationCredential;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\Tenancy;
use Tests\Support\Payments\WebhookScenario;

/*
|--------------------------------------------------------------------------
| ADR-0004 Option D, PAY-4.3, TOK-6: the second checkout session
|--------------------------------------------------------------------------
|
| A deposit and a balance are **two independent sessions**, months apart if need
| be, because "create a checkout session" is the only primitive Viva and Stripe
| genuinely share — no card-on-file, no stored mandate, no off-session charge.
|
| The consequence is the one these tests are about: the balance session is
| minted **on demand, from `/b/{manage_token}`, priced at the moment the guest
| opens the page**. ADR-0004 gives the reason in a clause — *"so a legitimately
| changed balance is charged correctly"* — and between the confirmation email
| and the click, weeks pass and the balance moves.
|
*/

beforeEach(function (): void {
    // No test in this file makes a network call. The gateway hosts are also
    // pinned to `.test` by `phpunit.xml`, so a request escaping this fake
    // cannot resolve — two layers, because this one is a thing a file can
    // forget and the other is not.
    WebhookScenario::fakeGatewayResponses();
});

/**
 * A confirmed booking with a deposit paid and a balance outstanding.
 *
 * @return array{0: Tenant, 1: Booking}
 */
function balanceSessionScenario(int $balanceCents = 6000): array
{
    $tenant = Tenant::factory()->create();

    $booking = Tenancy::forTenant($tenant, static function () use ($balanceCents): Booking {
        IntegrationCredential::factory()
            ->forProvider(IntegrationProvider::Viva)
            ->live()
            ->verified()
            ->default()
            ->create();

        $booking = Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'total_cents' => 12000,
            'deposit_cents' => 12000 - $balanceCents,
            'paid_cents' => 12000 - $balanceCents,
            'balance_cents' => $balanceCents,
            'is_test' => false,
        ]);

        Payment::factory()->deposit(12000 - $balanceCents)->create(['booking_id' => $booking->getKey()]);

        return $booking;
    });

    return [$tenant, $booking];
}

it('charges the balance as it is now, not as it was in the email', function (): void {
    [$tenant, $booking] = balanceSessionScenario(balanceCents: 6000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // Three weeks pass. The guest adds two people, and the balance
        // legitimately goes up — extras added, pax changed, an operator
        // discount applied. All of it happens between the email and the click.
        $booking->forceFill(['total_cents' => 20000, 'balance_cents' => 14000])->save();

        $target = app(MintBalanceSession::class)($booking->refresh());

        expect($target)->not->toBeNull();

        $payment = Payment::query()->where('kind', PaymentKind::Balance->value)->firstOrFail();

        // The new figure. A session minted at confirmation would charge €60 for
        // a €140 balance, and the operator would be short with no record of why.
        expect($payment->amount_cents)->toBe(14000)
            ->and($payment->status)->toBe(PaymentStatus::Pending)
            ->and($payment->gateway_ref)->toBe($target?->reference);
    });
})->group('fast');

it('creates a second payment row rather than mutating the deposit', function (): void {
    [$tenant, $booking] = balanceSessionScenario();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(MintBalanceSession::class)($booking);

        $payments = Payment::query()->where('booking_id', $booking->getKey())->get();

        // §2.5: a payment is never mutated except by its own status. The
        // deposit row records money that actually moved and stays exactly as
        // it was. Two gateway fees instead of one is ADR-0004's accepted cost.
        expect($payments)->toHaveCount(2)
            ->and($payments->pluck('kind')->map->value->sort()->values()->all())
            ->toBe(['balance', 'deposit']);
    });
})->group('fast');

it('reuses an open balance session rather than leaving two pending rows', function (): void {
    [$tenant, $booking] = balanceSessionScenario();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(MintBalanceSession::class)($booking);

        // The guest opened the page, thought better of it, and came back an
        // hour later. Two pending rows would sit in the operator's
        // stuck-payment feed competing with real ones.
        app(MintBalanceSession::class)($booking->refresh());

        expect(Payment::query()->where('kind', PaymentKind::Balance->value)->count())->toBe(1);
    });
})->group('fast');

it('refreshes the amount on the reused row, because the balance may have moved', function (): void {
    [$tenant, $booking] = balanceSessionScenario(balanceCents: 6000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(MintBalanceSession::class)($booking);

        $booking->forceFill(['total_cents' => 20000, 'balance_cents' => 14000])->save();

        app(MintBalanceSession::class)($booking->refresh());

        // Reusing the row must not mean reusing the price — that would
        // reintroduce the stale figure the late minting exists to avoid.
        expect(Payment::query()->where('kind', PaymentKind::Balance->value)->sole()->amount_cents)->toBe(14000);
    });
})->group('fast');

it('takes the balance through whatever gateway took the deposit', function (): void {
    [$tenant, $booking] = balanceSessionScenario();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(MintBalanceSession::class)($booking);

        $deposit = Payment::query()->where('kind', PaymentKind::Deposit->value)->sole();
        $balance = Payment::query()->where('kind', PaymentKind::Balance->value)->sole();

        // An operator reconciling two rows for one booking against two
        // different providers is a support call nobody needs.
        expect($balance->gateway)->toBe($deposit->gateway);
    });
})->group('fast');

it('mints nothing when there is nothing to pay', function (): void {
    [$tenant, $booking] = balanceSessionScenario(balanceCents: 0);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(app(MintBalanceSession::class)($booking))->toBeNull()
            ->and(Payment::query()->where('kind', PaymentKind::Balance->value)->count())->toBe(0);
    });
})->group('fast');

it('refuses to take a balance on a trip that is not happening', function (): void {
    [$tenant, $booking] = balanceSessionScenario();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // A cancelled booking can carry a non-zero `balance_cents` from before
        // it ended, and a guest still holding the link must not be able to pay
        // it. `/b/{token}` lives as long as the booking does.
        $booking->forceFill(['status' => BookingStatus::Cancelled])->save();

        expect(app(MintBalanceSession::class)($booking))->toBeNull();

        $booking->forceFill(['status' => BookingStatus::Expired])->save();

        expect(app(MintBalanceSession::class)($booking))->toBeNull();
    });
})->group('fast');

it('stores the checkout url on the row but never emails one', function (): void {
    [$tenant, $booking] = balanceSessionScenario();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $target = app(MintBalanceSession::class)($booking);

        $payment = Payment::query()->where('kind', PaymentKind::Balance->value)->sole();

        // PAY-4.3: the URL is what *this* redirect is built from, stored
        // un-indexed and short-lived. Emailed links point at
        // `/b/{manage_token}` instead, because a gateway URL emailed today is
        // dead by the time a guest opens it in three weeks — and they would
        // have no way back.
        expect($payment->checkout_url)->toBe($target?->url)
            ->and($payment->checkout_url)->not->toContain($booking->manage_token);
    });
})->group('fast');
