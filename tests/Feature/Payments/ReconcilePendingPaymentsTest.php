<?php

declare(strict_types=1);

use App\Domain\Payments\Actions\ReconcilePendingPayments;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\Payments\WebhookScenario;

/*
|--------------------------------------------------------------------------
| Payments the webhook never confirmed (docs/api.md item 12)
|--------------------------------------------------------------------------
|
| The webhook is a delivery somebody else has to make, and on 2026-09-16 every
| way it can fail happened against a real Viva demo account in one afternoon:
| the verification key was fetched from the wrong host, so the address could not
| be registered at all; then it was registered against «Order Updated», an event
| that never fires on a payment. Both times the money moved and the booking sat
| unconfirmed — taken, with nobody knowing.
|
| So the gateway is asked. These tests are mostly about what the asking must
| *refuse* to do, because that is where a reconciler becomes dangerous: one that
| reads silence as failure cancels bookings during an outage, and one that
| confirms without checking the amount trusts a number nobody compared.
*/

/** Viva's answer for an order, in their own shape. */
function vivaTransactions(array ...$transactions): array
{
    return ['Transactions' => $transactions];
}

function pendingVivaPayment(int $amountCents = 12000, ?string $reference = null): Payment
{
    return Payment::query()->latest('id')->firstOrFail()->forceFill([
        'status' => PaymentStatus::Pending,
        'amount_cents' => $amountCents,
        'gateway_ref' => $reference ?? WebhookScenario::REFERENCE,
        // Older than the grace period, or it is not a candidate yet.
        'created_at' => now()->subMinutes(30),
    ]);
}

it('confirms a booking the gateway says was paid', function (): void {
    [$tenant] = WebhookScenario::make();

    Tenancy::forTenant($tenant, function (): void {
        pendingVivaPayment(12000)->save();
    });

    Http::fake([
        '*/api/transactions*' => Http::response(vivaTransactions([
            'StatusId' => 'F',
            'Amount' => 120.00,
        ])),
    ]);

    $tally = app(ReconcilePendingPayments::class)();

    expect($tally['confirmed'])->toBe(1);

    Tenancy::forTenant($tenant, function (): void {
        expect(Payment::query()->latest('id')->first()?->status)->toBe(PaymentStatus::Succeeded)
            ->and(Booking::query()->latest('id')->first()?->status)->toBe(BookingStatus::Confirmed);
    });
})->group('fast');

it('leaves a payment alone while the gateway still calls it unsettled', function (): void {
    [$tenant] = WebhookScenario::make();

    Tenancy::forTenant($tenant, function (): void {
        pendingVivaPayment()->save();
    });

    // `A` — active. The guest may be on the 3-D Secure step this second.
    Http::fake(['*/api/transactions*' => Http::response(vivaTransactions(['StatusId' => 'A', 'Amount' => 120.00]))]);

    $tally = app(ReconcilePendingPayments::class)();

    expect($tally['unanswered'])->toBe(1)
        ->and($tally['confirmed'])->toBe(0);

    Tenancy::forTenant($tenant, function (): void {
        expect(Payment::query()->latest('id')->first()?->status)->toBe(PaymentStatus::Pending);
    });
})->group('fast');

it('fails a payment only when the gateway says the attempt is over', function (): void {
    [$tenant] = WebhookScenario::make();

    Tenancy::forTenant($tenant, function (): void {
        pendingVivaPayment()->save();
    });

    Http::fake(['*/api/transactions*' => Http::response(vivaTransactions(['StatusId' => 'E', 'Amount' => 120.00]))]);

    $tally = app(ReconcilePendingPayments::class)();

    expect($tally['failed'])->toBe(1);

    Tenancy::forTenant($tenant, function (): void {
        expect(Payment::query()->latest('id')->first()?->status)->toBe(PaymentStatus::Failed);
    });
})->group('fast');

it('never fails a payment because the gateway is unreachable', function (): void {
    [$tenant] = WebhookScenario::make();

    Tenancy::forTenant($tenant, function (): void {
        pendingVivaPayment()->save();
    });

    // The one that would be catastrophic: an outage must not cancel bookings
    // across the platform, so "no answer" leaves every row exactly as it was.
    Http::fake(['*/api/transactions*' => Http::response('gateway down', 503)]);

    $tally = app(ReconcilePendingPayments::class)();

    expect($tally['failed'])->toBe(0)
        ->and($tally['confirmed'])->toBe(0);

    Tenancy::forTenant($tenant, function (): void {
        expect(Payment::query()->latest('id')->first()?->status)->toBe(PaymentStatus::Pending)
            ->and(Booking::query()->latest('id')->first()?->status)->not->toBe(BookingStatus::Cancelled);
    });
})->group('fast');

it('refuses to confirm an amount that is not the one owed', function (): void {
    [$tenant] = WebhookScenario::make();

    Tenancy::forTenant($tenant, function (): void {
        pendingVivaPayment(12000)->save();
    });

    Log::spy();

    // Paid, settled — and for ninety euros against a hundred and twenty. A
    // person decides what that is; this must not quietly confirm it.
    Http::fake(['*/api/transactions*' => Http::response(vivaTransactions(['StatusId' => 'F', 'Amount' => 90.00]))]);

    $tally = app(ReconcilePendingPayments::class)();

    expect($tally['confirmed'])->toBe(0);

    Tenancy::forTenant($tenant, function (): void {
        expect(Payment::query()->latest('id')->first()?->status)->toBe(PaymentStatus::Pending);
    });

    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message): bool => $message === 'payments.reconcile_amount_mismatch',
    );
})->group('fast');

it('takes the successful attempt when a card was retried', function (): void {
    [$tenant] = WebhookScenario::make();

    Tenancy::forTenant($tenant, function (): void {
        pendingVivaPayment(12000)->save();
    });

    // A declined attempt, then a good one, against the same order. What matters
    // is whether the money is there now.
    Http::fake(['*/api/transactions*' => Http::response(vivaTransactions(
        ['StatusId' => 'E', 'Amount' => 120.00],
        ['StatusId' => 'F', 'Amount' => 120.00],
    ))]);

    expect(app(ReconcilePendingPayments::class)()['confirmed'])->toBe(1);
})->group('fast');

it('ignores a payment younger than the grace period', function (): void {
    [$tenant] = WebhookScenario::make();

    Tenancy::forTenant($tenant, function (): void {
        pendingVivaPayment()->forceFill(['created_at' => now()->subMinute()])->save();
    });

    Http::fake(['*/api/transactions*' => Http::response(vivaTransactions(['StatusId' => 'F', 'Amount' => 120.00]))]);

    // A guest on the gateway's page is not a straggler, and asking about them
    // would race the webhook for no gain.
    expect(app(ReconcilePendingPayments::class)()['checked'])->toBe(0);

    Http::assertNothingSent();
})->group('fast');

it('reads euros into cents without a floating point cent going missing', function (): void {
    [$tenant] = WebhookScenario::make();

    Tenancy::forTenant($tenant, function (): void {
        pendingVivaPayment(11000)->save();
    });

    // 110.00 * 100 is not always 11000 in binary floating point, and this number
    // decides whether a booking is confirmed for the right amount.
    Http::fake(['*/api/transactions*' => Http::response(vivaTransactions(['StatusId' => 'F', 'Amount' => 110.00]))]);

    expect(app(ReconcilePendingPayments::class)()['confirmed'])->toBe(1);
})->group('fast');
