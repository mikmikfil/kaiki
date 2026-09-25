<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\ConfirmFromWebhook;
use App\Domain\Booking\Actions\MintBalanceSession;
use App\Domain\Booking\Actions\RecordManualPayment;
use App\Domain\Booking\Actions\RefundBooking;
use App\Domain\Operations\Support\AttentionItems;
use App\Domain\Payments\Actions\ReconcilePendingPayments;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\PaymentGatewayName;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Jobs\ExecuteGatewayRefund;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\postJson;

use Tests\Support\Payments\WebhookScenario;

/*
|--------------------------------------------------------------------------
| Money that arrives when the booking is not where it was (2026-09-25)
|--------------------------------------------------------------------------
|
| A success webhook used to assume one thing: a booking at the gateway, waiting
| for it. Three others happen, and each lost money or seats:
|
| - **A balance** on a confirmed or checked-in booking. The payment was marked
|   paid and nothing else, so the guest was offered the balance again, the
|   reminders kept coming, and a checked-in booking threw.
| - **A decline, then a success on the same order.** The decline put the booking
|   back in draft and released its seats; the success confirmed it with none
|   sold.
| - **A success after the booking expired or was cancelled.** The payment was
|   marked paid and the confirmation threw: charged, with no booking.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);

    // The scenario's sailing is 4 July; the guest is paying in June.
    Carbon::setTestNow('2026-06-01 10:00:00');

    // The refund job is asserted as queued, not run: what it does is
    // `RefundFailureTest`'s business.
    Queue::fake([ExecuteGatewayRefund::class]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function postSuccess(string $transaction = 'evt_ok'): void
{
    $payload = WebhookScenario::gatewaySuccess($transaction);

    postJson('/webhooks/viva', $payload, WebhookScenario::verifiedHeaders($payload))->assertOk();
}

function postDecline(): void
{
    $payload = WebhookScenario::gatewayFailure();

    postJson('/webhooks/viva', $payload, WebhookScenario::verifiedHeaders($payload))->assertOk();
}

/**
 * A confirmed booking, deposit paid, the balance at the gateway under the
 * scenario's order code.
 *
 * @return array{0: Tenant, 1: Booking, 2: Payment}
 */
function balanceAtGateway(BookingStatus $status = BookingStatus::Confirmed): array
{
    [$tenant, $booking, $payment] = WebhookScenario::make();

    Tenancy::forTenant($tenant, function () use ($booking, $payment, $status): void {
        $booking->forceFill([
            'status' => $status,
            'confirmed_at' => now(),
            'hold_expires_at' => null,
            'paid_cents' => 4000,
            'balance_cents' => 8000,
            // Overdue already, so the operator's list shows it.
            'balance_due_at' => now()->subDay(),
            'is_test' => false,
        ])->save();

        Payment::factory()->deposit(4000)->create([
            'booking_id' => $booking->getKey(),
            'gateway' => PaymentGatewayName::Viva,
            'gateway_ref' => 'deposit-order',
        ]);

        $payment->forceFill(['kind' => PaymentKind::Balance, 'amount_cents' => 8000])->save();
    });

    return [$tenant, $booking, $payment];
}

/** @return list<string> */
function attentionKeys(): array
{
    return array_map(
        static fn ($item): string => $item->key,
        (new AttentionItems('Europe/Athens'))->everything(),
    );
}

function lateRefunds(): int
{
    return Payment::query()
        ->where('kind', PaymentKind::Refund->value)
        ->where('idempotency_key', 'like', RefundBooking::LATE_KEY_PREFIX . '%')
        ->count();
}

it('settles the booking money when the balance arrives by webhook', function (): void {
    [$tenant, $booking] = balanceAtGateway();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(attentionKeys())->toContain('balance:' . $booking->getKey());
    });

    postSuccess();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->refresh();

        expect($booking->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->paid_cents)->toBe(12000)
            ->and($booking->balance_cents)->toBe(0)
            // What the reminder scheduler and «ληξιπρόθεσμο» read.
            ->and($booking->balance_due_at)->toBeNull()
            ->and(attentionKeys())->not->toContain('balance:' . $booking->getKey())
            // And the guest is not offered the balance a second time.
            ->and(app(MintBalanceSession::class)($booking))->toBeNull()
            ->and(lateRefunds())->toBe(0);
    });

    Queue::assertNotPushed(ExecuteGatewayRefund::class);
})->group('fast');

it('takes a balance on a checked-in booking without trying to confirm it again', function (): void {
    [$tenant, $booking, $payment] = balanceAtGateway(BookingStatus::CheckedIn);

    postSuccess();

    Tenancy::forTenant($tenant, function () use ($booking, $payment): void {
        $booking->refresh();

        expect($booking->status)->toBe(BookingStatus::CheckedIn)
            ->and($booking->paid_cents)->toBe(12000)
            ->and($booking->balance_cents)->toBe(0)
            ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded);
    });
})->group('fast');

it('does nothing twice when the same success is delivered again', function (): void {
    [$tenant, $booking, $payment] = balanceAtGateway();

    postSuccess();
    postSuccess();

    Tenancy::forTenant($tenant, function () use ($booking, $payment): void {
        // A replay the unique index cannot see: the reconciler, say.
        app(ConfirmFromWebhook::class)($payment->refresh(), succeeded: true);

        $booking->refresh();

        expect($booking->paid_cents)->toBe(12000)
            ->and($booking->balance_cents)->toBe(0)
            ->and(lateRefunds())->toBe(0);
    });

    Queue::assertNotPushed(ExecuteGatewayRefund::class);
})->group('fast');

it('gives back a balance paid twice, from an older tab', function (): void {
    [$tenant, $booking, $older] = balanceAtGateway();

    Tenancy::forTenant($tenant, function () use ($booking, $older): void {
        // The newer tab's order, already paid and recorded.
        Payment::factory()->create([
            'booking_id' => $booking->getKey(),
            'kind' => PaymentKind::Balance,
            'amount_cents' => 8000,
            'gateway' => PaymentGatewayName::Viva,
            'gateway_ref' => 'newer-order',
        ]);

        $older->forceFill(['status' => PaymentStatus::Cancelled])->save();
    });

    postSuccess();

    Tenancy::forTenant($tenant, function () use ($booking, $older): void {
        $refund = Payment::query()->where('kind', PaymentKind::Refund->value)->sole();

        expect($refund->refunds_payment_id)->toBe($older->getKey())
            ->and($refund->amount_cents)->toBe(8000)
            ->and($booking->refresh()->status)->toBe(BookingStatus::Confirmed)
            ->and(attentionKeys())->toContain('late_payment:' . $refund->getKey());
    });

    Queue::assertPushed(ExecuteGatewayRefund::class, 1);
})->group('fast');

it('commits the seats when a declined card is followed by a success on the same order', function (): void {
    [$tenant, $booking] = WebhookScenario::make(capacity: 10);

    postDecline();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect($booking->refresh()->status)->toBe(BookingStatus::Draft);
    });

    postSuccess();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->refresh();
        $departure = Departure::query()->findOrFail($booking->departure_id);

        // Sold, not merely held — and not lost between the two.
        expect($booking->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->paid_cents)->toBe(12000)
            ->and($departure->seats_sold)->toBe(2)
            ->and($departure->seats_held)->toBe(0);
    });
})->group('fast');

it('refunds a success after a decline when the seats have gone meanwhile', function (): void {
    [$tenant, $booking, $payment] = WebhookScenario::make(capacity: 2);

    postDecline();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // The re-hold lapsed and somebody else bought the last two seats.
        $booking->refresh()->forceFill(['hold_expires_at' => now()->subMinute()])->save();
        Departure::query()->whereKey($booking->departure_id)->update(['seats_sold' => 2, 'seats_held' => 0]);
    });

    postSuccess();

    Tenancy::forTenant($tenant, function () use ($booking, $payment): void {
        $booking->refresh();
        $departure = Departure::query()->findOrFail($booking->departure_id);
        $refund = Payment::query()->where('kind', PaymentKind::Refund->value)->sole();

        expect($booking->status)->toBe(BookingStatus::Expired)
            ->and($departure->seats_sold)->toBe(2)
            ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
            ->and($refund->amount_cents)->toBe(12000)
            ->and($refund->refunds_payment_id)->toBe($payment->getKey())
            ->and(attentionKeys())->toContain('late_payment:' . $refund->getKey());
    });

    Queue::assertPushed(ExecuteGatewayRefund::class, 1);
})->group('fast');

/**
 * The scenario's booking as the sweeper leaves it: expired, seats released,
 * payment cancelled.
 */
function expireAtGateway(Tenant $tenant, Booking $booking, int $othersSold = 0): void
{
    Tenancy::forTenant($tenant, function () use ($booking, $othersSold): void {
        Departure::query()->whereKey($booking->departure_id)->update(['seats_sold' => $othersSold]);
        Payment::query()->update(['status' => PaymentStatus::Cancelled->value]);

        $booking->forceFill([
            'status' => BookingStatus::Expired,
            'cancel_reason' => CancelReason::PaymentFailed,
            'hold_expires_at' => null,
        ])->save();
    });
}

it('confirms a booking paid after it expired, when the seats are still there', function (): void {
    [$tenant, $booking] = WebhookScenario::make(capacity: 10);

    expireAtGateway($tenant, $booking);

    postSuccess();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->refresh();

        expect($booking->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->cancel_reason)->toBeNull()
            ->and($booking->paid_cents)->toBe(12000)
            ->and(Departure::query()->findOrFail($booking->departure_id)->seats_sold)->toBe(2)
            ->and(lateRefunds())->toBe(0);
    });
})->group('fast');

it('refunds a booking paid after it expired when the seats are gone, and tells the operator', function (): void {
    [$tenant, $booking, $payment] = WebhookScenario::make(capacity: 10);

    expireAtGateway($tenant, $booking, othersSold: 10);

    postSuccess();
    // A second delivery changes nothing.
    Tenancy::forTenant($tenant, fn () => app(ConfirmFromWebhook::class)($payment->refresh(), succeeded: true));

    Tenancy::forTenant($tenant, function () use ($booking, $payment): void {
        $booking->refresh();
        $refund = Payment::query()->where('kind', PaymentKind::Refund->value)->sole();

        expect($booking->status)->toBe(BookingStatus::Expired)
            ->and(Departure::query()->findOrFail($booking->departure_id)->seats_sold)->toBe(10)
            ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
            ->and($refund->amount_cents)->toBe(12000)
            ->and($refund->status)->toBe(PaymentStatus::Pending)
            ->and(attentionKeys())->toContain('late_payment:' . $refund->getKey());
    });

    Queue::assertPushed(ExecuteGatewayRefund::class, 1);
})->group('fast');

it('refunds a payment that finished after the booking was cancelled', function (): void {
    [$tenant, $booking, $payment] = WebhookScenario::make();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        Payment::query()->update(['status' => PaymentStatus::Cancelled->value]);
        $booking->forceFill(['status' => BookingStatus::Cancelled, 'cancelled_at' => now()])->save();
    });

    postSuccess();

    Tenancy::forTenant($tenant, function () use ($booking, $payment): void {
        $refund = Payment::query()->where('kind', PaymentKind::Refund->value)->sole();

        expect($booking->refresh()->status)->toBe(BookingStatus::Cancelled)
            ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
            ->and($refund->amount_cents)->toBe(12000)
            ->and(attentionKeys())->toContain('late_payment:' . $refund->getKey());
    });

    Queue::assertPushed(ExecuteGatewayRefund::class, 1);
})->group('fast');

it('does not refund again a charge the cancellation already settled', function (): void {
    [$tenant, $booking, $payment] = WebhookScenario::make();

    Tenancy::forTenant($tenant, function () use ($booking, $payment): void {
        // Paid, confirmed, then cancelled under the policy — and the old
        // success replayed.
        $payment->forceFill(['status' => PaymentStatus::Succeeded, 'paid_at' => now()])->save();
        $booking->forceFill(['status' => BookingStatus::Cancelled, 'cancelled_at' => now()])->save();

        app(ConfirmFromWebhook::class)($payment->refresh(), succeeded: true);

        expect(Payment::query()->where('kind', PaymentKind::Refund->value)->count())->toBe(0);
    });

    Queue::assertNotPushed(ExecuteGatewayRefund::class);
})->group('fast');

it('does the same when the reconciler finds the late payment', function (): void {
    [$tenant, $booking] = WebhookScenario::make(capacity: 10);

    expireAtGateway($tenant, $booking, othersSold: 10);

    Tenancy::forTenant($tenant, function (): void {
        Payment::query()->update(['created_at' => now()->subMinutes(70), 'updated_at' => now()->subMinutes(10)]);
    });

    Http::fake(['*/api/transactions*' => Http::response(['Transactions' => [['StatusId' => 'F', 'Amount' => 120.00]]])]);

    expect(app(ReconcilePendingPayments::class)()['confirmed'])->toBe(1);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect($booking->refresh()->status)->toBe(BookingStatus::Expired)
            ->and(lateRefunds())->toBe(1);
    });

    // The next sweep asks again (the window is still open) and gives back
    // nothing more.
    app(ReconcilePendingPayments::class)();

    Tenancy::forTenant($tenant, function (): void {
        expect(lateRefunds())->toBe(1);
    });

    Queue::assertPushed(ExecuteGatewayRefund::class, 1);
})->group('fast');

it('settles a balance the reconciler finds, as the webhook would', function (): void {
    [$tenant, $booking] = balanceAtGateway();

    Tenancy::forTenant($tenant, function (): void {
        Payment::query()->where('gateway_ref', WebhookScenario::REFERENCE)->update(['created_at' => now()->subMinutes(30)]);
    });

    Http::fake(['*/api/transactions*' => Http::response(['Transactions' => [['StatusId' => 'F', 'Amount' => 80.00]]])]);

    app(ReconcilePendingPayments::class)();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->refresh();

        expect($booking->status)->toBe(BookingStatus::Confirmed)
            ->and($booking->paid_cents)->toBe(12000)
            ->and($booking->balance_cents)->toBe(0)
            ->and($booking->balance_due_at)->toBeNull();
    });
})->group('fast');

it('does not sell the seats twice when cash settles a booking at the gateway', function (): void {
    [$tenant, $booking, $payment] = WebhookScenario::make(capacity: 10);

    Tenancy::forTenant($tenant, function () use ($booking, $payment): void {
        app(RecordManualPayment::class)($booking, 12000, PaymentGatewayName::Cash);

        expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed)
            // Two went in at the redirect (BKG-9), and that is all.
            ->and(Departure::query()->findOrFail($booking->departure_id)->seats_sold)->toBe(2)
            // The card page the guest gave up on is withdrawn.
            ->and($payment->refresh()->status)->toBe(PaymentStatus::Cancelled);
    });
})->group('fast');
