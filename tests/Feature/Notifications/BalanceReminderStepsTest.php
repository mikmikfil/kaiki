<?php

declare(strict_types=1);

use App\Domain\Notifications\Actions\SendDueReminders;
use App\Enums\NotificationChannel;
use App\Enums\NotificationTemplate;
use App\Mail\GuestMail;
use App\Mail\Support\BookingMailDetails;
use App\Models\Booking;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Booking\GuestPageScenario;

/*
|--------------------------------------------------------------------------
| The balance reminder, at −7 days and again at −1 day (2026-09-25)
|--------------------------------------------------------------------------
|
| Both steps are one template, and the dedupe asked about the template: the
| −7 row suppressed the −1 for good, and the reminder the day before never
| went. Each step has its own key now.
|
*/

beforeEach(function (): void {
    Mail::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A booking owing €80, due on 1 August at 09:00 Athens, sailing on 15 August.
 *
 * @return array{0: Tenant, 1: Booking}
 */
function remindedBooking(): array
{
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 4000, balanceCents: 8000);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill([
            'starts_at_utc' => Carbon::parse('2026-08-15 06:00:00'),
            'ends_at_utc' => Carbon::parse('2026-08-15 10:00:00'),
            'local_date' => '2026-08-15',
            'balance_due_at' => Carbon::parse('2026-08-01 06:00:00'),
        ])->save();
    });

    return [$tenant, $booking];
}

function balanceReminders(Tenant $tenant, Booking $booking): int
{
    return Tenancy::forTenant($tenant, static fn (): int => NotificationLog::query()
        ->where('booking_id', $booking->getKey())
        ->where('template', NotificationTemplate::BalanceDueReminder->value)
        ->where('channel', NotificationChannel::Mail->value)
        ->count());
}

it('sends the reminder a week before and again the day before', function (): void {
    [$tenant, $booking] = remindedBooking();

    // 25 July, 14:00 Athens: the −7 step is due.
    Carbon::setTestNow('2026-07-25 11:00:00');
    app(SendDueReminders::class)();
    app(SendDueReminders::class)();

    expect(balanceReminders($tenant, $booking))->toBe(1);

    // 30 July: nothing new is due.
    Carbon::setTestNow('2026-07-30 11:00:00');
    app(SendDueReminders::class)();

    expect(balanceReminders($tenant, $booking))->toBe(1);

    // 31 July, 14:00 Athens: the −1 step, once however often the sweep runs.
    Carbon::setTestNow('2026-07-31 11:00:00');
    app(SendDueReminders::class)();
    app(SendDueReminders::class)();

    expect(balanceReminders($tenant, $booking))->toBe(2);

    Mail::assertSent(GuestMail::class, 2);
})->group('fast');

it('sends one reminder, not two, to a booking made inside the last day', function (): void {
    [$tenant, $booking] = remindedBooking();

    // Both steps are already due on the first pass.
    Carbon::setTestNow('2026-07-31 11:00:00');
    app(SendDueReminders::class)();
    app(SendDueReminders::class)();

    expect(balanceReminders($tenant, $booking))->toBe(1);
})->group('fast');

it('writes the due date in the email on the operator\'s calendar, not UTC\'s', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 4000, balanceCents: 8000);

    $details = Tenancy::forTenant($tenant, function () use ($booking): BookingMailDetails {
        // 22:30 UTC on Wednesday 1 July is 01:30 on Thursday 2 July in Athens.
        $booking->forceFill(['balance_due_at' => Carbon::parse('2026-07-01 22:30:00', 'UTC')])->save();

        return BookingMailDetails::for($booking->refresh(), NotificationTemplate::BookingConfirmed, 'en');
    });

    expect($details->balanceDue)->toBe('Thursday 2/7');
})->group('fast');
