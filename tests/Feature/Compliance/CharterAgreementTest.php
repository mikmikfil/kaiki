<?php

declare(strict_types=1);

use App\Domain\Compliance\Actions\GenerateCharterAgreement;
use App\Enums\AgreementStatus;
use App\Enums\BookingMode;
use App\Models\Booking;
use App\Models\CharterAgreement;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Ναυλοσύμφωνο — spec CMP-6, CMP-7, CMP-8, CMP-9
|--------------------------------------------------------------------------
|
| The **wording** of a ναυλοσύμφωνο is prescribed by ΚΥΑ Α.Π. 3133.1/47821 and
| nobody here has read it. Everything else — a versioned template, a frozen
| snapshot, a hash, a stored file, evidence that cannot be overwritten — is ours,
| and this file proves that half.
|
| The template announces itself as provisional in its own first paragraph, and
| `TEMPLATE_VERSION` starts with `provisional-` so that when a lawyer's text
| arrives, every document produced under this one stays permanently
| distinguishable from a real one.
|
| These tests do not render a PDF. Browsershot needs a Chromium binary and this
| suite runs on machines that may not have one; what is asserted is the
| **snapshot and the row**, which is where CMP-7 and CMP-8 actually live. The
| rendering path is the same one `GenerateETicket` already exercises.
|
*/

/** @return array{0: Tenant, 1: Booking} */
function charterFixture(): array
{
    $tenant = Tenant::factory()->create([
        'name' => 'Aegean Blue Cruises',
        'legal_name' => 'ΑΙΓΑΙΟ ΝΑΥΤΙΛΙΑΚΗ ΙΚΕ',
        'vat_number' => '094014201',
        'tax_office' => 'ΔΟΥ Πειραιά',
    ]);

    $booking = Tenancy::forTenant($tenant, function (): Booking {
        $vessel = Vessel::factory()->create([
            'name' => 'Αμφιτρίτη',
            'registration_number' => 'ΝΠ 1234',
            'captain_name' => 'Νίκος Βασιλείου',
        ]);

        $product = Product::factory()->create([
            'mode' => BookingMode::PerVessel,
            'vessel_id' => $vessel->getKey(),
        ]);

        return Booking::factory()->create([
            'mode' => BookingMode::PerVessel,
            'product_id' => $product->getKey(),
            'vessel_id' => $vessel->getKey(),
            'guest_name' => 'Ελένη Νικολάου',
            'total_cents' => 90_000,
            'paid_cents' => 27_000,
            'balance_cents' => 63_000,
        ]);
    });

    return [$tenant, $booking];
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-08 12:00:00');
    Storage::fake('local');
});

it('refuses a per-seat booking', function (): void {
    // CMP-6 is about a charter. A charter agreement for a single seat is a
    // document nobody can explain to a harbour master.
    $tenant = Tenant::factory()->create();

    $booking = Tenancy::forTenant($tenant, fn (): Booking => Booking::factory()->create([
        'mode' => BookingMode::PerSeat,
    ]));

    Tenancy::forTenant($tenant, function () use ($booking): void {
        expect(fn () => app(GenerateCharterAgreement::class)($booking))
            ->toThrow(RuntimeException::class, 'CMP-6');
    });
})->group('fast');

it('marks itself provisional in the version, permanently', function (): void {
    // The one thing that has to survive a lawyer's text arriving: every
    // agreement produced before it must stay tellable-apart afterwards.
    expect(GenerateCharterAgreement::TEMPLATE_VERSION)->toStartWith('provisional-');
})->group('fast');

it('freezes both parties, the vessel and the money into the snapshot', function (): void {
    // CMP-7. Everything the document prints is copied here at generation.
    [$tenant, $booking] = charterFixture();

    $agreement = Tenancy::forTenant($tenant, fn (): CharterAgreement => app(GenerateCharterAgreement::class)($booking));

    $fields = $agreement->fields_snapshot;

    expect($fields['operator']['legal_name'])->toBe('ΑΙΓΑΙΟ ΝΑΥΤΙΛΙΑΚΗ ΙΚΕ')
        ->and($fields['operator']['vat_number'])->toBe('094014201')
        ->and($fields['charterer']['name'])->toBe('Ελένη Νικολάου')
        ->and($fields['vessel']['name'])->toBe('Αμφιτρίτη')
        ->and($fields['vessel']['registration_number'])->toBe('ΝΠ 1234')
        ->and($fields['charter']['reference'])->toBe($booking->reference)
        // Cents, not a formatted string: the snapshot has to stay comparable.
        ->and($fields['money']['total_cents'])->toBe(90_000);
})->group('fast');

it('uses the legal name rather than the trading name', function (): void {
    // A ναυλοσύμφωνο is between two legal persons. «Aegean Blue Cruises» is a
    // brand; «ΑΙΓΑΙΟ ΝΑΥΤΙΛΙΑΚΗ ΙΚΕ» is a party.
    [$tenant, $booking] = charterFixture();

    $agreement = Tenancy::forTenant($tenant, fn (): CharterAgreement => app(GenerateCharterAgreement::class)($booking));

    expect($agreement->fields_snapshot['operator']['legal_name'])->not->toBe('Aegean Blue Cruises');
})->group('fast');

it('stores the file privately and records what it hashed', function (): void {
    // Both parties' legal identities are on this page. The private disk, and a
    // hash so the file somebody presents can be shown to be the file produced.
    [$tenant, $booking] = charterFixture();

    $agreement = Tenancy::forTenant($tenant, fn (): CharterAgreement => app(GenerateCharterAgreement::class)($booking));

    expect($agreement->pdf_path)->toContain("tenants/{$tenant->getKey()}/charter-agreements/")
        ->and($agreement->pdf_path)->toContain($booking->uuid)
        // The version is in the filename, so a regeneration under a new
        // template cannot overwrite the bytes an old row's hash points at.
        ->and($agreement->pdf_path)->toContain(GenerateCharterAgreement::TEMPLATE_VERSION)
        ->and($agreement->pdf_hash)->toHaveLength(64)
        ->and($agreement->status)->toBe(AgreementStatus::Generated)
        ->and(Storage::disk('local')->exists($agreement->pdf_path))->toBeTrue();
})->group('fast');

it('regenerates in place while nothing has been accepted', function (): void {
    // An operator correcting a passenger count before sending should get one
    // agreement, not two.
    [$tenant, $booking] = charterFixture();

    [$first, $second] = Tenancy::forTenant($tenant, function () use ($booking): array {
        $generate = app(GenerateCharterAgreement::class);

        return [$generate($booking), $generate($booking->refresh())];
    });

    expect($second->getKey())->toBe($first->getKey())
        ->and(Tenancy::forTenant($tenant, fn (): int => CharterAgreement::query()->count()))->toBe(1);
})->group('fast');

it('never touches an accepted agreement, and starts a new row instead', function (): void {
    /*
     * CMP-8, and the reason `openVersionFor()` existed before this action did.
     * The evidence a guest's acceptance created — the timestamp, the IP, the
     * hash of what they saw — has to survive somebody pressing "regenerate".
     */
    [$tenant, $booking] = charterFixture();

    $accepted = Tenancy::forTenant($tenant, function () use ($booking): CharterAgreement {
        $agreement = app(GenerateCharterAgreement::class)($booking);

        $agreement->forceFill([
            'status' => AgreementStatus::Accepted,
            'guest_accepted_at' => Carbon::now(),
            'guest_accepted_ip' => '198.51.100.10',
        ])->save();

        return $agreement;
    });

    $regenerated = Tenancy::forTenant($tenant, fn (): CharterAgreement => app(GenerateCharterAgreement::class)($booking->refresh()));

    expect($regenerated->getKey())->not->toBe($accepted->getKey());

    $accepted = Tenancy::forTenant($tenant, fn (): CharterAgreement => $accepted->refresh());

    expect($accepted->status)->toBe(AgreementStatus::Accepted)
        ->and($accepted->guest_accepted_ip)->toBe('198.51.100.10')
        ->and($accepted->guest_accepted_at)->not->toBeNull();
})->group('fast');

it('does not change what a guest already agreed to when the vessel is renamed', function (): void {
    // The whole point of the snapshot, stated as the thing an operator would
    // actually do: a boat gets a new name in March and February's document has
    // to keep saying what it said.
    [$tenant, $booking] = charterFixture();

    $agreement = Tenancy::forTenant($tenant, fn (): CharterAgreement => app(GenerateCharterAgreement::class)($booking));

    Tenancy::forTenant($tenant, fn () => Vessel::query()
        ->whereKey($booking->vessel_id)
        ->update(['name' => 'Ποσειδώνας']));

    expect(Tenancy::forTenant($tenant, fn (): string => $agreement->refresh()->fields_snapshot['vessel']['name']))
        ->toBe('Αμφιτρίτη');
})->group('fast');
