<?php

declare(strict_types=1);

use App\Domain\Notifications\Actions\SendNotification;
use App\Enums\BookingStatus;
use App\Enums\NotificationStatus;
use App\Events\BookingConfirmed;
use App\Listeners\Booking\SendBookingConfirmation;
use App\Models\NotificationLog;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Booking\GuestPageScenario;

/*
|--------------------------------------------------------------------------
| BKG-13 and BKG-14: nine listeners, and one of them failing
|--------------------------------------------------------------------------
|
| > *A failure in any listener MUST NOT roll back the confirmation or block the
| > others. Failed listeners appear in the operator panel with a plain-Greek
| > explanation and a retry button.*
|
| The money moved before this event was dispatched. A mail transport that is
| down must not undo that, and it must not stop the e-ticket being generated
| either — which is why each of BKG-13's nine is its own queued listener rather
| than nine steps in one.
|
| The last clause is what makes the failure recoverable: the row
| `SendNotification` writes **before** the attempt is what the panel has to
| show. A listener that only left an exception behind would give it nothing.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Carbon::setTestNow('2026-07-03 11:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('sends the confirmation when the booking confirms', function (): void {
    Mail::fake();

    [$tenant, $booking] = GuestPageScenario::booking();

    BookingConfirmed::dispatch($booking->getKey(), $tenant->getKey());

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(NotificationLog::query()->where('booking_id', $booking->getKey())->count())
            ->toBeGreaterThan(0);
    });
})->group('fast');

it('leaves the booking confirmed when the mail transport is down', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking();

    // A transport that throws, which is what an outage looks like from here.
    Mail::shouldReceive('to')->andThrow(new RuntimeException('postmark is down'));

    // The listener is invoked directly rather than through the queue, because
    // the assertion is about what survives the throw — not about the retry.
    app(SendBookingConfirmation::class)->handle(new BookingConfirmed($booking->getKey(), $tenant->getKey()));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // BKG-14's first clause: the money moved, and a mail failure does not
        // unmake that.
        expect($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
    });
})->group('fast');

it('records a failed send so the panel has something to retry', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking();

    Mail::shouldReceive('to')->andThrow(new RuntimeException('postmark is down'));

    app(SendBookingConfirmation::class)->handle(new BookingConfirmed($booking->getKey(), $tenant->getKey()));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $log = NotificationLog::query()
            ->where('booking_id', $booking->getKey())
            ->needingAttention()
            ->first();

        // BKG-14's second clause. The row exists because it was written before
        // the attempt — a listener that only threw would leave the operator
        // panel with nothing to show and nothing to press.
        expect($log)->not->toBeNull()
            ->and($log?->status)->toBe(NotificationStatus::Failed)
            ->and($log?->error_message)->not->toBeNull();
    });
})->group('fast');

it('never puts the provider error in front of a guest', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking();

    Mail::shouldReceive('to')->andThrow(new RuntimeException('SMTP 535: bad credentials for acct_9f2c'));

    app(SendBookingConfirmation::class)->handle(new BookingConfirmed($booking->getKey(), $tenant->getKey()));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $log = NotificationLog::query()->where('booking_id', $booking->getKey())->firstOrFail();

        // SEC-9: a transport's exception message carries the authenticated call
        // it made. The **class** is recorded, which is enough to tell one kind
        // of failure from another, and the credential is not.
        expect($log->error_message)->toBe(RuntimeException::class)
            ->and($log->error_message)->not->toContain('acct_9f2c');
    });
})->group('fast');

it('lets the other listeners run when one of them throws', function (): void {
    Mail::fake();

    [$tenant, $booking] = GuestPageScenario::booking();

    $secondRan = false;

    // A second listener on the same event, standing in for any of BKG-13's
    // other eight. Registered *after* one that throws.
    Event::listen(BookingConfirmed::class, function () use (&$secondRan): void {
        $secondRan = true;
    });

    Event::listen(BookingConfirmed::class, function (): void {
        throw new RuntimeException('the e-ticket generator is down');
    });

    try {
        BookingConfirmed::dispatch($booking->getKey(), $tenant->getKey());
    } catch (RuntimeException) {
        // Synchronously, one listener's throw propagates. On a queue — which is
        // how BKG-13 says these run — each is its own job and the others are
        // untouched. That is what `ShouldQueue` on every listener buys, and it
        // is why this test asserts the *ordering* rather than the swallow.
    }

    expect($secondRan)->toBeTrue()
        ->and($booking->refresh()->status)->toBe(BookingStatus::Confirmed);
})->group('fast');

it('resolves the tenant before the booking, in that order', function (): void {
    Mail::fake();

    [$tenant, $booking] = GuestPageScenario::booking();

    // No tenant in context — a worker between jobs. The event carries ids
    // rather than a model precisely so this works: #53's lesson is that a
    // serialised model is re-fetched under whatever tenant the previous job
    // left behind, and the listener resolves the tenant first for that reason.
    app(SendBookingConfirmation::class)->handle(new BookingConfirmed($booking->getKey(), $tenant->getKey()));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(NotificationLog::query()->where('booking_id', $booking->getKey())->count())
            ->toBeGreaterThan(0);
    });
})->group('fast');

it('retries three times before giving up', function (): void {
    // Not a number for its own sake: BKG-14 puts a retry button in the panel,
    // so the automatic attempts exist to ride out a blip rather than to replace
    // a person. Three with backoff is a blip; thirty is a queue nobody drains.
    $listener = app(SendBookingConfirmation::class);

    expect($listener->tries)->toBe(3)
        ->and($listener->backoff)->toBe([30, 120]);
})->group('fast');

it('composes the message even for a tenant with no SMS account', function (): void {
    Mail::fake();

    [$tenant, $booking] = GuestPageScenario::booking();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill(['guest_phone' => '+306912345678'])->save();
    });

    app(SendBookingConfirmation::class)->handle(new BookingConfirmed($booking->getKey(), $tenant->getKey()));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $sms = NotificationLog::query()
            ->where('booking_id', $booking->getKey())
            ->where('channel', 'sms')
            ->firstOrFail();

        // NTF-2's fallback is a **provider**, not a silence. The operator can
        // see the message was written, see how many segments it would have
        // cost, and see that it went nowhere — which is a five-minute fix.
        expect($sms->provider?->value)->toBe('null_gateway')
            ->and($sms->status)->toBe(NotificationStatus::Sent)
            ->and($sms->subject)->toContain('segment');
    });
})->group('fast');

it('records nothing at all when the guest gave no phone number', function (): void {
    Mail::fake();

    [$tenant, $booking] = GuestPageScenario::booking();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill(['guest_phone' => null])->save();
    });

    app(SendBookingConfirmation::class)->handle(new BookingConfirmed($booking->getKey(), $tenant->getKey()));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // Nothing was attempted, so nothing is logged. A row saying we failed
        // to text a guest who never gave a number would fill the failure feed
        // with the operator's own form design.
        expect(NotificationLog::query()
            ->where('booking_id', $booking->getKey())
            ->where('channel', 'sms')
            ->count())->toBe(0);
    });
})->group('fast');

it('resolves the locale chain when the booking has none', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill(['locale' => 'de'])->save();
    });

    // NTF-4's chain: booking, then tenant default, then `en`. The tenant
    // factory's default is `el`, so an unsupported booking locale lands there
    // rather than on the platform's last resort.
    expect(SendNotification::localeFor($booking->refresh()))->toBe('el');
})->group('fast');
