<?php

declare(strict_types=1);

use App\Enums\GuestDocumentType;
use App\Jobs\PurgeGuestDocumentsJob;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\Departure;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Passport numbers stop existing on time — spec GDR-2, GDR-3, GDR-4
|--------------------------------------------------------------------------
|
| The only job in this product whose success is that data is **gone**, which
| makes it the only one where a passing test has to assert an absence and a
| presence in the same breath: the number is destroyed, and the name is not.
|
| GDR-3.3 draws that line deliberately. A manifest a coastguard asked for last
| August has to stay explicable, and a chargeback six months later is argued
| with a passenger list. Purging a name in service of a promise nobody made
| would leave an operator unable to answer either.
|
*/

/** @return array{0: Tenant, 1: BookingGuest} */
function guestDepartingOn(string $endsAtUtc, int $retentionDays = 90): array
{
    $tenant = Tenant::factory()->create([
        'timezone' => 'Europe/Athens',
        'guest_document_retention_days' => $retentionDays,
    ]);

    $guest = Tenancy::forTenant($tenant, function () use ($endsAtUtc): BookingGuest {
        $departure = Departure::factory()->create(['ends_at_utc' => Carbon::parse($endsAtUtc)]);
        $booking = Booking::factory()->for($departure)->create();

        return BookingGuest::factory()->for($booking)->create([
            'full_name' => 'Ελένη Νικολάου',
            'nationality' => 'GR',
            'document_type' => GuestDocumentType::Passport,
            'document_number' => 'AB1234567',
        ]);
    });

    return [$tenant, $guest];
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-08 12:00:00');
});

it('destroys a document number once the window has passed', function (): void {
    // Ninety days back from 8 September is 10 June; a trip that ended in May is
    // well past it.
    [$tenant, $guest] = guestDepartingOn('2026-05-01 18:00:00');

    (new PurgeGuestDocumentsJob)->handle();

    $guest = Tenancy::forTenant($tenant, fn (): BookingGuest => $guest->refresh());

    expect($guest->document_number)->toBeNull()
        ->and($guest->document_type)->toBeNull()
        // The stamp, so a purged row is not mistaken for one that never had a
        // document and chased for missing details months later.
        ->and($guest->document_purged_at)->not->toBeNull();
})->group('fast');

it('keeps the name, the nationality and the booking', function (): void {
    // GDR-3.3. The half of this job that is about *not* deleting.
    [$tenant, $guest] = guestDepartingOn('2026-05-01 18:00:00');

    (new PurgeGuestDocumentsJob)->handle();

    $guest = Tenancy::forTenant($tenant, fn (): BookingGuest => $guest->refresh());

    expect($guest->full_name)->toBe('Ελένη Νικολάου')
        ->and($guest->nationality)->toBe('GR')
        ->and($guest->booking_id)->not->toBeNull();
})->group('fast');

it('leaves a document alone while the window is still running', function (): void {
    // A trip that ended last week. Purging it early would break a promise in
    // the other direction — the operator said ninety days.
    [$tenant, $guest] = guestDepartingOn('2026-09-01 18:00:00');

    (new PurgeGuestDocumentsJob)->handle();

    expect(Tenancy::forTenant($tenant, fn (): ?string => $guest->refresh()->document_number))
        ->toBe('AB1234567');
})->group('fast');

it('leaves a future departure alone', function (): void {
    // The clock runs from the departure, not the booking. A trip booked in
    // January for August is retained from August.
    [$tenant, $guest] = guestDepartingOn('2027-07-01 18:00:00');

    (new PurgeGuestDocumentsJob)->handle();

    expect(Tenancy::forTenant($tenant, fn (): ?string => $guest->refresh()->document_number))
        ->toBe('AB1234567');
})->group('fast');

it('uses each operator’s own window', function (): void {
    // Thirty days and ninety days, same departure. One is purged and one is not.
    [$short, $shortGuest] = guestDepartingOn('2026-07-01 18:00:00', retentionDays: 30);
    [$long, $longGuest] = guestDepartingOn('2026-07-01 18:00:00', retentionDays: 90);

    (new PurgeGuestDocumentsJob)->handle();

    expect(Tenancy::forTenant($short, fn (): ?string => $shortGuest->refresh()->document_number))
        ->toBeNull()
        ->and(Tenancy::forTenant($long, fn (): ?string => $longGuest->refresh()->document_number))
        ->toBe('AB1234567');
})->group('fast');

it('clamps a window nobody validated to GDR-3.2’s floor', function (): void {
    // The column is validated at save, so a value below thirty arrived by a
    // seeder, an import or a hand-edited row. The floor is what stops an
    // operator promising a guest thirty days and keeping the number for one.
    $tenant = Tenant::factory()->create(['guest_document_retention_days' => 1]);

    expect(PurgeGuestDocumentsJob::retentionDays($tenant))->toBe(PurgeGuestDocumentsJob::MIN_DAYS);

    $decade = Tenant::factory()->create(['guest_document_retention_days' => 3650]);

    expect(PurgeGuestDocumentsJob::retentionDays($decade))->toBe(PurgeGuestDocumentsJob::MAX_DAYS);
})->group('fast');

it('runs twice without doing anything the second time', function (): void {
    // GDR-4's idempotence, asserted by the stamp not moving.
    [$tenant, $guest] = guestDepartingOn('2026-05-01 18:00:00');

    (new PurgeGuestDocumentsJob)->handle();

    $first = Tenancy::forTenant($tenant, fn (): ?Carbon => $guest->refresh()->document_purged_at);

    Carbon::setTestNow('2026-09-09 12:00:00');
    (new PurgeGuestDocumentsJob)->handle();

    expect(Tenancy::forTenant($tenant, fn (): ?Carbon => $guest->refresh()->document_purged_at)
        ?->toIso8601String())->toBe($first?->toIso8601String());
})->group('fast');

it('does not reach into another operator’s guests', function (): void {
    // The job iterates tenants and resolves each one; a query that escaped the
    // scope would purge a stranger's documents, which is the one failure here
    // that cannot be undone.
    [$aegean, $aegeanGuest] = guestDepartingOn('2026-05-01 18:00:00', retentionDays: 30);
    [$ionian, $ionianGuest] = guestDepartingOn('2026-09-07 18:00:00', retentionDays: 365);

    (new PurgeGuestDocumentsJob)->handle();

    expect(Tenancy::forTenant($aegean, fn (): ?string => $aegeanGuest->refresh()->document_number))
        ->toBeNull()
        ->and(Tenancy::forTenant($ionian, fn (): ?string => $ionianGuest->refresh()->document_number))
        ->toBe('AB1234567');
})->group('fast');
