<?php

declare(strict_types=1);

use App\Domain\Compliance\Actions\EraseGuestData;
use App\Domain\Compliance\Actions\ExportGuestData;
use App\Enums\GuestDocumentType;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| "What do you hold about me" and "delete it" — spec GDR-5, GDR-6, GDR-10, GDR-11
|--------------------------------------------------------------------------
|
| Two requests that arrive rarely and carry a legal deadline. Without them an
| operator's options are a database query they cannot write and a screenshot of
| a booking list — neither of which is an answer.
|
| The two rules this file exists to hold:
|
|   1. **The export never contains a document number** (GDR-6). It is emailed to
|      whoever asked, and "we hold a passport number" is the honest answer;
|      printing the number is not.
|   2. **Erasure is anonymisation, not deletion** (GDR-10). An invoice is a
|      document in a state tax register the operator must keep, and Article
|      17(3)(b) does not ask them to break tax law. The person goes; the
|      transaction stays.
|
*/

/** @return array{0: Tenant, 1: Booking, 2: BookingGuest} */
function subjectFixture(string $email = 'eleni@example.test'): array
{
    $tenant = Tenant::factory()->create(['name' => 'Aegean Blue']);

    [$booking, $guest] = Tenancy::forTenant($tenant, function () use ($email): array {
        $booking = Booking::factory()->create([
            'guest_name' => 'Ελένη Νικολάου',
            'guest_email' => $email,
            'guest_phone' => '+30 694 000 0000',
            'total_cents' => 12_000,
        ]);

        $guest = BookingGuest::factory()->for($booking)->create([
            'full_name' => 'Ελένη Νικολάου',
            'nationality' => 'GR',
            'document_type' => GuestDocumentType::Passport,
            'document_number' => 'AB1234567',
        ]);

        return [$booking, $guest];
    });

    return [$tenant, $booking, $guest];
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-08 12:00:00');
});

it('returns the booking, the passengers and the operator’s name', function (): void {
    [$tenant, $booking] = subjectFixture();

    $export = Tenancy::forTenant($tenant, fn (): array => app(ExportGuestData::class)('eleni@example.test'));

    expect($export['subject'])->toBe('eleni@example.test')
        ->and($export['operator'])->toBe('Aegean Blue')
        ->and($export['bookings'])->toHaveCount(1)
        ->and($export['bookings'][0]['reference'])->toBe($booking->reference)
        ->and($export['passengers'])->toHaveCount(1)
        ->and($export['passengers'][0]['full_name'])->toBe('Ελένη Νικολάου');
})->group('fast');

it('never puts a document number in the file', function (): void {
    /*
     * GDR-6, asserted the way `ExportRows` asserts it: a real number in the
     * database, the finished document, and the number nowhere in its bytes.
     * Checking the shape of the array would pass a version that added the field
     * back under a different key.
     */
    [$tenant] = subjectFixture();

    $export = Tenancy::forTenant($tenant, fn (): array => app(ExportGuestData::class)('eleni@example.test'));

    expect(json_encode($export, JSON_UNESCAPED_UNICODE))->not->toContain('AB1234567');
})->group('fast');

it('says a document is held without saying what it is', function (): void {
    // The honest answer to "what do you hold". Silence would read as "nothing",
    // which would be false.
    [$tenant] = subjectFixture();

    $export = Tenancy::forTenant($tenant, fn (): array => app(ExportGuestData::class)('eleni@example.test'));

    expect($export['passengers'][0]['identity_document'])->toBe(__('gdpr.export.document.held'));
})->group('fast');

it('says when a document was destroyed, which is what makes the policy evidence', function (): void {
    [$tenant, , $guest] = subjectFixture();

    Tenancy::forTenant($tenant, fn () => $guest->forceFill([
        'document_number' => null,
        'document_purged_at' => Carbon::parse('2026-08-01'),
    ])->save());

    $export = Tenancy::forTenant($tenant, fn (): array => app(ExportGuestData::class)('eleni@example.test'));

    expect($export['passengers'][0]['identity_document'])->toContain('2026-08-01');
})->group('fast');

it('finds the whole party, not only the person who paid', function (): void {
    // Somebody books for four and travels with three. `booking_guests` holds no
    // email of its own, so the party is reached through the booking — and an
    // export returning only the lead's row would be an incomplete answer.
    [$tenant, $booking] = subjectFixture();

    // Explicit positions: `booking_guests` is unique on (tenant, booking,
    // position), which is how a manifest keeps its order.
    Tenancy::forTenant($tenant, function () use ($booking): void {
        BookingGuest::factory()->for($booking)->create(['position' => 2]);
        BookingGuest::factory()->for($booking)->create(['position' => 3]);
    });

    $export = Tenancy::forTenant($tenant, fn (): array => app(ExportGuestData::class)('eleni@example.test'));

    expect($export['passengers'])->toHaveCount(3);
})->group('fast');

it('returns nothing for an address nobody used', function (): void {
    // Not an error. "We hold nothing about you" is a complete and correct
    // answer to a subject access request, and the operator has to be able to
    // send it.
    [$tenant] = subjectFixture();

    $export = Tenancy::forTenant($tenant, fn (): array => app(ExportGuestData::class)('nobody@example.test'));

    expect($export['bookings'])->toBe([])
        ->and($export['passengers'])->toBe([])
        ->and($export['payments'])->toBe([]);
})->group('fast');

it('does not reach into another operator’s bookings', function (): void {
    // The same address can book with two operators, and each answers only for
    // what it holds.
    [$aegean] = subjectFixture();
    [$ionian] = subjectFixture();

    $export = Tenancy::forTenant($aegean, fn (): array => app(ExportGuestData::class)('eleni@example.test'));

    expect($export['bookings'])->toHaveCount(1);
})->group('fast');

it('erases the person and keeps the transaction', function (): void {
    // GDR-10, and the sentence the whole action is built around.
    [$tenant, $booking, $guest] = subjectFixture();

    $touched = Tenancy::forTenant($tenant, fn (): array => app(EraseGuestData::class)('eleni@example.test'));

    [$booking, $guest] = Tenancy::forTenant($tenant, fn (): array => [$booking->refresh(), $guest->refresh()]);

    expect($touched['bookings'])->toBe(1)
        ->and($touched['passengers'])->toBe(1)
        // The person is gone…
        ->and($booking->guest_name)->not->toContain('Ελένη')
        ->and($booking->guest_phone)->toBeNull()
        ->and($guest->full_name)->not->toContain('Ελένη')
        ->and($guest->document_number)->toBeNull()
        // …and the transaction is intact.
        ->and($booking->exists)->toBeTrue()
        ->and($booking->total_cents)->toBe(12_000)
        ->and($booking->reference)->not->toBeNull();
})->group('fast');

it('writes a pseudonym that says why the name is missing, and when', function (): void {
    // A null column reads as "never collected". This has to read as "erased on
    // request", which is the difference between answering an audit and
    // shrugging at one.
    [$tenant, $booking] = subjectFixture();

    Tenancy::forTenant($tenant, fn (): array => app(EraseGuestData::class)('eleni@example.test'));

    expect(Tenancy::forTenant($tenant, fn (): string => (string) $booking->refresh()->guest_name))
        ->toBe(__('gdpr.erasure.pseudonym', ['date' => '2026-09-08']));
})->group('fast');

it('leaves an address that can never be delivered to', function (): void {
    // RFC 2606 reserves `.invalid`, so nothing sent here can reach a person by
    // accident — and null would break every screen that renders a booking.
    [$tenant, $booking] = subjectFixture();

    Tenancy::forTenant($tenant, fn (): array => app(EraseGuestData::class)('eleni@example.test'));

    expect(Tenancy::forTenant($tenant, fn (): string => (string) $booking->refresh()->guest_email))
        ->toEndWith('@erased.invalid');
})->group('fast');

it('erases the whole party, not only the lead', function (): void {
    [$tenant, $booking] = subjectFixture();

    Tenancy::forTenant($tenant, function () use ($booking): void {
        foreach ([2, 3] as $position) {
            BookingGuest::factory()->for($booking)->create([
                'position' => $position,
                'full_name' => 'Συνταξιδιώτης',
                'document_number' => 'CD7654321',
            ]);
        }
    });

    Tenancy::forTenant($tenant, fn (): array => app(EraseGuestData::class)('eleni@example.test'));

    $remaining = Tenancy::forTenant($tenant, fn (): int => BookingGuest::query()
        ->whereNotNull('document_number')
        ->count());

    expect($remaining)->toBe(0);
})->group('fast');

it('does not touch another operator’s rows', function (): void {
    // The one failure here that cannot be undone.
    [$aegean] = subjectFixture();
    [$ionian, $ionianBooking] = subjectFixture();

    Tenancy::forTenant($aegean, fn (): array => app(EraseGuestData::class)('eleni@example.test'));

    expect(Tenancy::forTenant($ionian, fn (): string => (string) $ionianBooking->refresh()->guest_name))
        ->toBe('Ελένη Νικολάου');
})->group('fast');

it('refuses an empty address rather than erasing everybody', function (): void {
    // A blank search box is the worst possible input to a bulk anonymiser.
    [$tenant] = subjectFixture();

    Tenancy::forTenant($tenant, function (): void {
        expect(fn () => app(EraseGuestData::class)('   '))->toThrow(RuntimeException::class);
        expect(fn () => app(ExportGuestData::class)(''))->toThrow(RuntimeException::class);
    });
})->group('fast');
