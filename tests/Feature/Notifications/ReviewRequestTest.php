<?php

declare(strict_types=1);

use App\Domain\Notifications\Actions\SendDueReminders;
use App\Domain\Notifications\Support\ReviewRequestSettings;
use App\Enums\BookingStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationTemplate;
use App\Enums\Role;
use App\Filament\App\Pages\ReviewSettings;
use App\Mail\GuestMail;
use App\Models\Booking;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\Booking\GuestPageScenario;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| The Google review request (product owner, 2026-09-17)
|--------------------------------------------------------------------------
|
| «Μετά από 24 ώρες (επιλογή operator) να στέλνεται να μπουν για αξιολόγηση
| στην Google.» Once, after the trip, to guests who sailed, never at night, and
| only when the operator has switched it on and said where the review goes.
|
*/

const REVIEW_URL = 'https://g.page/r/aegean-blue/review';

beforeEach(function (): void {
    Mail::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A guest who sailed: back at 10:00 UTC on 4 July (13:00 in Athens).
 *
 * @param  array<string, mixed>  $settings
 * @return array{0: Tenant, 1: Booking}
 */
function sailedBooking(array $settings = ['enabled' => true, 'delay_hours' => 24, 'google_url' => REVIEW_URL], BookingStatus $status = BookingStatus::CheckedIn): array
{
    [$tenant, $booking] = GuestPageScenario::booking();

    Tenancy::withoutTenancy(static function () use ($tenant, $settings): void {
        $fresh = Tenant::query()->findOrFail($tenant->getKey());
        $fresh->forceFill(['settings' => [...(array) $fresh->settings, ReviewRequestSettings::SETTINGS_KEY => $settings]])->save();
    });

    Tenancy::forTenant($tenant, static function () use ($booking, $status): void {
        $booking->forceFill([
            'status' => $status,
            'starts_at_utc' => '2026-07-04 06:00:00',
            'ends_at_utc' => '2026-07-04 10:00:00',
        ])->save();
    });

    return [$tenant, $booking];
}

function reviewRowsFor(Tenant $tenant, Booking $booking): int
{
    return (int) Tenancy::forTenant($tenant, static fn (): int => NotificationLog::query()
        ->where('booking_id', $booking->getKey())
        ->where('template', NotificationTemplate::ReviewRequest->value)
        ->where('channel', NotificationChannel::Mail->value)
        ->count());
}

it('asks once, the chosen number of hours after the boat is back', function (): void {
    [$tenant, $booking] = sailedBooking();

    // 23 hours after: not yet.
    Carbon::setTestNow('2026-07-05 09:00:00');
    app(SendDueReminders::class)();
    expect(reviewRowsFor($tenant, $booking))->toBe(0);

    // 24 hours after, 13:00 in Athens: sent, and a second pass sends nothing.
    Carbon::setTestNow('2026-07-05 10:00:00');
    app(SendDueReminders::class)();
    app(SendDueReminders::class)();

    expect(reviewRowsFor($tenant, $booking))->toBe(1);
    Mail::assertSent(GuestMail::class, static fn (GuestMail $mail): bool => $mail->template === NotificationTemplate::ReviewRequest);
})->group('fast');

it('links the email to the operator review page', function (): void {
    [$tenant, $booking] = sailedBooking();

    $html = Tenancy::forTenant($tenant, static fn (): string => (new GuestMail($booking->refresh(), NotificationTemplate::ReviewRequest))->render());

    expect($html)->toContain(REVIEW_URL)
        ->and($html)->toContain(__('mail.common.leave_review', [], 'el'))
        ->and($html)->not->toContain('<img');
})->group('fast');

it('sends nothing while switched off, or switched on with no link', function (array $settings): void {
    [$tenant, $booking] = sailedBooking($settings);

    Carbon::setTestNow('2026-07-05 10:00:00');
    app(SendDueReminders::class)();

    expect(reviewRowsFor($tenant, $booking))->toBe(0);
})->with([
    'off' => [['enabled' => false, 'delay_hours' => 24, 'google_url' => REVIEW_URL]],
    'no link' => [['enabled' => true, 'delay_hours' => 24, 'google_url' => null]],
])->group('fast');

it('never asks a cancelled booking or a no-show', function (): void {
    [$cancelledTenant, $cancelled] = sailedBooking(status: BookingStatus::Cancelled);
    [$noShowTenant, $noShow] = sailedBooking(status: BookingStatus::Completed);

    Tenancy::forTenant($noShowTenant, static fn () => $noShow->forceFill(['no_show' => true])->save());

    Carbon::setTestNow('2026-07-05 10:00:00');
    app(SendDueReminders::class)();

    expect(reviewRowsFor($cancelledTenant, $cancelled))->toBe(0)
        ->and(reviewRowsFor($noShowTenant, $noShow))->toBe(0);
})->group('fast');

it('asks a completed booking as well as a checked-in one', function (): void {
    [$tenant, $booking] = sailedBooking(status: BookingStatus::Completed);

    Carbon::setTestNow('2026-07-05 10:00:00');
    app(SendDueReminders::class)();

    expect(reviewRowsFor($tenant, $booking))->toBe(1);
})->group('fast');

it('waits for the morning rather than asking at night', function (): void {
    [$tenant, $booking] = sailedBooking(['enabled' => true, 'delay_hours' => 10, 'google_url' => REVIEW_URL]);

    // Due 20:00 UTC, 23:00 in Athens: held back.
    Carbon::setTestNow('2026-07-04 20:00:00');
    app(SendDueReminders::class)();
    expect(reviewRowsFor($tenant, $booking))->toBe(0);

    // 08:00 in Athens.
    Carbon::setTestNow('2026-07-05 05:00:00');
    app(SendDueReminders::class)();
    expect(reviewRowsFor($tenant, $booking))->toBe(1);
})->group('fast');

it('does not ask about old trips when the switch is first turned on', function (): void {
    [$tenant, $booking] = sailedBooking();

    Carbon::setTestNow('2026-07-20 10:00:00');
    app(SendDueReminders::class)();

    expect(reviewRowsFor($tenant, $booking))->toBe(0);
})->group('fast');

it('saves the settings from the panel, and needs a link to switch on', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $tenant = Tenancy::withoutTenancy(static fn (): Tenant => Tenant::query()->findOrFail($owner->tenant_id));
    tenancy()->initialize($tenant);

    Livewire::actingAs($owner)
        ->test(ReviewSettings::class)
        ->assertFormSet(['enabled' => false, 'delay_hours' => 24])
        ->fillForm(['enabled' => true, 'delay_hours' => 24, 'google_url' => ''])
        ->call('save')
        ->assertHasFormErrors(['google_url' => 'required'])
        ->fillForm(['enabled' => true, 'delay_hours' => 36, 'google_url' => REVIEW_URL])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = ReviewRequestSettings::for($tenant->refresh());

    expect($settings->active())->toBeTrue()
        ->and($settings->delayHours)->toBe(36)
        ->and($settings->googleUrl)->toBe(REVIEW_URL);
})->group('fast');
