<?php

declare(strict_types=1);

use App\Domain\Compliance\Actions\AllocateInvoiceNumber;
use App\Domain\Compliance\Actions\GenerateCharterAgreement;
use App\Domain\Compliance\Actions\IssueInvoice;
use App\Enums\BookingMode;
use App\Enums\BookingStatus;
use App\Enums\InvoiceStatus;
use App\Models\Booking;
use App\Models\CharterAgreement;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Legal documents print the operator's clock (CNV-2)
|--------------------------------------------------------------------------
|
| Instants are stored in UTC. An invoice issued at 00:30 on 1 January in
| Athens is 22:30 on 31 December in UTC, and printing that would date the
| document in the previous year.
|
*/

beforeEach(function (): void {
    Storage::fake('local');
});

it('prints the invoice issue time in the tenant timezone', function (): void {
    Carbon::setTestNow('2026-12-31 22:30:00');

    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);

    $invoice = Tenancy::forTenant($tenant, function (): Invoice {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'total_cents' => 10_000,
            'price_snapshot' => ['vat' => ['rate_bp' => 1_300, 'vat_category' => 'VAT_2', 'net_cents' => 8_850, 'vat_cents' => 1_150]],
        ]);

        $invoice = app(IssueInvoice::class)($booking);
        app(AllocateInvoiceNumber::class)($invoice);
        $invoice->forceFill(['status' => InvoiceStatus::Sent, 'issued_at' => Carbon::now()])->save();

        return $invoice->refresh();
    });

    $html = Tenancy::forTenant($tenant, fn (): string => view('pdf.invoice', [
        'invoice' => $invoice,
        'tenant' => $tenant,
        'brand' => [],
        'qr' => null,
    ])->render());

    expect($html)->toContain('01/01/2027 00:30')
        ->and($html)->not->toContain('31/12/2026 22:30');
})->group('fast');

it('stamps the charter agreement with the tenant clock', function (): void {
    Carbon::setTestNow('2026-09-08 22:30:00');

    $tenant = Tenant::factory()->create(['timezone' => 'Europe/Athens']);

    $agreement = Tenancy::forTenant($tenant, function (): CharterAgreement {
        $vessel = Vessel::factory()->create();
        $product = Product::factory()->create(['mode' => BookingMode::PerVessel, 'vessel_id' => $vessel->getKey()]);
        $booking = Booking::factory()->create([
            'mode' => BookingMode::PerVessel,
            'product_id' => $product->getKey(),
            'vessel_id' => $vessel->getKey(),
        ]);

        return app(GenerateCharterAgreement::class)($booking);
    });

    // The same instant, with the operator's offset.
    expect($agreement->fields_snapshot['generated_at'])->toBe('2026-09-09T01:30:00+03:00');

    $html = view('pdf.charter-agreement', [
        'agreement' => $agreement,
        'fields' => $agreement->fields_snapshot,
        'brand' => [],
    ])->render();

    expect($html)->toContain('Δημιουργήθηκε 09/09/2026 01:30');
})->group('fast');
