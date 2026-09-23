<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\ApplyGuestChoice;
use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Booking\Actions\CancelDeparture;
use App\Domain\Booking\Actions\ConfirmManualRefund;
use App\Domain\Booking\Actions\RefundBooking;
use App\Domain\Booking\Support\RefundEntitlement;
use App\Domain\Operations\Support\AttentionItems;
use App\Enums\BookingStatus;
use App\Enums\CancelledBy;
use App\Enums\CancelReason;
use App\Enums\DepartureCancelReason;
use App\Enums\NotificationTemplate;
use App\Enums\PaymentGatewayName;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Enums\WeatherChoice;
use App\Mail\Support\BookingMailDetails;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\Booking\CancellationScenario;

/*
|--------------------------------------------------------------------------
| Every way money comes in × every way a booking stops (2026-09-23)
|--------------------------------------------------------------------------
|
| «Δεν θα έπρεπε να τα έχεις βρει στο stress;» Each piece of the refund path
| had its own tests — a card refund, a cash payment recorded by hand — and the
| two had never met. A booking paid in cash was cancelled and nothing was owed
| to anybody; a card deposit with a cash balance asked the card for money it
| had never taken. This file is the meeting: every mix of payments against
| every path that gives money back, and the same questions of each cell:
|
| 1. Is exactly what is owed on its way back — no more, no less?
| 2. Does each refund go back the way its money came, and never exceed it?
| 3. Is whatever the operator must hand back themselves in front of them?
| 4. After «Επιστράφηκε», does the booking's money add up?
| 5. Does the guest's email say where the money comes from?
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    CancellationScenario::fakeGatewayResponses();

    Carbon::setTestNow('2026-06-27 00:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * The ways money arrives, as [gateway, kind, cents] rows. €120 in every mix,
 * so the paths compare like with like.
 *
 * @return array<string, list<array{0: PaymentGatewayName, 1: PaymentKind, 2: int}>>
 */
function moneyMixes(): array
{
    return [
        'card' => [[PaymentGatewayName::Viva, PaymentKind::Full, 12000]],
        'cash' => [[PaymentGatewayName::Cash, PaymentKind::Full, 12000]],
        'bank transfer' => [[PaymentGatewayName::BankTransfer, PaymentKind::Full, 12000]],
        'card deposit, cash balance' => [
            [PaymentGatewayName::Viva, PaymentKind::Deposit, 3600],
            [PaymentGatewayName::Cash, PaymentKind::Balance, 8400],
        ],
        'card deposit, card balance' => [
            [PaymentGatewayName::Viva, PaymentKind::Deposit, 3600],
            [PaymentGatewayName::Viva, PaymentKind::Balance, 8400],
        ],
        'transfer deposit, card balance' => [
            [PaymentGatewayName::BankTransfer, PaymentKind::Deposit, 3600],
            [PaymentGatewayName::Viva, PaymentKind::Balance, 8400],
        ],
    ];
}

/**
 * A confirmed €120 booking paid by `$mix`.
 *
 * @param  list<array{0: PaymentGatewayName, 1: PaymentKind, 2: int}>  $mix
 * @return array{0: Tenant, 1: Booking}
 */
function paidBy(array $mix): array
{
    [$tenant, $booking] = CancellationScenario::make(paidCents: 12000);

    Tenancy::forTenant($tenant, static function () use ($booking, $mix): void {
        // The scenario's own card payment, replaced by the mix under test.
        Payment::query()->where('booking_id', $booking->getKey())->delete();

        foreach ($mix as [$gateway, $kind, $cents]) {
            Payment::query()->create([
                'uuid' => (string) Str::uuid(),
                'booking_id' => $booking->getKey(),
                'gateway' => $gateway,
                'kind' => $kind,
                'amount_cents' => $cents,
                'currency' => 'EUR',
                'status' => PaymentStatus::Succeeded,
                'gateway_ref' => $gateway->isExternal() ? CancellationScenario::REFERENCE : null,
                'idempotency_key' => (string) Str::uuid(),
                'paid_at' => now(),
            ]);
        }
    });

    return [$tenant, $booking->refresh()];
}

/**
 * The ways a booking gives money back. Each returns what should now be on its
 * way back, in cents.
 *
 * @return array<string, Closure(Booking): int>
 */
function refundPaths(): array
{
    return [
        'operator cancels the booking' => static function (Booking $booking): int {
            app(CancelBooking::class)(
                booking: $booking,
                reason: CancelReason::Operator,
                by: CancelledBy::Operator,
                refundInFull: true,
            );

            return 12000;
        },
        'departure cancelled, short of its minimum' => static function (Booking $booking): int {
            app(CancelDeparture::class)(
                departure: Departure::query()->findOrFail($booking->departure_id),
                reason: DepartureCancelReason::MinPax,
            );

            return 12000;
        },
        'guest cancels under the policy' => static function (Booking $booking): int {
            $owed = RefundEntitlement::forCancellation($booking)->cashCents;

            app(CancelBooking::class)(booking: $booking, reason: CancelReason::GuestRequest, by: CancelledBy::Guest);

            return $owed;
        },
        'weather, the guest takes the money' => static function (Booking $booking): int {
            app(CancelDeparture::class)(
                departure: Departure::query()->findOrFail($booking->departure_id),
                reason: DepartureCancelReason::Weather,
            );

            app(ApplyGuestChoice::class)($booking->refresh(), WeatherChoice::Refund);

            return 12000;
        },
        'people taken off, €50 back' => static function (Booking $booking): int {
            app(RefundBooking::class)->partial($booking, 5000);

            return 5000;
        },
    ];
}

/** @return list<Payment> every refund that is on its way or has arrived */
function liveRefunds(Booking $booking): array
{
    return Payment::query()
        ->where('booking_id', $booking->getKey())
        ->where('kind', PaymentKind::Refund->value)
        ->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::Processing->value, PaymentStatus::Succeeded->value])
        ->get()
        ->all();
}

$cells = [];
foreach (array_keys(moneyMixes()) as $mix) {
    foreach (array_keys(refundPaths()) as $path) {
        $cells["{$mix} × {$path}"] = [$mix, $path];
    }
}

it('gives back what is owed, the way it came, and says so', function (string $mix, string $path): void {
    [$tenant, $booking] = paidBy(moneyMixes()[$mix]);

    Tenancy::forTenant($tenant, function () use ($booking, $path): void {
        $owed = refundPaths()[$path]($booking);
        $booking->refresh();

        $refunds = liveRefunds($booking);

        // 1. Exactly what is owed is on its way back.
        expect(array_sum(array_map(static fn (Payment $r): int => $r->amount_cents, $refunds)))->toBe($owed);

        // 2. Back the way it came, and never more than that charge took.
        foreach ($refunds as $refund) {
            $source = Payment::query()->findOrFail($refund->refunds_payment_id);

            expect($refund->gateway)->toBe($source->gateway)
                ->and($refund->amount_cents)->toBeLessThanOrEqual($source->amount_cents);

            // A card refund went through the (faked) gateway on its own; money
            // to hand back waits for the operator.
            expect($refund->status)->toBe($source->gateway->isExternal() ? PaymentStatus::Succeeded : PaymentStatus::Pending);
        }

        $byHand = array_values(array_filter($refunds, static fn (Payment $r): bool => ! $r->gateway->isExternal()));

        // 3. Whatever is to be handed back is in front of the operator.
        $keys = array_map(
            static fn ($item): string => $item->key,
            (new AttentionItems('Europe/Athens'))->everything(),
        );

        foreach ($byHand as $refund) {
            expect($keys)->toContain('refund:' . $refund->getKey());
        }

        // 5. The guest's email says which part comes back how. (Asked before
        // the money is handed back — it is sent at the cancellation.)
        if ($booking->status === BookingStatus::Cancelled && $owed > 0) {
            $facts = collect(BookingMailDetails::for($booking, NotificationTemplate::BookingCancelled, 'el', ['refunded_cents' => $owed])->facts)
                ->pluck('value')
                ->implode(' | ');

            $handCents = array_sum(array_map(static fn (Payment $r): int => $r->amount_cents, $byHand));

            if ($handCents > 0) {
                expect($facts)->toContain('θα σας τα επιστρέψουμε εμείς');
            } else {
                expect($facts)->not->toContain('θα σας τα επιστρέψουμε εμείς');
            }
        }

        // 4. «Επιστράφηκε» on each, and the booking's money adds up.
        foreach ($byHand as $refund) {
            expect(app(ConfirmManualRefund::class)($refund))->toBeTrue()
                // Pressed twice, recorded once.
                ->and(app(ConfirmManualRefund::class)($refund->refresh()))->toBeFalse();
        }

        $booking->refresh();

        expect($booking->refunded_cents)->toBe($owed)
            ->and($booking->paid_cents)->toBe(12000 - $owed)
            ->and(array_filter(
                (new AttentionItems('Europe/Athens'))->everything(),
                static fn ($item): bool => str_starts_with($item->key, 'refund:'),
            ))->toBe([]);

        if ($owed === 12000 && $booking->status !== BookingStatus::Confirmed) {
            expect($booking->status)->toBe(BookingStatus::Refunded);
        }
    });
})->with($cells)->group('fast');

it('does not refund twice when a cancellation is replayed', function (string $mix): void {
    [$tenant, $booking] = paidBy(moneyMixes()[$mix]);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $refund = app(RefundBooking::class);
        $entitlement = RefundEntitlement::atPercent($booking, 100);

        $refund($booking, $entitlement);
        $refund($booking->refresh(), $entitlement);

        $total = array_sum(array_map(static fn (Payment $r): int => $r->amount_cents, liveRefunds($booking)));

        expect($total)->toBe(12000);
    });
})->with(array_keys(moneyMixes()))->group('fast');
