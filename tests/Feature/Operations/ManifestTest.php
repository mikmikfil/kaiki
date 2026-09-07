<?php

declare(strict_types=1);

use App\Domain\Operations\Actions\GenerateManifest;
use App\Domain\Operations\Support\Manifest;
use App\Enums\AuditAction;
use App\Enums\BookingStatus;
use App\Enums\GuestDocumentType;
use App\Enums\ManifestColumn;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\Departure;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| OPS-8, OPS-9, OPS-10, GDR-6: the passenger list
|--------------------------------------------------------------------------
|
| Two things here are wrong in ways nobody notices until it matters.
|
| **OPS-9's count.** Every other count in this system is a capacity count, so
| the obvious implementation reuses one — and produces a manifest short by
| exactly the number of babies. The coastguard notices; the operator does not.
|
| **GDR-6's claim** that document numbers appear in the manifest and nowhere
| else. The audit row is what makes that checkable rather than asserted, and it
| must be written when the column is *included* — not on every manifest, or the
| trail fills with rows nobody reads and stops being read at all.
|
*/

beforeEach(function (): void {
    config(['queue.default' => 'sync']);
    Carbon::setTestNow('2026-07-08 06:00:00');
});

/** @return array{0: Tenant, 1: Departure} */
function manifestFixture(): array
{
    $tenant = Tenant::factory()->create();

    $departure = Tenancy::forTenant($tenant, function (): Departure {
        $vessel = Vessel::factory()->create([
            'name' => 'Θάλασσα',
            'capacity_max' => 12,
            'captain_name' => 'Γιώργος Δημητρίου',
        ]);

        $sailing = Departure::factory()->for($vessel)->at('2026-07-09', '09:00')->withSeats(3)->create();

        $booking = Booking::factory()->create([
            'departure_id' => $sailing->getKey(),
            'status' => BookingStatus::Confirmed,
            'guest_name' => 'Anna Rossi',
            'pax_total' => 3,
        ]);

        // Two adults who take seats, and an infant who does not — OPS-9's case.
        foreach ([
            ['Anna Rossi', 'adult', '1990-04-02', 'IT', 'AA1234567'],
            ['Marco Rossi', 'adult', '1988-11-30', 'IT', 'BB7654321'],
            ['Bimbo Rossi', 'infant', '2025-01-05', 'IT', null],
        ] as $position => [$name, $band, $dob, $nationality, $document]) {
            BookingGuest::factory()->create([
                'booking_id' => $booking->getKey(),
                'position' => $position + 1,
                'age_band_code' => $band,
                'full_name' => $name,
                'date_of_birth' => $dob,
                'nationality' => $nationality,
                'document_type' => $document === null ? null : GuestDocumentType::Passport,
                'document_number' => $document,
                'is_lead' => $position === 0,
            ]);
        }

        return $sailing;
    });

    return [$tenant, $departure];
}

it('counts every body on board, not every seat sold', function (): void {
    [$tenant, $departure] = manifestFixture();

    $manifest = Tenancy::forTenant(
        $tenant,
        fn (): Manifest => Manifest::forDeparture($departure, ManifestColumn::defaults()),
    );

    // Three people. `seats_sold` is two, because the infant takes no seat — and
    // a manifest built from that number is short by exactly the babies.
    expect($manifest->onBoard)->toBe(3)
        ->and($manifest->rows)->toHaveCount(3)
        ->and($manifest->capacityMax)->toBe(12)
        ->and($manifest->header['captain'])->toBe('Γιώργος Δημητρίου')
        ->and($manifest->header['vessel'])->toBe('Θάλασσα');
});

it('carries the document number only when the column was asked for', function (): void {
    [$tenant, $departure] = manifestFixture();

    Tenancy::forTenant($tenant, function () use ($departure): void {
        $with = Manifest::forDeparture($departure, ManifestColumn::defaults());
        $without = Manifest::forDeparture($departure, [ManifestColumn::FullName, ManifestColumn::Nationality]);

        expect($with->rows[0][ManifestColumn::DocumentNumber->value])->toBe('AA1234567')
            ->and($without->rows[0])->not->toHaveKey(ManifestColumn::DocumentNumber->value);
    });
});

it('logs the generation when it carries document numbers, and not when it does not', function (): void {
    [$tenant, $departure] = manifestFixture();

    Tenancy::forTenant($tenant, function () use ($departure): void {
        $generate = app(GenerateManifest::class);

        $generate->forDeparture($departure, [ManifestColumn::FullName, ManifestColumn::Nationality]);

        // A crew list of names is an ordinary export. A trail full of rows
        // nobody needs to read is a trail nobody reads.
        expect(AuditLog::query()->where('action', AuditAction::ManifestGenerated->value)->count())->toBe(0);

        $generate->forDeparture($departure, ManifestColumn::defaults(), userId: 41);

        $entry = AuditLog::query()->where('action', AuditAction::ManifestGenerated->value)->first();

        expect($entry)->not->toBeNull()
            ->and($entry?->context['people'] ?? null)->toBe(3)
            ->and($entry?->context['requested_by_user_id'] ?? null)->toBe(41)
            // ADR-0025 §3: a row about a list of passport numbers is exactly the
            // row that would be tempted to carry one.
            ->and($entry?->context)->not->toHaveKey('document_number');
    });
});

it('shows a booking with no details as blank rows rather than leaving it off', function (): void {
    // A short manifest that looks complete is worse than one with gaps in it:
    // the gaps are what send somebody to chase the details before sailing.
    $tenant = Tenant::factory()->create();

    $departure = Tenancy::forTenant($tenant, function (): Departure {
        $sailing = Departure::factory()->at('2026-07-09', '09:00')->withSeats(4)->create();

        Booking::factory()->create([
            'departure_id' => $sailing->getKey(),
            'status' => BookingStatus::Confirmed,
            'guest_name' => 'Nobody Filled This In',
            'pax_total' => 4,
        ]);

        return $sailing;
    });

    $manifest = Tenancy::forTenant(
        $tenant,
        fn (): Manifest => Manifest::forDeparture($departure, ManifestColumn::defaults()),
    );

    expect($manifest->onBoard)->toBe(4)
        ->and($manifest->missingDetails)->toBe(4)
        ->and($manifest->rows[0][ManifestColumn::FullName->value])->toBe(__('manifest.blank'));
});

it('leaves a cancelled booking off, because the crew would wait for them', function (): void {
    [$tenant, $departure] = manifestFixture();

    Tenancy::forTenant($tenant, function () use ($departure): void {
        $cancelled = Booking::factory()->create([
            'departure_id' => $departure->getKey(),
            'status' => BookingStatus::Cancelled,
            'guest_name' => 'Gone Away',
            'pax_total' => 2,
        ]);

        BookingGuest::factory()->create([
            'booking_id' => $cancelled->getKey(),
            'position' => 1,
            'full_name' => 'Gone Away',
        ]);

        $manifest = Manifest::forDeparture($departure, ManifestColumn::defaults());

        expect($manifest->onBoard)->toBe(3)
            ->and(array_column($manifest->rows, ManifestColumn::FullName->value))->not->toContain('Gone Away');
    });
});

it('says a purged document was removed rather than leaving a blank', function (): void {
    // ADR-0012: the guest did supply it and the retention job took it away.
    // A blank would read as "they never gave us one".
    $tenant = Tenant::factory()->create();

    $departure = Tenancy::forTenant($tenant, function (): Departure {
        $sailing = Departure::factory()->at('2026-07-09', '09:00')->withSeats(1)->create();

        $booking = Booking::factory()->create([
            'departure_id' => $sailing->getKey(),
            'status' => BookingStatus::Confirmed,
            'pax_total' => 1,
        ]);

        BookingGuest::factory()->create([
            'booking_id' => $booking->getKey(),
            'position' => 1,
            'full_name' => 'Old Booking',
            'document_number' => null,
            'document_purged_at' => now()->subYear(),
        ]);

        return $sailing;
    });

    $manifest = Tenancy::forTenant(
        $tenant,
        fn (): Manifest => Manifest::forDeparture($departure, ManifestColumn::defaults()),
    );

    expect($manifest->rows[0][ManifestColumn::DocumentNumber->value])->toBe(__('manifest.purged'));
});

it('writes a CSV Excel on a Greek machine can actually open', function (): void {
    [$tenant, $departure] = manifestFixture();

    $csv = Tenancy::forTenant($tenant, function () use ($departure): string {
        $generate = app(GenerateManifest::class);

        return $generate->csv($generate->forDeparture($departure, ManifestColumn::defaults()));
    });

    // The BOM. Without it Excel reads UTF-8 as Windows-1253 and every Greek
    // name becomes mojibake — which looks like our bug and cannot be fixed from
    // the operator's side.
    expect(str_starts_with($csv, "\u{FEFF}"))->toBeTrue()
        ->and($csv)->toContain('Anna Rossi')
        ->and($csv)->toContain('AA1234567')
        ->and(substr_count($csv, "\r\n"))->toBe(4);
});

it('quotes a name with a comma in it rather than splitting the row', function (): void {
    $tenant = Tenant::factory()->create();

    $departure = Tenancy::forTenant($tenant, function (): Departure {
        $sailing = Departure::factory()->at('2026-07-09', '09:00')->withSeats(1)->create();

        $booking = Booking::factory()->create([
            'departure_id' => $sailing->getKey(),
            'status' => BookingStatus::Confirmed,
            'pax_total' => 1,
        ]);

        BookingGuest::factory()->create([
            'booking_id' => $booking->getKey(),
            'position' => 1,
            'full_name' => 'Rossi, Anna',
        ]);

        return $sailing;
    });

    $csv = Tenancy::forTenant($tenant, function () use ($departure): string {
        $generate = app(GenerateManifest::class);

        return $generate->csv($generate->forDeparture($departure, [ManifestColumn::FullName]));
    });

    expect($csv)->toContain('"Rossi, Anna"');
});

it('names the file so an operator can find it again in November', function (): void {
    [$tenant, $departure] = manifestFixture();

    $name = Tenancy::forTenant($tenant, function () use ($departure): string {
        $generate = app(GenerateManifest::class);

        return $generate->filename($generate->forDeparture($departure, [ManifestColumn::FullName]), 'pdf');
    });

    // The Greek vessel name transliterated, because a downloads folder and an
    // email attachment both have opinions about non-ASCII filenames.
    expect($name)->toBe('manifest-2026-07-09-thalassa.pdf');
});

it('renders both layouts, in Greek, without a stylesheet to load', function (): void {
    // CMP-3. The PDF is produced by a headless browser with no session and no
    // asset manifest, so a stylesheet reference would silently not load and the
    // manifest would come out as unstyled text.
    [$tenant, $departure] = manifestFixture();

    Tenancy::forTenant($tenant, function () use ($departure): void {
        app()->setLocale('el');

        $manifest = Manifest::forDeparture($departure, ManifestColumn::defaults());

        foreach (['manifests.standard', 'manifests.harbour'] as $view) {
            $html = view($view, ['manifest' => $manifest])->render();

            expect($html)->toContain('Θάλασσα')
                ->and($html)->toContain('Anna Rossi')
                ->and($html)->toContain('Κατάσταση επιβατών')
                // Three on board, against the licensed twelve.
                ->and($html)->toContain('12')
                // Everything inline: no <link>, no asset() call that resolved.
                ->and($html)->not->toContain('<link');
        }
    });
});

it('renders a manifest that runs over a page without splitting a row', function (): void {
    // CMP-3 asks for a multi-page case. What it is really asking is that the
    // table keeps its header and does not break inside a row — a sheet split
    // mid-person is one the harbourmaster hands back.
    $tenant = Tenant::factory()->create();

    $departure = Tenancy::forTenant($tenant, function (): Departure {
        $vessel = Vessel::factory()->create(['capacity_max' => 60]);
        $sailing = Departure::factory()->for($vessel)->at('2026-07-09', '09:00')->create(['capacity' => 60]);

        $booking = Booking::factory()->create([
            'departure_id' => $sailing->getKey(),
            'status' => BookingStatus::Confirmed,
            'pax_total' => 46,
        ]);

        for ($position = 1; $position <= 46; $position++) {
            BookingGuest::factory()->create([
                'booking_id' => $booking->getKey(),
                'position' => $position,
                'full_name' => 'Παπαδόπουλος Κωνσταντίνος ' . $position,
                'nationality' => 'GR',
            ]);
        }

        return $sailing;
    });

    $html = Tenancy::forTenant($tenant, function () use ($departure): string {
        return view('manifests.standard', [
            'manifest' => Manifest::forDeparture($departure, ManifestColumn::defaults()),
        ])->render();
    });

    // Counted by the row-number cell rather than by `<tr>`: the header table
    // has rows of its own, and a test that counted those would pass whatever
    // the list did.
    expect(substr_count($html, '<td class="num">'))->toBe(46)
        ->and($html)->toContain('page-break-inside: avoid')
        ->and($html)->toContain('Παπαδόπουλος Κωνσταντίνος 46');
});
