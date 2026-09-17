<?php

declare(strict_types=1);

use App\Domain\Notifications\Actions\SendDueReminders;
use App\Enums\CancelReason;
use App\Enums\GuestDetailsStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationTemplate;
use App\Enums\QuoteStatus;
use App\Enums\WeatherChoice;
use App\Events\QuoteSent;
use App\Events\WeatherChoiceApplied;
use App\Events\WeatherChoiceReminderDue;
use App\Events\WeatherChoiceRequested;
use App\Mail\GuestMail;
use App\Models\Booking;
use App\Models\NotificationLog;
use App\Models\Quote;
use App\Models\QuoteLineItem;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Booking\GuestPageScenario;

/*
|--------------------------------------------------------------------------
| The emails that had a template and no sender (product owner, 2026-09-17)
|--------------------------------------------------------------------------
|
| The gallery of every email showed five that nothing ever sent: the passenger
| details request, the three weather messages and the quote. Each is now wired
| to the event or sweep that should have sent it, and each is asserted here
| through that event, not by constructing the mail by hand.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Mail::fake();
    Carbon::setTestNow('2026-07-01 11:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return list<string> the mail templates logged for this booking */
function sentTemplates(Tenant $tenant, Booking $booking): array
{
    return Tenancy::forTenant($tenant, fn (): array => NotificationLog::query()
        ->where('booking_id', $booking->getKey())
        ->where('channel', NotificationChannel::Mail->value)
        ->pluck('template')
        ->map(static fn (mixed $template): string => $template instanceof NotificationTemplate ? $template->value : (string) $template)
        ->all());
}

function weatherCancelled(Tenant $tenant, Booking $booking): void
{
    Tenancy::forTenant($tenant, fn () => $booking->forceFill([
        'cancel_reason' => CancelReason::Weather,
        'weather_choice_due_at' => now()->addDays(14),
    ])->save());
}

it('asks the guest to choose when the weather cancels their trip', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking();
    weatherCancelled($tenant, $booking);

    WeatherChoiceRequested::dispatch($booking->getKey(), $tenant->getKey(), 12000, now()->addDays(14));

    expect(sentTemplates($tenant, $booking))->toBe([NotificationTemplate::WeatherChoiceRequested->value]);

    Mail::assertSent(GuestMail::class, fn (GuestMail $mail): bool => $mail->template === NotificationTemplate::WeatherChoiceRequested
        && $mail->extra['entitlement_cents'] === 12000);
})->group('fast');

it('reminds the guest who has not chosen, and not the one who has', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking();
    weatherCancelled($tenant, $booking);

    WeatherChoiceReminderDue::dispatch($booking->getKey(), $tenant->getKey(), now()->addDays(11));

    expect(sentTemplates($tenant, $booking))->toBe([NotificationTemplate::WeatherChoiceReminder->value]);

    [$tenant2, $chosen] = GuestPageScenario::booking();
    weatherCancelled($tenant2, $chosen);
    Tenancy::forTenant($tenant2, fn () => $chosen->forceFill(['weather_choice' => WeatherChoice::Refund])->save());

    WeatherChoiceReminderDue::dispatch($chosen->getKey(), $tenant2->getKey(), now()->addDays(11));

    expect(sentTemplates($tenant2, $chosen))->toBe([]);
})->group('fast');

it('tells the guest when the deadline chose for them, and only then', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking();
    weatherCancelled($tenant, $booking);

    // The guest's own click: the booking page already confirmed it.
    WeatherChoiceApplied::dispatch($booking->getKey(), $tenant->getKey(), WeatherChoice::Refund, 12000, false);

    expect(sentTemplates($tenant, $booking))->toBe([]);

    WeatherChoiceApplied::dispatch($booking->getKey(), $tenant->getKey(), WeatherChoice::Voucher, 12000, true);

    expect(sentTemplates($tenant, $booking))->toBe([NotificationTemplate::WeatherChoiceApplied->value]);
})->group('fast');

it('emails every version of a quote the operator sends', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 0);

    [$first, $second] = Tenancy::forTenant($tenant, function () use ($booking): array {
        $first = Quote::factory()->sent()->create(['booking_id' => $booking->getKey()]);
        QuoteLineItem::factory()->create(['quote_id' => $first->getKey()]);
        $second = Quote::factory()->sent()->create(['booking_id' => $booking->getKey(), 'version' => 2]);
        QuoteLineItem::factory()->create(['quote_id' => $second->getKey()]);

        return [$first, $second];
    });

    QuoteSent::dispatch($first->getKey(), $booking->getKey(), $tenant->getKey());
    QuoteSent::dispatch($second->getKey(), $booking->getKey(), $tenant->getKey());

    expect(sentTemplates($tenant, $booking))->toHaveCount(2);

    Mail::assertSent(GuestMail::class, fn (GuestMail $mail): bool => $mail->template === NotificationTemplate::QuoteSent
        && $mail->extra['quote']->is($second));
})->group('fast');

it('does not email a quote version replaced before the queue reached it', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 0);

    $quote = Tenancy::forTenant($tenant, fn (): Quote => Quote::factory()->create([
        'booking_id' => $booking->getKey(),
        // Replaced by a newer version, which `SendQuote` marks expired.
        'status' => QuoteStatus::Expired,
    ]));

    QuoteSent::dispatch($quote->getKey(), $booking->getKey(), $tenant->getKey());

    expect(sentTemplates($tenant, $booking))->toBe([]);
})->group('fast');

it('asks for missing passenger details a day after confirmation', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(documentsRequired: true);

    // Departure 2026-07-10: the 48-hour details reminder is not due for days.
    Tenancy::forTenant($tenant, fn () => $booking->forceFill([
        'starts_at_utc' => Carbon::parse('2026-07-10 06:00:00'),
        'local_date' => '2026-07-10',
        'confirmed_at' => Carbon::parse('2026-06-30 09:00:00'),
    ])->save());

    app(SendDueReminders::class)();
    app(SendDueReminders::class)();

    expect(array_count_values(sentTemplates($tenant, $booking))[NotificationTemplate::GuestDetailsRequested->value] ?? 0)->toBe(1);
})->group('fast');

it('does not ask for details on the day of confirmation, or once they are complete', function (string $case): void {
    [$tenant, $booking] = GuestPageScenario::booking(documentsRequired: true);

    Tenancy::forTenant($tenant, fn () => $booking->forceFill([
        'starts_at_utc' => Carbon::parse('2026-07-10 06:00:00'),
        'local_date' => '2026-07-10',
        'confirmed_at' => $case === 'just confirmed' ? now()->subHours(2) : Carbon::parse('2026-06-30 09:00:00'),
        'guest_details_status' => $case === 'complete' ? GuestDetailsStatus::Complete : GuestDetailsStatus::Pending,
    ])->save());

    app(SendDueReminders::class)();

    expect(sentTemplates($tenant, $booking))->not->toContain(NotificationTemplate::GuestDetailsRequested->value);
})->with(['just confirmed', 'complete'])->group('fast');

it('leaves the request to the 48-hour reminder when that one is already due', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(documentsRequired: true);

    // Departure 2026-07-04 06:00 UTC, details due 48h before: the 48-hour
    // reminder opened on 2026-06-30.
    Tenancy::forTenant($tenant, fn () => $booking->forceFill([
        'confirmed_at' => Carbon::parse('2026-06-29 09:00:00'),
    ])->save());

    app(SendDueReminders::class)();

    $sent = sentTemplates($tenant, $booking);

    expect($sent)->toContain(NotificationTemplate::GuestDetailsReminder48h->value)
        ->and($sent)->not->toContain(NotificationTemplate::GuestDetailsRequested->value);
})->group('fast');
