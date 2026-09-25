<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\ConfirmBooking;
use App\Domain\Booking\Actions\SaveGuestDetails;
use App\Domain\Booking\Support\GuestDetailsTracking;
use App\Domain\Notifications\Actions\SendDueReminders;
use App\Enums\GuestDetailsStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationTemplate;
use App\Mail\Support\BookingMailDetails;
use App\Models\Booking;
use App\Models\NotificationLog;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\postJson;

use Tests\Support\Api\BookingApiScenario;
use Tests\Support\Api\CatalogRequest;

/*
|--------------------------------------------------------------------------
| The passenger list is chased (BKG-15/16, ν. 4926/2022 άρθρο 13 — 2026-09-25)
|--------------------------------------------------------------------------
|
| Every booking used to be written `not_required` and nothing moved it, so the
| manifest reminders, the attention list and the confirmation's `/g/` link —
| all keyed on `pending` — never fired. Tests passed only because a fixture
| forced the status by hand. These start from a real draft.
|
*/

beforeEach(function (): void {
    Mail::fake();
    Carbon::setTestNow('2026-06-01 09:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array{0: Tenant, 1: Booking} */
function manifestDraft(bool $required = true): array
{
    // 1 July, 09:00 UTC: 12:00 on the operator's clock.
    $fixture = BookingApiScenario::bookable(startsAt: Carbon::parse('2026-07-01 09:00:00'));

    Tenancy::forTenant($fixture['tenant'], function () use ($fixture, $required): void {
        $fixture['product']->forceFill([
            'guest_details_required' => $required,
            'guest_details_deadline_hours' => 48,
        ])->save();
    });

    $created = postJson(
        CatalogRequest::url('/bookings'),
        BookingApiScenario::body($fixture['product'], $fixture['departure'], $fixture['band']),
        ['Authorization' => "Bearer {$fixture['key']}", 'Idempotency-Key' => BookingApiScenario::idempotencyKey()],
    )->assertCreated();

    $booking = Tenancy::forTenant(
        $fixture['tenant'],
        fn (): Booking => Booking::query()->where('uuid', $created->json('data.uuid'))->sole(),
    );

    return [$fixture['tenant'], $booking];
}

it('starts a booking on a trip that asks for passengers as pending, with no link yet', function (): void {
    [, $booking] = manifestDraft();

    expect($booking->guest_details_status)->toBe(GuestDetailsStatus::Pending)
        // §2.5: lazy. A draft nobody pays for leaves no live URL behind.
        ->and($booking->guest_details_token)->toBeNull();

    [, $other] = manifestDraft(required: false);

    expect($other->guest_details_status)->toBe(GuestDetailsStatus::NotRequired);
})->group('fast');

it('gives a confirmed booking its deadline and its /g/ link, and the confirmation carries it', function (): void {
    [$tenant, $booking] = manifestDraft();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $confirmed = app(ConfirmBooking::class)($booking);

        expect($confirmed->guest_details_status)->toBe(GuestDetailsStatus::Pending)
            ->and($confirmed->guest_details_token)->not->toBeNull()
            ->and($confirmed->guest_details_deadline_at?->toIso8601String())->toBe('2026-06-29T09:00:00+00:00');

        $mail = BookingMailDetails::for($confirmed, NotificationTemplate::BookingConfirmed, 'el');

        expect($mail->detailsUrl)->toBe(route('guest.details', ['token' => $confirmed->guest_details_token]));
    });
})->group('fast');

it('sends the 48-hour manifest reminder from the sweep', function (): void {
    [$tenant, $booking] = manifestDraft();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // A test key makes a test booking, which the sweep leaves alone.
        $booking->forceFill(['is_test' => false])->save();

        app(ConfirmBooking::class)($booking);
    });

    // Deadline 29 June 09:00 UTC; its 48-hour reminder falls due on 27 June
    // 09:00 UTC. An hour later, 13:00 in Athens.
    Carbon::setTestNow('2026-06-27 10:00:00');

    app(SendDueReminders::class)();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(NotificationLog::alreadySent($booking->getKey(), NotificationTemplate::GuestDetailsReminder48h, NotificationChannel::Mail))
            ->toBeTrue();
    });
})->group('fast');

it('does not trust a stored not_required on a trip that asks for passengers', function (): void {
    [$tenant, $booking] = manifestDraft();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // A booking written before this fix, or by an import.
        $booking->forceFill(['guest_details_status' => GuestDetailsStatus::NotRequired])->save();

        expect(app(SaveGuestDetails::class)->syncStatus($booking))->toBe(GuestDetailsStatus::Pending);

        $booking->forceFill(['guest_details_status' => GuestDetailsStatus::NotRequired, 'guest_details_token' => null])->save();

        GuestDetailsTracking::open($booking);

        expect($booking->refresh()->guest_details_status)->toBe(GuestDetailsStatus::Pending)
            ->and($booking->guest_details_token)->not->toBeNull()
            ->and($booking->guest_details_deadline_at)->not->toBeNull();
    });
})->group('fast');

it('sends the balance reminder\'s button to the booking page', function (): void {
    [$tenant, $booking] = manifestDraft(required: false);

    Tenancy::forTenant($tenant, function () use ($booking): void {
        $booking->forceFill(['balance_cents' => 5000, 'balance_due_at' => now()->addDays(5)])->save();

        $mail = BookingMailDetails::for($booking->refresh(), NotificationTemplate::BalanceDueReminder, 'el');

        // PAY-4: an emailed link goes to /b, which mints a fresh session.
        expect($mail->actionUrl)->toBe(route('guest.booking', ['token' => $booking->manage_token]));
    });
})->group('fast');
