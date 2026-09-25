<?php

declare(strict_types=1);

use App\Enums\GuestDetailsStatus;
use App\Enums\NotificationTemplate;
use App\Mail\GuestMail;
use App\Models\Booking;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Tests\Support\Booking\GuestPageScenario;

/*
|--------------------------------------------------------------------------
| The confirmation carries the whole trip (design A, 2026-09-17)
|--------------------------------------------------------------------------
|
| «Στο email κράτησης επιβεβαίωσης make sure να υπάρχουν όλα τα στοιχεία. Ώρα,
| ημέρα, σημείο συνάντησης κλπ.» One full booking, rendered in Greek, and every
| fact the guest needs to turn up on time is asserted in both halves.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-03 11:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * A booking with everything filled in: a trip that checks in half an hour
 * early and lasts four hours, a port with directions, a family with an extra,
 * a balance still due, a policy, and passenger details still missing.
 *
 * @return array{0: string, 1: string, 2: Booking}
 */
function fullConfirmation(string $locale = 'el'): array
{
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 8000, balanceCents: 4500);

    Tenancy::withoutTenancy(static fn () => Tenant::query()->whereKey($tenant->getKey())->update([
        'phone' => '+30 22840 12345',
        'email' => 'hello@aegean-blue.example',
        'check_in_enabled' => true,
    ]));

    return Tenancy::forTenant($tenant, static function () use ($booking, $locale): array {
        $booking->loadMissing(['product.meetingPoint', 'vessel']);

        $booking->product->forceFill([
            'title' => ['el' => 'Γύρος της Αντιπάρου', 'en' => 'Antiparos cruise'],
            'duration_minutes' => 240,
            'check_in_offset_minutes' => 30,
            'what_to_bring' => ['el' => ['Μαγιό', 'Αντηλιακό'], 'en' => ['Swimwear', 'Sun cream']],
        ])->save();

        $booking->product->meetingPoint->forceFill([
            'name' => ['el' => 'Λιμάνι Παροικιάς', 'en' => 'Parikia port'],
            'address' => 'Προκυμαία Παροικιάς, Πάρος 844 00',
            'instructions' => ['el' => 'Δίπλα στο περίπτερο, προβλήτα 3.', 'en' => 'Next to the kiosk, pier 3.'],
            'maps_url' => 'https://maps.example/parikia',
        ])->save();

        $booking->vessel->forceFill(['name' => 'Αγία Μαρίνα'])->save();

        $booking->forceFill([
            'locale' => $locale,
            'guest_name' => 'Ελένη Παππά',
            'local_date' => '2026-07-18',
            'local_time' => '10:00:00',
            'pax_breakdown' => [
                ['code' => 'adult', 'label' => ['el' => 'Ενήλικας', 'en' => 'Adult'], 'qty' => 2, 'counts_toward_capacity' => true, 'unit_price_cents' => 5000, 'total_cents' => 10000],
                ['code' => 'child', 'label' => ['el' => 'Παιδί', 'en' => 'Child'], 'qty' => 1, 'counts_toward_capacity' => true, 'unit_price_cents' => 2000, 'total_cents' => 2000],
            ],
            'extras_snapshot' => [
                ['kind' => 'extra', 'ref' => 'x', 'label' => ['el' => 'Μεταφορά', 'en' => 'Transfer'], 'qty' => 1, 'unit_price_cents' => 500, 'total_cents' => 500],
            ],
            'total_cents' => 12500,
            'paid_cents' => 8000,
            'balance_cents' => 4500,
            'balance_due_at' => '2026-07-15 09:00:00',
            'policy_snapshot' => array_merge((array) $booking->policy_snapshot, [
                'summary' => ['el' => 'Δωρεάν ακύρωση έως 7 ημέρες πριν.', 'en' => 'Free cancellation up to 7 days before.'],
            ]),
            'guest_details_status' => GuestDetailsStatus::Pending,
            'guest_details_token' => str_repeat('g', 40),
            'guest_details_deadline_at' => '2026-07-16 21:00:00',
        ])->save();

        $booking = $booking->refresh();
        $mail = new GuestMail($booking, NotificationTemplate::BookingConfirmed);
        $html = $mail->render();

        app()->setLocale($locale);
        $text = view((string) $mail->textView, [
            'booking' => $booking,
            'template' => NotificationTemplate::BookingConfirmed,
            'brand' => [],
            'extra' => [],
        ])->render();

        return [$html, $text, $booking];
    });
}

it('carries every fact of the trip in the confirmation, in both halves', function (): void {
    [$html, $text, $booking] = fullConfirmation();

    $html = html_entity_decode($html, ENT_QUOTES);
    $day = Carbon::parse('2026-07-18')->locale('el')->isoFormat('dddd D MMMM YYYY');

    foreach ([$html, $text] as $body) {
        expect($body)
            // Who and what.
            ->toContain('Γεια σας Ελένη,')
            ->toContain('Γύρος της Αντιπάρου')
            ->toContain('Αγία Μαρίνα')
            ->toContain($booking->reference)
            // When: the day, check-in half an hour early, back four hours later.
            ->toContain($day)
            ->toContain(__('mail.common.check_in', [], 'el'))
            ->toContain('09:30')
            ->toContain('10:00')
            ->toContain('14:00')
            // Where, and how to find it.
            ->toContain('Λιμάνι Παροικιάς')
            ->toContain('Προκυμαία Παροικιάς, Πάρος 844 00')
            ->toContain('Δίπλα στο περίπτερο, προβλήτα 3.')
            ->toContain('https://maps.example/parikia')
            // Who is coming and what it cost.
            ->toContain('2 × Ενήλικας')
            ->toContain('100,00 €')
            ->toContain('1 × Παιδί')
            ->toContain('1 × Μεταφορά')
            ->toContain('125,00 €')
            ->toContain('80,00 €')
            ->toContain('45,00 €')
            ->toContain(__('mail.common.deposit_paid', [], 'el'))
            ->toContain(__('mail.common.until', [], 'el'))
            // The rest of design A.
            ->toContain('Δωρεάν ακύρωση έως 7 ημέρες πριν.')
            ->toContain('Μαγιό')
            ->toContain('Αντηλιακό')
            ->toContain('+30 22840 12345')
            ->toContain('hello@aegean-blue.example')
            ->toContain('/b/' . $booking->manage_token . '/ticket')
            ->toContain('/g/' . $booking->guest_details_token)
            ->toContain(__('mail.common.details_title', [], 'el'))
            // 21:00 UTC on the 16th is the 17th in Athens (CNV-2).
            ->toContain('17/7');
    }

    // The boarding QR is the only image, and it is carried, never fetched
    // (2026-09-23; see TemplateSnapshotTest and BoardingQrEmailTest).
    expect($html)->not->toMatch('/<img\b[^>]*src="(https?:)?\/\//i')
        ->and(strtolower($html))->not->toContain('uppercase')
        ->and($html)->not->toContain('text-decoration:underline');
})->group('fast');

it('writes the English confirmation in English', function (): void {
    [$html, $text] = fullConfirmation('en');

    foreach ([html_entity_decode($html, ENT_QUOTES), $text] as $body) {
        expect($body)
            ->toContain('Antiparos cruise')
            ->toContain('Parikia port')
            ->toContain('Next to the kiosk, pier 3.')
            ->toContain('2 × Adult')
            ->toContain('Free cancellation up to 7 days before.')
            ->toContain('Sun cream');
    }
})->group('fast');

it('drops the ticket and the details notice when neither applies', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 12000);

    Tenancy::withoutTenancy(static fn () => Tenant::query()->whereKey($tenant->getKey())->update(['check_in_enabled' => false]));

    $html = Tenancy::forTenant($tenant, static function () use ($booking): string {
        $booking->forceFill(['guest_details_status' => GuestDetailsStatus::Complete])->save();

        return (new GuestMail($booking->refresh(), NotificationTemplate::BookingConfirmed))->render();
    });

    expect($html)->not->toContain('/ticket')
        ->and($html)->not->toContain(__('mail.common.details_title', [], 'el'))
        ->and($html)->not->toContain(__('mail.common.until', [], 'el') . '</td>');
})->group('fast');

it('shows what the discount code took off, in both halves', function (): void {
    [$tenant, $booking] = GuestPageScenario::booking(paidCents: 10500);

    $rendered = Tenancy::forTenant($tenant, static function () use ($booking): array {
        $booking->forceFill([
            'locale' => 'el',
            'discount_cents' => 1500,
            'price_snapshot' => array_merge((array) $booking->price_snapshot, [
                'discount_code' => ['id' => 1, 'code' => 'SUMMER10', 'name' => 'Καλοκαίρι', 'kind' => 'percent', 'value' => 10, 'amount_cents' => 1500],
            ]),
        ])->save();

        $booking = $booking->refresh();
        $mail = new GuestMail($booking, NotificationTemplate::BookingConfirmed);

        app()->setLocale('el');

        return [
            $mail->render(),
            view((string) $mail->textView, [
                'booking' => $booking,
                'template' => NotificationTemplate::BookingConfirmed,
                'brand' => [],
                'extra' => [],
            ])->render(),
        ];
    });

    foreach ($rendered as $body) {
        expect(html_entity_decode($body, ENT_QUOTES))
            ->toContain(__('mail.common.discount', [], 'el'))
            // The code itself, because «Έκπτωση 15,00 €» does not tell a guest
            // whether the code they typed is the one that was honoured.
            ->toContain('SUMMER10')
            ->toContain('15,00');
    }
})->group('fast');
