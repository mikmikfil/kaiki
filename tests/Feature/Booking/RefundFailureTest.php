<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\CancelBooking;
use App\Domain\Payments\Support\GatewayResolver;
use App\Enums\BookingStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Events\BookingRefunded;
use App\Jobs\ExecuteGatewayRefund;
use App\Models\Payment;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Booking\CancellationScenario;

/*
|--------------------------------------------------------------------------
| CXL-10: a refund that failed must look like a refund that failed
|--------------------------------------------------------------------------
|
| > *Refunds are executed through the gateway asynchronously, are idempotent per
| > `Payment` row, and a failed refund surfaces in the operator error feed in
| > Greek and English without silently marking the booking refunded.*
|
| The last clause is the one with teeth. A booking that says `refunded` while
| the money is still in the operator's account is a dispute the operator loses
| without knowing why — and it is the natural shape of the bug, because the
| status is written by the code that *asked* for the refund rather than by the
| code that got an answer.
|
*/

beforeEach(function (): void {
    // **The queue driver is set explicitly, and #53 and #83 both paid for
    // learning why.** `phpunit.xml` sets `QUEUE_CONNECTION=sync`, so the job
    // runs inline *by accident* locally — and ENV-1 puts a real Redis in the
    // `Pest on MySQL 8 + Redis` job, where the same dispatch is enqueued and
    // never executed. Every assertion about an outcome then fails on that one
    // job while the ones that only inspect a row keep passing, which reads as a
    // data problem rather than an environment one.
    //
    // The *processing* is under test here; the queueing is asserted separately
    // below with `Queue::fake()`.
    config(['queue.default' => 'sync']);

    Carbon::setTestNow('2026-06-27 00:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('queues the gateway call rather than making it during the cancellation', function (): void {
    Queue::fake();
    CancellationScenario::fakeGatewayResponses();

    [$tenant, $booking] = CancellationScenario::make(paidCents: 12000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(CancelBooking::class)($booking);

        $refund = Payment::query()->where('kind', PaymentKind::Refund->value)->sole();

        // AVL-46 and CXL-10 together: the row exists, the money has not moved,
        // and nobody waited on a gateway while a guest watched a spinner.
        expect($refund->status)->toBe(PaymentStatus::Pending)
            ->and($refund->amount_cents)->toBe(6000)
            // §2.5: which charge this reverses. The gateway needs it and so does
            // an operator reconciling two rows against one bank statement.
            ->and($refund->refunds_payment_id)->not->toBeNull();

        Queue::assertPushed(
            ExecuteGatewayRefund::class,
            fn (ExecuteGatewayRefund $job): bool => $job->paymentId === $refund->getKey(),
        );
    });
})->group('fast');

it('does not mark the booking refunded when the gateway refuses', function (): void {
    Event::fake([BookingRefunded::class]);
    CancellationScenario::fakeRefusedRefund('charge_too_old');

    [$tenant, $booking] = CancellationScenario::make(paidCents: 12000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $cancelled = app(CancelBooking::class)($booking);

        $refund = Payment::query()->where('kind', PaymentKind::Refund->value)->sole();

        expect($refund->status)->toBe(PaymentStatus::Failed)
            // The gateway's own code, stored — so the feed can produce the two
            // sentences again later without keeping the prose (PAY-12).
            ->and($refund->failure_code)->toBe('charge_too_old')
            ->and($refund->refunded_at)->toBeNull()
            // CXL-10's clause, asserted directly. The trip is off; the money is
            // not back.
            ->and($cancelled->refresh()->status)->toBe(BookingStatus::Cancelled)
            ->and($cancelled->paid_cents)->toBe(12000)
            ->and($cancelled->refunded_cents)->toBe(0);

        // And no audit row claiming a guest was refunded when they were not.
        Event::assertNotDispatched(BookingRefunded::class);
    });
})->group('fast');

it('surfaces a failed refund in the operator error feed, and nothing else', function (): void {
    CancellationScenario::fakeRefusedRefund();

    [$tenant, $booking] = CancellationScenario::make(paidCents: 12000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // A declined card on an unrelated booking. It is a payment failure and
        // it is **not** what CXL-10 asks an operator to act on: a feed that
        // shows both shows mostly declined cards, and the one row that needs a
        // person is indistinguishable from the forty that do not.
        Payment::factory()->create([
            'booking_id' => $booking->getKey(),
            'kind' => PaymentKind::Deposit,
            'status' => PaymentStatus::Failed,
            'failure_code' => 'card_declined',
        ]);

        app(CancelBooking::class)($booking);

        $feed = Payment::query()->needingAttention()->get();

        expect($feed)->toHaveCount(1)
            ->and($feed->first()?->kind)->toBe(PaymentKind::Refund);
    });
})->group('fast');

it('describes the failure to an operator in Greek and English', function (): void {
    CancellationScenario::fakeRefusedRefund('charge_too_old');

    [$tenant, $booking] = CancellationScenario::make(paidCents: 12000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(CancelBooking::class)($booking);

        $refund = Payment::query()->needingAttention()->sole();

        $described = app(GatewayResolver::class)
            ->named($refund->gateway)
            ->describeError((string) $refund->failure_code);

        // I18N-1 and PAY-12: both languages, always, and the operator sentence
        // is not the guest's. A guest never reads a gateway's English error
        // written for a developer by a company they have never heard of.
        expect($described->forOperator('el'))->not->toBe('')
            ->and($described->forOperator('en'))->not->toBe('')
            ->and($described->forOperator('el'))->not->toBe($described->forOperator('en'));
    });
})->group('fast');

it('settles the booking when the refund goes through', function (): void {
    CancellationScenario::fakeGatewayResponses();

    [$tenant, $booking] = CancellationScenario::make(
        paidCents: 12000,
        ladder: [15 => 100, 7 => 100, 2 => 100],
    );

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(CancelBooking::class)($booking);

        $refund = Payment::query()->where('kind', PaymentKind::Refund->value)->sole();

        expect($refund->status)->toBe(PaymentStatus::Succeeded)
            ->and($refund->refunded_at)->not->toBeNull();

        // PAY-10: recomputed from the payment rows, never incremented. Fully
        // refunded, so `refunded` rather than `cancelled` — a partial refund
        // would leave it `cancelled`, which is the honest description of a trip
        // that is off and money that has only partly gone back.
        expect($booking->refresh()->paid_cents)->toBe(0)
            ->and($booking->refunded_cents)->toBe(12000)
            ->and($booking->status)->toBe(BookingStatus::Refunded);
    });
})->group('fast');

it('is idempotent per payment row, so a replayed job refunds once', function (): void {
    CancellationScenario::fakeGatewayResponses();

    [$tenant, $booking] = CancellationScenario::make(paidCents: 12000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(CancelBooking::class)($booking);

        $refund = Payment::query()->where('kind', PaymentKind::Refund->value)->sole();
        $settledAt = $refund->refunded_at?->toIso8601String();

        // The job again, by hand — a retry after a worker died between the
        // gateway call and the acknowledgement, which is the case CXL-10's
        // idempotency clause is about.
        app(ExecuteGatewayRefund::class, ['paymentId' => $refund->getKey()])
            ->handle(app(GatewayResolver::class));

        expect(Payment::query()->where('kind', PaymentKind::Refund->value)->count())->toBe(1)
            ->and($refund->refresh()->refunded_at?->toIso8601String())->toBe($settledAt)
            ->and($booking->refresh()->refunded_cents)->toBe(6000);
    });
})->group('fast');

it('does not queue a second refund when one has already settled', function (): void {
    CancellationScenario::fakeGatewayResponses();

    [$tenant, $booking] = CancellationScenario::make(paidCents: 12000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        app(CancelBooking::class)($booking);

        // Cancelling again — a double click, a webhook replay, an operator
        // pressing the button twice. The booking is already cancelled, so this
        // returns early; the assertion is that nothing new was written.
        app(CancelBooking::class)($booking->refresh());

        expect(Payment::query()->where('kind', PaymentKind::Refund->value)->count())->toBe(1);
    });
})->group('fast');
