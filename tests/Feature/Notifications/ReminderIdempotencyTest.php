<?php

declare(strict_types=1);

use App\Domain\Notifications\Actions\SendDueReminders;
use App\Enums\GuestDetailsStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\NotificationTemplate;
use App\Models\NotificationLog;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Booking\GuestPageScenario;

/*
|--------------------------------------------------------------------------
| BKG-16: run the scheduler twice, send once
|--------------------------------------------------------------------------
|
| > *Reminder schedule for every confirmed booking, all idempotent and all
| > logged in the `Notification` log.*
|
| The idempotency is the **log**, not the queue. A delayed job scheduled at
| confirmation fires whether or not the balance was paid, the details were
| completed, or the booking was cancelled — and nobody can find it to cancel it.
| So the schedule is derived from the booking on every pass and the dedupe is an
| indexed read on `notif_logs_tenant_tmpl_idx`.
|
| That also makes every one of BKG-16's "suppressed when" conditions live: a
| guest who finishes their passport details an hour before the reminder simply
| does not get one, and this file asserts that rather than assuming it.
|
*/

beforeEach(function (): void {
    Mail::fake();
    // 14:00 Athens, comfortably outside BKG-18's window, so this file tests
    // idempotency rather than accidentally testing quiet hours.
    Carbon::setTestNow('2026-07-03 11:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('sends once when the sweeper runs twice', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking();

    // 2026-07-04 06:00 UTC departure; "now" is one day and five hours before,
    // so the pre-departure reminder is due.
    Carbon::setTestNow('2026-07-03 11:00:00');

    app(SendDueReminders::class)();
    app(SendDueReminders::class)();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // One row, not two. `ReminderIdempotencyTest` is named after this line.
        expect(NotificationLog::query()
            ->where('booking_id', $booking->getKey())
            ->where('template', NotificationTemplate::PreDeparture24h->value)
            ->where('channel', NotificationChannel::Mail->value)
            ->count())->toBe(1);
    });
})->group('fast');

it('dedupes per channel, so the email does not swallow the text', function (): void {
    // SMS is off for the first phase (`kaiki.notifications.sms_enabled`).
    // The machinery underneath is deliberately kept built and tested, so
    // this test turns it on rather than being deleted — switching it back
    // on must not be a rebuild.
    config(['kaiki.notifications.sms_enabled' => true]);

    [$tenant, $booking] = GuestPageScenario::booking();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill(['guest_phone' => '+306912345678'])->save();
    });

    app(SendDueReminders::class)();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $rows = NotificationLog::query()
            ->where('booking_id', $booking->getKey())
            ->where('template', NotificationTemplate::PreDeparture24h->value)
            ->get();

        // A dedupe key of (booking, template) alone would let the email
        // suppress the SMS — and the log would look entirely healthy while the
        // guest never got the text.
        expect($rows->pluck('channel')->map->value->sort()->values()->all())
            ->toBe(['mail', 'sms']);
    });
})->group('fast');

it('retries after a failure, because a failed reminder was not sent', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        NotificationLog::factory()->failed()->create([
            'booking_id' => $booking->getKey(),
            'template' => NotificationTemplate::PreDeparture24h,
            'channel' => NotificationChannel::Mail,
        ]);
    });

    app(SendDueReminders::class)();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // Two rows: the failure, and the retry. Counting a `failed` row as sent
        // would turn one provider outage into a message a guest never receives
        // and nobody ever notices.
        expect(NotificationLog::query()
            ->where('booking_id', $booking->getKey())
            ->where('template', NotificationTemplate::PreDeparture24h->value)
            ->where('channel', NotificationChannel::Mail->value)
            ->count())->toBe(2);
    });
})->group('fast');

it('does not retry after a bounce, because the mailbox rejected us', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        NotificationLog::factory()->bounced()->create([
            'booking_id' => $booking->getKey(),
            'template' => NotificationTemplate::PreDeparture24h,
            'channel' => NotificationChannel::Mail,
        ]);
    });

    app(SendDueReminders::class)();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // Re-sending to a mailbox that rejected us produces a second bounce
        // rather than a delivery. NTF-8 flags the booking so a person
        // telephones — which is the only thing that works.
        expect(NotificationLog::query()->where('booking_id', $booking->getKey())->count())->toBe(1);
    });
})->group('fast');

it('suppresses the guest-details reminder once the details are complete', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(documentsRequired: true);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill(['guest_details_status' => GuestDetailsStatus::Complete])->save();
    });

    app(SendDueReminders::class)();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // BKG-16's own "suppressed when" column, evaluated at send time. A
        // delayed job scheduled at confirmation would have fired anyway.
        expect(NotificationLog::query()
            ->where('booking_id', $booking->getKey())
            ->whereIn('template', [
                NotificationTemplate::GuestDetailsReminder48h->value,
                NotificationTemplate::GuestDetailsReminder24h->value,
            ])
            ->count())->toBe(0);
    });
})->group('fast');

it('suppresses the balance reminder once there is nothing to pay', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 12000, balanceCents: 0);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill(['balance_due_at' => now()->subDay()])->save();
    });

    app(SendDueReminders::class)();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(NotificationLog::query()
            ->where('booking_id', $booking->getKey())
            ->whereIn('template', [
                NotificationTemplate::BalanceDueReminder->value,
                NotificationTemplate::BalanceOverdue->value,
            ])
            ->count())->toBe(0);
    });
})->group('fast');

it('does not remind anybody about a boat that has already sailed', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking();

    Carbon::setTestNow('2026-07-05 11:00:00');

    app(SendDueReminders::class)();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(NotificationLog::query()->where('booking_id', $booking->getKey())->count())->toBe(0);
    });
})->group('fast');

it('leaves test bookings entirely alone', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill(['is_test' => true])->save();
    });

    app(SendDueReminders::class)();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // SAA-12: a sandbox booking is excluded from every dashboard figure,
        // every export and every webhook — and from every email, which is the
        // one an operator would notice by receiving it.
        expect(NotificationLog::query()->where('booking_id', $booking->getKey())->count())->toBe(0);
    });
})->group('fast');

it('records the locale it sent in, from the booking rather than the panel', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill(['locale' => 'en'])->save();
    });

    // An operator's panel language, deliberately different. NTF-4: the message
    // follows the guest, not whoever happened to trigger it.
    app()->setLocale('el');

    app(SendDueReminders::class)();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(NotificationLog::query()->where('booking_id', $booking->getKey())->first()?->locale)
            ->toBe('en');
    });
})->group('fast');

it('writes the row before the send, so a crash leaves evidence', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking();

    app(SendDueReminders::class)();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $log = NotificationLog::query()->where('booking_id', $booking->getKey())->firstOrFail();

        // `queued` → `sent`, in that order. A log written only after success is
        // a log of the sends that worked, which is the log nobody needs.
        expect($log->status)->toBe(NotificationStatus::Sent)
            ->and($log->sent_at)->not->toBeNull();
    });
})->group('fast');
