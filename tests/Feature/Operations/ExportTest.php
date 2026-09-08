<?php

declare(strict_types=1);

use App\Domain\Operations\Actions\RequestExport;
use App\Enums\BookingStatus;
use App\Enums\ExportDateBasis;
use App\Enums\ExportStatus;
use App\Enums\ExportType;
use App\Enums\GuestDocumentType;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use App\Jobs\PurgeExpiredExportsJob;
use App\Jobs\RunExportJob;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\ExportJob;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| OPS-17, OPS-18, OPS-10: the bookings and guests CSVs
|--------------------------------------------------------------------------
|
| Four things here are wrong in ways that produce a plausible-looking file.
|
| **Document numbers.** OPS-10 says the standard exports exclude them. The test
| that matters asserts the property from outside — a guest with a number, a
| finished file, and the number nowhere in its bytes — rather than checking that
| some column list does not mention it.
|
| **The inclusive end of a date window.** `<= '2026-09-30'` on a timestamp means
| midnight, so the last day of every window silently vanishes. It does not look
| like a bug; it looks like a quiet Tuesday.
|
| **A booking with two succeeded payments.** A join on `payments` puts it in the
| file twice, and an accountant double-counts a boat trip because of a SQL
| decision nobody wrote down.
|
| **An expired link over a file that is still there.** OPS-18's link expiry is
| satisfied by a check on the way in; the file sitting in a bucket a year later
| is the disclosure nobody sees.
|
*/

beforeEach(function (): void {
    // The queue is faked rather than run synchronously, because the ordering is
    // part of what is being tested: the row exists before the job does, and the
    // job's own clock is what stamps the expiry. A `sync` queue runs the job
    // inside the request that asked for it and hides both.
    Queue::fake();
    Storage::fake('local');
    Carbon::setTestNow('2026-09-08 09:00:00');
});

/**
 * A tenant with three bookings across three months, one of them a test row.
 *
 * @return array{0: Tenant, 1: Vessel, 2: Product}
 */
function exportFixture(): array
{
    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);

    [$vessel, $product] = Tenancy::forTenant($tenant, function (): array {
        $vessel = Vessel::factory()->create(['name' => 'Θάλασσα', 'capacity_max' => 12]);
        $product = Product::factory()->create(['title' => ['el' => 'Ηλιοβασίλεμα', 'en' => 'Sunset cruise']]);

        // June: booked on the 10th, sails on the 20th, paid on the 12th.
        $june = Booking::factory()->create([
            'product_id' => $product->getKey(),
            'vessel_id' => $vessel->getKey(),
            'status' => BookingStatus::Confirmed,
            'guest_name' => 'Anna Rossi',
            'guest_email' => 'anna@example.com',
            'local_date' => '2026-06-20',
            'created_at' => '2026-06-10 08:00:00',
            'total_cents' => 12000,
            'paid_cents' => 12000,
            'pax_total' => 2,
        ]);

        // Two succeeded payments on one booking — a deposit and a balance.
        // A join would put this booking in the file twice.
        foreach ([['2026-06-12 10:00:00', 4000, PaymentKind::Deposit], ['2026-06-18 10:00:00', 8000, PaymentKind::Balance]] as [$at, $cents, $kind]) {
            Payment::factory()->create([
                'booking_id' => $june->getKey(),
                'status' => PaymentStatus::Succeeded,
                'kind' => $kind,
                'amount_cents' => $cents,
                'paid_at' => $at,
            ]);
        }

        // July, and the row that must never appear: a test booking.
        Booking::factory()->create([
            'product_id' => $product->getKey(),
            'vessel_id' => $vessel->getKey(),
            'status' => BookingStatus::Confirmed,
            'guest_name' => 'Test Person',
            'local_date' => '2026-07-04',
            'created_at' => '2026-07-01 08:00:00',
            'is_test' => true,
        ]);

        // A hold. Fifteen minutes of somebody thinking about it, not a booking.
        Booking::factory()->create([
            'product_id' => $product->getKey(),
            'vessel_id' => $vessel->getKey(),
            'status' => BookingStatus::Draft,
            'guest_name' => 'Undecided',
            'local_date' => '2026-06-25',
            'created_at' => '2026-06-11 08:00:00',
        ]);

        return [$vessel, $product];
    });

    return [$tenant, $vessel, $product];
}

/** Run an export the way the queue would, and hand back its contents. */
function runExport(Tenant $tenant, ExportJob $export): string
{
    (new RunExportJob($export->getKey()))->handle();

    $export->refresh();

    return $export->path === null
        ? ''
        : (string) Storage::disk((string) $export->disk)->get($export->path);
}

it('writes a bookings CSV with a byte-order mark, CRLF endings and translated headers', function (): void {
    [$tenant] = exportFixture();

    $export = Tenancy::forTenant($tenant, fn (): ExportJob => app(RequestExport::class)(ExportType::Bookings));

    $csv = runExport($tenant, $export);
    $export->refresh();

    expect($export->status)->toBe(ExportStatus::Ready)
        // Without the mark, Excel on a Greek machine reads UTF-8 as
        // Windows-1253 and every name becomes mojibake.
        ->and(str_starts_with($csv, "\u{FEFF}"))->toBeTrue()
        ->and($csv)->toContain("\r\n")
        ->and($csv)->toContain('Reference')
        ->and($csv)->toContain('Anna Rossi')
        // The test booking and the hold are both out.
        ->and($csv)->not->toContain('Test Person')
        ->and($csv)->not->toContain('Undecided')
        ->and($export->row_count)->toBe(1);
});

it('never writes a document number into a standard export', function (): void {
    [$tenant] = exportFixture();

    Tenancy::forTenant($tenant, function (): void {
        $booking = Booking::query()->where('guest_name', 'Anna Rossi')->firstOrFail();

        BookingGuest::factory()->create([
            'booking_id' => $booking->getKey(),
            'position' => 1,
            'full_name' => 'Anna Rossi',
            'nationality' => 'IT',
            'document_type' => GuestDocumentType::Passport,
            'document_number' => 'AA1234567',
        ]);
    });

    $guests = Tenancy::forTenant($tenant, fn (): ExportJob => app(RequestExport::class)(ExportType::Guests));
    $bookings = Tenancy::forTenant($tenant, fn (): ExportJob => app(RequestExport::class)(ExportType::Bookings));

    $guestCsv = runExport($tenant, $guests);
    $bookingCsv = runExport($tenant, $bookings);

    // The property, asserted from outside: the number is in the database and
    // nowhere in either file. OPS-10, GDR-6.
    expect($guestCsv)->toContain('Anna Rossi')
        ->and($guestCsv)->not->toContain('AA1234567')
        ->and($bookingCsv)->not->toContain('AA1234567');
});

it('includes the last day of the window on a timestamp column', function (): void {
    [$tenant] = exportFixture();

    // The booking was made on 10 June. A window ending on 10 June must contain
    // it — `<= '2026-06-10'` means midnight and would silently drop it.
    $export = Tenancy::forTenant($tenant, fn (): ExportJob => app(RequestExport::class)(
        type: ExportType::Bookings,
        basis: ExportDateBasis::Booked,
        from: Carbon::parse('2026-06-01'),
        to: Carbon::parse('2026-06-10'),
    ));

    $csv = runExport($tenant, $export);

    expect($csv)->toContain('Anna Rossi')
        ->and($export->refresh()->row_count)->toBe(1);
});

it('counts a booking with two succeeded payments once', function (): void {
    [$tenant] = exportFixture();

    $export = Tenancy::forTenant($tenant, fn (): ExportJob => app(RequestExport::class)(
        type: ExportType::Bookings,
        basis: ExportDateBasis::Paid,
        from: Carbon::parse('2026-06-01'),
        to: Carbon::parse('2026-06-30'),
    ));

    runExport($tenant, $export);

    // Two payments, one booking, one row. A join would give two.
    expect($export->refresh()->row_count)->toBe(1);
});

it('swaps a window whose ends are the wrong way round', function (): void {
    [$tenant] = exportFixture();

    $export = Tenancy::forTenant($tenant, fn (): ExportJob => app(RequestExport::class)(
        type: ExportType::Bookings,
        basis: ExportDateBasis::Booked,
        from: Carbon::parse('2026-06-30'),
        to: Carbon::parse('2026-06-01'),
    ));

    // A typo produces the window the operator meant, not a legitimate-looking
    // empty file.
    expect($export->from_date?->toDateString())->toBe('2026-06-01')
        ->and($export->to_date?->toDateString())->toBe('2026-06-30');
});

it('refuses a date basis the export type cannot answer', function (): void {
    [$tenant] = exportFixture();

    $export = Tenancy::forTenant($tenant, fn (): ExportJob => app(RequestExport::class)(
        type: ExportType::Guests,
        basis: ExportDateBasis::Paid,
    ));

    // A passenger list windowed on "the date the money arrived" would run and
    // answer a question nobody asked.
    expect($export->date_basis)->toBe(ExportDateBasis::Departure);
});

it('stamps the expiry on completion, not on request', function (): void {
    [$tenant] = exportFixture();

    $export = Tenancy::forTenant($tenant, fn (): ExportJob => app(RequestExport::class)(ExportType::Bookings));

    // Three hours behind a catalogue import.
    Carbon::setTestNow('2026-09-08 12:00:00');

    runExport($tenant, $export);

    // Twenty-four hours from finishing, so a queued export does not silently
    // get twenty-one.
    expect($export->refresh()->expires_at?->toDateTimeString())->toBe('2026-09-09 12:00:00');
});

it('deletes the file when the link expires, and keeps the row', function (): void {
    [$tenant] = exportFixture();

    $export = Tenancy::forTenant($tenant, fn (): ExportJob => app(RequestExport::class)(ExportType::Bookings));

    runExport($tenant, $export);
    $export->refresh();

    $path = (string) $export->path;

    expect(Storage::disk('local')->exists($path))->toBeTrue();

    Carbon::setTestNow('2026-09-10 09:00:00');

    (new PurgeExpiredExportsJob)->handle();

    $export->refresh();

    // The bytes are gone; the record that somebody took them is not.
    expect(Storage::disk('local')->exists($path))->toBeFalse()
        ->and($export->status)->toBe(ExportStatus::Expired)
        ->and($export->path)->toBeNull()
        ->and($export->row_count)->toBe(1);
});

it('closes the link on time even when the sweeper has not run', function (): void {
    [$tenant] = exportFixture();

    $export = Tenancy::forTenant($tenant, fn (): ExportJob => app(RequestExport::class)(ExportType::Bookings));

    runExport($tenant, $export);
    $export->refresh();

    expect($export->isDownloadable())->toBeTrue();

    Carbon::setTestNow('2026-09-10 09:00:00');

    // No purge job. The row is still `ready` and the file is still there, and
    // the link is closed anyway — the same asymmetry a seat hold has.
    expect($export->status)->toBe(ExportStatus::Ready)
        ->and(Storage::disk('local')->exists((string) $export->path))->toBeTrue()
        ->and($export->isDownloadable())->toBeFalse();
});

it('does not rebuild an export whose first attempt finished', function (): void {
    [$tenant] = exportFixture();

    $export = Tenancy::forTenant($tenant, fn (): ExportJob => app(RequestExport::class)(ExportType::Bookings));

    runExport($tenant, $export);
    $completedAt = $export->refresh()->completed_at?->toDateTimeString();

    Carbon::setTestNow('2026-09-08 11:00:00');

    // A retry must not replace a file somebody may already hold a link to.
    (new RunExportJob($export->getKey()))->handle();

    expect($export->refresh()->completed_at?->toDateTimeString())->toBe($completedAt);
});

it('writes one row per person in a guests export, blanks included', function (): void {
    [$tenant] = exportFixture();

    Tenancy::forTenant($tenant, function (): void {
        $booking = Booking::query()->where('guest_name', 'Anna Rossi')->firstOrFail();

        foreach ([['Anna Rossi', 'adult'], [null, 'adult'], ['Bimbo Rossi', 'infant']] as $position => [$name, $band]) {
            BookingGuest::factory()->create([
                'booking_id' => $booking->getKey(),
                'position' => $position + 1,
                'age_band_code' => $band,
                'full_name' => $name,
            ]);
        }
    });

    $export = Tenancy::forTenant($tenant, fn (): ExportJob => app(RequestExport::class)(ExportType::Guests));

    runExport($tenant, $export);

    // The guest nobody has filled in is a blank row, not an omission — a short
    // list that looks complete is worse than one with visible gaps.
    expect($export->refresh()->row_count)->toBe(3);
});

it('records a failure as a sentence the operator can read', function (): void {
    [$tenant] = exportFixture();

    $export = Tenancy::forTenant($tenant, fn (): ExportJob => app(RequestExport::class)(ExportType::Bookings));

    // The disk the finished file is written to has gone. Anything that throws
    // inside the build reaches the same branch; this is the one an operator is
    // most likely to meet, because it is a deployment mistake rather than a
    // data one.
    config(['kaiki.exports.disk' => 'no-such-disk']);

    (new RunExportJob($export->getKey()))->handle();

    $export->refresh();

    expect($export->status)->toBe(ExportStatus::Failed)
        ->and($export->error)->not->toBeNull()
        // A sentence, not a translation key and not a stack trace.
        ->and($export->error)->not->toContain('exports.errors')
        ->and($export->path)->toBeNull();
});

it('creates the row before dispatching the job', function (): void {
    [$tenant] = exportFixture();

    $export = Tenancy::forTenant($tenant, fn (): ExportJob => app(RequestExport::class)(ExportType::Bookings));

    // A queued job that created its own record would have nothing to report to
    // when it died before its first line ran — and the operator would press a
    // button that appeared to do nothing.
    expect($export->exists)->toBeTrue()
        ->and($export->status)->toBe(ExportStatus::Queued);

    Queue::assertPushed(RunExportJob::class, static fn (RunExportJob $job): bool => $job->exportJobId === $export->getKey());
});
