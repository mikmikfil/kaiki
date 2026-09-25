<?php

declare(strict_types=1);

use App\Domain\Booking\Actions\CreateBookingDraft;
use App\Domain\Booking\Actions\RefundBooking;
use App\Domain\Booking\Data\BookingDraftData;
use App\Domain\Notifications\Actions\SendPaymentUnfinished;
use App\Enums\BookingStatus;
use App\Enums\CancelReason;
use App\Enums\NotificationTemplate;
use App\Enums\PaymentStatus;
use App\Mail\GuestMail;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Payment;
use App\Models\RatePlan;
use App\Models\RatePlanPrice;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\get;

use Tests\Support\AvailabilityScenarioBuilder;

/*
|--------------------------------------------------------------------------
| «Η κράτησή σας για … δεν ολοκληρώθηκε» — Mike, 2026-09-24, subject A
|--------------------------------------------------------------------------
|
| One email after an abandoned checkout has expired, never a second, and not
| when the guest booked again, the boat filled or it leaves within 3 hours.
| Its button makes a fresh booking that checks the seats again.
|
*/

beforeEach(function (): void {
    Mail::fake();
    Carbon::setTestNow('2026-07-01 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array{0: Tenant, 1: Booking, 2: Departure} */
function abandonedCheckout(): array
{
    $tenant = Tenant::factory()->create();

    [$booking, $departure] = Tenancy::forTenant($tenant, static function (): array {
        $builder = AvailabilityScenarioBuilder::make();
        $product = $builder->product();

        RatePlanPrice::factory()->create([
            'rate_plan_id' => RatePlan::query()->where('product_id', $product->getKey())->firstOrFail()->getKey(),
            'age_band_id' => $product->ageBands->firstWhere('code', 'adult')?->getKey(),
        ]);

        $departure = $builder->departure($product, '2026-07-04', '09:00');

        $booking = app(CreateBookingDraft::class)(new BookingDraftData(
            product: $product,
            date: Carbon::parse('2026-07-04'),
            guestName: 'Μαρία Παπαδοπούλου',
            guestEmail: 'maria@example.gr',
            paxByCode: ['adult' => 2],
            startTime: (string) $departure->local_time,
            termsAcceptedAt: now(),
        ));

        // What ExpireAbandonedCheckouts leaves behind: expired, seats back.
        $booking->forceFill(['status' => BookingStatus::Expired, 'cancel_reason' => CancelReason::PaymentFailed, 'hold_expires_at' => null])->save();
        $departure->forceFill(['seats_held' => 0, 'seats_sold' => 0])->save();

        return [$booking, $departure];
    });

    return [$tenant, $booking, $departure];
}

it('sends one email after the checkout expired, and never a second', function (): void {
    [, $booking] = abandonedCheckout();

    expect(app(SendPaymentUnfinished::class)())->toBe(1)
        ->and(app(SendPaymentUnfinished::class)())->toBe(0);

    Mail::assertSent(GuestMail::class, static fn (GuestMail $mail): bool => $mail->template === NotificationTemplate::PaymentUnfinished
        && $mail->booking->is($booking));
})->group('fast');

it('says the trip in the subject and sends the button to the checkout', function (): void {
    [$tenant, $booking] = abandonedCheckout();

    [$subject, $html] = Tenancy::forTenant($tenant, static function () use ($booking): array {
        $mail = new GuestMail($booking->refresh(), NotificationTemplate::PaymentUnfinished);

        return [$mail->envelope()->subject, $mail->render()];
    });

    expect($subject)->toContain((string) $booking->product->title)
        ->and($html)->toContain(route('guest.checkout', ['token' => $booking->manage_token]))
        ->and($html)->toContain(__('mail.payment_unfinished.button', [], 'el'));
})->group('fast');

it('does not send for a test booking, a full boat, or a boat leaving within 3 hours', function (): void {
    [$tenant, $booking, $departure] = abandonedCheckout();

    Tenancy::forTenant($tenant, static fn () => $booking->forceFill(['is_test' => true])->save());
    expect(app(SendPaymentUnfinished::class)())->toBe(0);

    Tenancy::forTenant($tenant, static function () use ($booking, $departure): void {
        $booking->forceFill(['is_test' => false])->save();
        $departure->forceFill(['seats_sold' => $departure->capacity])->save();
    });
    expect(app(SendPaymentUnfinished::class)())->toBe(0);

    Tenancy::forTenant($tenant, static fn () => $departure->forceFill(['seats_sold' => 0])->save());
    Carbon::setTestNow($booking->starts_at_utc->copy()->subHours(2));
    expect(app(SendPaymentUnfinished::class)())->toBe(0);
})->group('fast');

it('makes a fresh booking with the same party from the button, once', function (): void {
    [$tenant, $booking] = abandonedCheckout();

    $response = get(route('guest.checkout', ['token' => $booking->manage_token]));

    $fresh = Tenancy::forTenant($tenant, static fn (): ?Booking => Booking::query()->whereKeyNot($booking->getKey())->first());

    expect($fresh)->not->toBeNull()
        ->and($fresh?->status)->toBe(BookingStatus::Draft)
        ->and($fresh?->pax_total)->toBe(2)
        ->and($fresh?->guest_email)->toBe('maria@example.gr');

    $response->assertRedirect(route('guest.checkout', ['token' => $fresh?->manage_token]));

    // Pressed again: the same booking, not a third.
    get(route('guest.checkout', ['token' => $booking->manage_token]))
        ->assertRedirect(route('guest.checkout', ['token' => $fresh?->manage_token]));

    expect(Tenancy::forTenant($tenant, static fn (): int => Booking::query()->count()))->toBe(2);

    // And the guest who has booked again gets no email about the old one.
    expect(app(SendPaymentUnfinished::class)())->toBe(0);
})->group('fast');

it('says why, on the old booking page, when the boat has filled', function (): void {
    [$tenant, $booking, $departure] = abandonedCheckout();

    Tenancy::forTenant($tenant, static fn () => $departure->forceFill(['seats_sold' => $departure->capacity])->save());

    get(route('guest.checkout', ['token' => $booking->manage_token]))
        ->assertRedirect(route('guest.booking', ['token' => $booking->manage_token]))
        ->assertSessionHas('resume_refused');
})->group('fast');

it('does not say the payment was unfinished when the money came in late and went back', function (): void {
    [$tenant, $booking] = abandonedCheckout();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        // Audit 2: paid after the checkout lapsed, the seats gone, refunded.
        $charge = Payment::factory()->create(['booking_id' => $booking->getKey(), 'amount_cents' => 13000]);

        Payment::factory()->refundOf($charge)->create([
            'status' => PaymentStatus::Pending,
            'refunded_at' => null,
            'idempotency_key' => RefundBooking::LATE_KEY_PREFIX . str_repeat('a', 32),
        ]);
    });

    expect(app(SendPaymentUnfinished::class)())->toBe(0);

    Mail::assertNothingSent();
})->group('fast');

it('does not offer a charter back once its boat has gone to somebody else', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        $vessel = Vessel::factory()->create();

        Booking::factory()->perVessel()->create([
            'vessel_id' => $vessel->getKey(),
            'status' => BookingStatus::Expired,
            'cancel_reason' => CancelReason::PaymentFailed,
        ]);

        // Somebody else chartered the same boat for the same day.
        Booking::factory()->perVessel()->create([
            'vessel_id' => $vessel->getKey(),
            'status' => BookingStatus::Confirmed,
            'guest_email' => 'other@example.gr',
        ]);
    });

    expect(app(SendPaymentUnfinished::class)())->toBe(0);

    Mail::assertNothingSent();
})->group('fast');
