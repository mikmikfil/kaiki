<?php

declare(strict_types=1);

use App\Enums\BookingMode;
use App\Enums\NotificationTemplate;
use App\Enums\VoucherReason;
use App\Mail\GuestMail;
use App\Models\Booking;
use App\Models\Quote;
use App\Models\QuoteLineItem;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Tests\Support\Booking\GuestPageScenario;

/*
|--------------------------------------------------------------------------
| What each email says, after the gallery review (product owner, 2026-09-17)
|--------------------------------------------------------------------------
|
| Every message rendered for the product owner, and each one checked for the
| facts it exists to carry. These are the ones that were wrong: a cancellation
| that showed «Υπόλοιπο», a voucher reminder with no code, a quote with no
| price, a balance reminder with no date, a charter deadline on the charter
| day, «ο διαχειριστής» where the operator has a name.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-01 11:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Both halves of one message, in Greek.
 *
 * @param  array<string, mixed>  $extra
 * @return array{0: string, 1: string}
 */
function greekMail(Tenant $tenant, Booking $booking, NotificationTemplate $template, array $extra = []): array
{
    return Tenancy::forTenant($tenant, function () use ($booking, $template, $extra): array {
        $booking->forceFill(['locale' => 'el'])->save();

        $mail = new GuestMail($booking->refresh(), $template, $extra);
        $html = $mail->render();

        $previous = app()->getLocale();
        app()->setLocale('el');
        $text = view('mail.booking.text', [
            'booking' => $booking,
            'template' => $template,
            'brand' => ['tenant' => ['name' => $booking->tenant?->name]],
            'extra' => $extra,
        ])->render();
        app()->setLocale($previous);

        return [html_entity_decode($html), $text];
    });
}

it('says what was refunded and where on a cancellation, and no balance', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 8000, balanceCents: 4000);

    [$html, $text] = greekMail($tenant, $booking, NotificationTemplate::BookingCancelled, ['refunded_cents' => 6000]);

    foreach ([$html, $text] as $body) {
        expect($body)->toContain('60,00 €')
            ->and($body)->toContain('Στον τρόπο πληρωμής που χρησιμοποιήσατε')
            ->and($body)->not->toContain('Υπόλοιπο');
    }
})->group('fast');

it('names the voucher code when the refund was a voucher', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 8000);

    Tenancy::forTenant($tenant, fn () => Voucher::factory()->create([
        'code' => 'GIFT-ABC123',
        'issued_for_booking_id' => $booking->getKey(),
        'reason' => VoucherReason::OperatorCancellation,
    ]));

    [$html, $text] = greekMail($tenant, $booking, NotificationTemplate::BookingCancelled, ['refunded_cents' => 8000]);

    expect($html)->toContain('Κουπόνι με κωδικό GIFT-ABC123')
        ->and($text)->toContain('Κουπόνι με κωδικό GIFT-ABC123');
})->group('fast');

it('shows the voucher, not the old booking, on a voucher expiry reminder', function (NotificationTemplate $template): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 8000, balanceCents: 4000);

    $voucher = Tenancy::forTenant($tenant, fn (): Voucher => Voucher::factory()->create([
        'code' => 'GIFT-XYZ789',
        'amount_cents' => 8000,
        'remaining_cents' => 5500,
        'expires_at' => Carbon::parse('2026-07-25 12:00:00'),
        'issued_for_booking_id' => $booking->getKey(),
    ]));

    [$html, $text] = greekMail($tenant, $booking, $template, ['voucher' => $voucher]);

    foreach ([$html, $text] as $body) {
        expect($body)->toContain('GIFT-XYZ789')
            ->and($body)->toContain('55,00 €')
            ->and($body)->toContain('25 Ιουλίου 2026')
            ->and($body)->toContain('/v/GIFT-XYZ789')
            ->and($body)->not->toContain($booking->reference)
            ->and($body)->not->toContain('Υπόλοιπο');
    }
})->with([NotificationTemplate::VoucherExpiry30d, NotificationTemplate::VoucherExpiry7d])->group('fast');

it('shows the items, total, validity and quote link on a quote', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 0, balanceCents: 0, mode: BookingMode::PerVessel);

    $quote = Tenancy::forTenant($tenant, function () use ($booking): Quote {
        $quote = Quote::factory()->sent()->create([
            'booking_id' => $booking->getKey(),
            'total_cents' => 110000,
            'deposit_cents' => 30000,
            'valid_until' => Carbon::parse('2026-07-08 20:00:00'),
        ]);
        QuoteLineItem::factory()->create(['quote_id' => $quote->getKey()]);
        QuoteLineItem::factory()->fee(15000)->create(['quote_id' => $quote->getKey()]);

        return $quote;
    });

    [$html, $text] = greekMail($tenant, $booking, NotificationTemplate::QuoteSent, ['quote' => $quote]);

    foreach ([$html, $text] as $body) {
        expect($body)->toContain('Ιδιωτική ναύλωση ολοήμερη')
            ->and($body)->toContain('Καύσιμα')
            ->and($body)->toContain('1.100,00 €')
            ->and($body)->toContain('300,00 €')
            ->and($body)->toContain('8 Ιουλίου 2026')
            ->and($body)->toContain('/q/' . $quote->quote_token)
            ->and($body)->not->toContain('Υπόλοιπο');
    }
})->group('fast');

it('gives the due date, the amount and a pay button on a balance reminder', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 8000, balanceCents: 4000);

    Tenancy::forTenant($tenant, fn () => $booking->forceFill(['balance_due_at' => Carbon::parse('2026-07-02 21:00:00')])->save());

    [$html, $text] = greekMail($tenant, $booking, NotificationTemplate::BalanceDueReminder);

    foreach ([$html, $text] as $body) {
        expect($body)->toContain('40,00 €')
            ->and($body)->toContain('Παρασκευή 3 Ιουλίου 2026')
            ->and($body)->toContain('Πληρωμή υπολοίπου')
            ->and($body)->toContain('/b/' . $booking->manage_token);
    }
})->group('fast');

it('asks for the charter agreement by the real deadline, not the charter day', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(mode: BookingMode::PerVessel);

    // Departure 2026-07-04 09:00 Athens, details due 48 hours before.
    [$html, $text] = greekMail($tenant, $booking, NotificationTemplate::CharterAgreement72h);

    foreach ([$html, $text] as $body) {
        expect($body)->toContain('Πέμπτη 2 Ιουλίου 2026, 09:00')
            ->and($body)->toContain('/g/' . $booking->guest_details_token)
            ->and($body)->not->toContain('πριν από 04/07/2026');
    }
})->group('fast');

it('names the operator in the weather emails instead of «ο διαχειριστής»', function (NotificationTemplate $template): void {
    [$tenant, $booking] = GuestPageScenario::booking();
    $tenant->forceFill(['name' => 'Aegean Blue'])->save();

    [$html, $text] = greekMail($tenant, $booking, $template, [
        'entitlement_cents' => 12000,
        'due_at' => Carbon::parse('2026-07-15 09:00:00'),
    ]);

    foreach ([$html, $text] as $body) {
        expect($body)->toContain('Aegean Blue')
            ->and(mb_strtolower($body))->not->toContain('διαχειριστ');
    }
})->with([
    NotificationTemplate::WeatherChoiceRequested,
    NotificationTemplate::WeatherChoiceReminder,
    NotificationTemplate::WeatherChoiceApplied,
])->group('fast');

it('says what is owed and until when on the weather choice', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking();

    [$html, $text] = greekMail($tenant, $booking, NotificationTemplate::WeatherChoiceRequested, [
        'entitlement_cents' => 12000,
        'due_at' => Carbon::parse('2026-07-15 09:00:00'),
    ]);

    foreach ([$html, $text] as $body) {
        expect($body)->toContain('120,00 €')
            ->and($body)->toContain('Τετάρτη 15 Ιουλίου 2026, 12:00');
    }
})->group('fast');

it('shows no balance on a review request', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 8000, balanceCents: 4000);

    [$html, $text] = greekMail($tenant, $booking, NotificationTemplate::ReviewRequest);

    expect($html)->not->toContain('Υπόλοιπο')
        ->and($text)->not->toContain('Υπόλοιπο');
})->group('fast');

it('still shows the balance on the confirmation', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 8000, balanceCents: 4000);

    [$html, $text] = greekMail($tenant, $booking, NotificationTemplate::BookingConfirmed);

    expect($html)->toContain('Υπόλοιπο')
        ->and($text)->toContain('Υπόλοιπο');
})->group('fast');
