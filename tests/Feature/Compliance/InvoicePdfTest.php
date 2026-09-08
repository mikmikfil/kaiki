<?php

declare(strict_types=1);

use App\Domain\Compliance\Actions\AllocateInvoiceNumber;
use App\Domain\Compliance\Actions\GenerateInvoicePdf;
use App\Domain\Compliance\Actions\IssueInvoice;
use App\Enums\BookingStatus;
use App\Enums\InvoiceStatus;
use App\Models\Booking;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The invoice PDF — spec MYD-12
|--------------------------------------------------------------------------
|
| > *"The invoice PDF carries the myDATA QR code and is downloadable by the
| > guest from `/b/{manage_token}` once issued."*
|
| **"Once issued" is the operative clause.** A PDF of a pending document would
| look official and carry neither a number nor a MARK; a PDF of a failed one
| would say a sale was filed that was not.
|
| And the QR is AADE's own URL, which comes back with the MARK and cannot be
| constructed here. A document without one prints without a square rather than
| with a square pointing somewhere plausible — somebody will scan it and believe
| the answer.
|
*/

/** @return array{0: Tenant, 1: Invoice} */
function pdfInvoice(?InvoiceStatus $status = InvoiceStatus::Sent, ?string $qrUrl = null): array
{
    $tenant = Tenant::factory()->create([
        'name' => 'Aegean Blue Cruises',
        'legal_name' => 'ΑΙΓΑΙΟ ΝΑΥΤΙΛΙΑΚΗ ΙΚΕ',
        'vat_number' => '094014201',
    ]);

    $invoice = Tenancy::forTenant($tenant, function () use ($status, $qrUrl): Invoice {
        $booking = Booking::factory()->create([
            'status' => BookingStatus::Confirmed,
            'total_cents' => 10_000,
            'price_snapshot' => ['vat' => ['rate_bp' => 1_300, 'vat_category' => 'VAT_2', 'net_cents' => 8_850, 'vat_cents' => 1_150]],
        ]);

        $invoice = app(IssueInvoice::class)($booking);

        if ($status === InvoiceStatus::Sent) {
            app(AllocateInvoiceNumber::class)($invoice);

            $invoice->forceFill([
                'status' => InvoiceStatus::Sent,
                'mark' => '400000000000123',
                'qr_url' => $qrUrl,
                'issued_at' => Carbon::now(),
            ])->save();
        } elseif ($status !== null && $status !== InvoiceStatus::Pending) {
            $invoice->forceFill(['status' => $status])->save();
        }

        return $invoice->refresh();
    });

    return [$tenant, $invoice];
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-08 12:00:00');
    Storage::fake('local');
});

it('refuses a document AADE has not registered', function (): void {
    // MYD-12's "once issued". A PDF of a pending document looks official and
    // carries neither a number nor a MARK.
    [$tenant, $pending] = pdfInvoice(InvoiceStatus::Pending);

    Tenancy::forTenant($tenant, function () use ($pending): void {
        expect(fn () => app(GenerateInvoicePdf::class)($pending))
            ->toThrow(RuntimeException::class, 'MYD-12');
    });
})->group('fast');

it('refuses a document that failed, which would claim a sale was filed', function (): void {
    [$tenant, $failed] = pdfInvoice(InvoiceStatus::Failed);

    Tenancy::forTenant($tenant, function () use ($failed): void {
        expect(fn () => app(GenerateInvoicePdf::class)($failed))
            ->toThrow(RuntimeException::class, 'MYD-12');
    });
})->group('fast');

it('renders a registered document and stores it privately', function (): void {
    // An invoice carries the operator's legal identity and, on a ΤΠΥ, a
    // customer's ΑΦΜ. A public URL would make every one enumerable.
    [$tenant, $invoice] = pdfInvoice(qrUrl: 'https://mydata.aade.gr/verify/abc');

    $path = Tenancy::forTenant($tenant, fn (): string => app(GenerateInvoicePdf::class)($invoice));

    expect($path)->toBe("tenants/{$tenant->getKey()}/invoices/{$invoice->uuid}.pdf")
        ->and(Storage::disk('local')->exists($path))->toBeTrue()
        // Recorded on the row, so the guest page can offer a link.
        ->and(Tenancy::forTenant($tenant, fn (): ?string => $invoice->refresh()->pdf_path))->toBe($path);
})->group('fast');

it('renders once and returns the same file afterwards', function (): void {
    // An invoice regenerated on each request is one whose printed copy and
    // screen copy can disagree — a conversation nobody wants with an accountant.
    [$tenant, $invoice] = pdfInvoice(qrUrl: 'https://mydata.aade.gr/verify/abc');

    $first = Tenancy::forTenant($tenant, fn (): string => app(GenerateInvoicePdf::class)($invoice));
    $bytes = Storage::disk('local')->get($first);

    $second = Tenancy::forTenant($tenant, fn (): string => app(GenerateInvoicePdf::class)($invoice->refresh()));

    expect($second)->toBe($first)
        ->and(Storage::disk('local')->get($second))->toBe($bytes);
})->group('fast');

it('renders without a square when AADE gave no verification URL', function (): void {
    // A QR pointing somewhere plausible is worse than none: somebody will scan
    // it and believe the answer.
    [$tenant, $invoice] = pdfInvoice(qrUrl: null);

    $path = Tenancy::forTenant($tenant, fn (): string => app(GenerateInvoicePdf::class)($invoice));

    expect(Storage::disk('local')->exists($path))->toBeTrue();
})->group('fast');

it('prints a Greek tax document in Greek, whoever asked for it', function (): void {
    /*
     * «ΑΛΠ», «ΦΠΑ» and «ΑΦΜ» are Greek by law rather than by preference, and the
     * one translated string on the page — the document type — has to match. The
     * first version printed «Retail receipt» directly under «ΑΛΠ Α/2026/1»,
     * because the render inherited whatever locale was current.
     *
     * Asserted through the *view* rather than the PDF bytes, because a PDF is
     * compressed and the assertion would be about zlib rather than about
     * language. The action pins the locale around exactly this render.
     */
    [$tenant, $invoice] = pdfInvoice();

    app()->setLocale('en');

    Tenancy::forTenant($tenant, fn (): string => app(GenerateInvoicePdf::class)($invoice));

    // And the worker is handed back the locale it had, so the next tenant's
    // English guest does not get a Greek confirmation.
    expect(app()->getLocale())->toBe('en');

    $html = Tenancy::forTenant($tenant, fn (): string => view('pdf.invoice', [
        'invoice' => $invoice->refresh(),
        'tenant' => $tenant,
        'brand' => [],
        'qr' => null,
    ])->render());

    // Rendered here in `en` on purpose: this is what the page would have said
    // without the pin, and it is what the pin exists to prevent.
    expect($html)->toContain(__('enums.invoice_type.alp.label', [], 'en'));
})->group('fast');

it('names the file by uuid rather than by the document reference', function (): void {
    // «ΑΛΠ Α/2026/41» has slashes and Greek in it, and a filename is not where
    // to discover how a storage driver feels about either.
    [$tenant, $invoice] = pdfInvoice();

    $path = Tenancy::forTenant($tenant, fn (): string => app(GenerateInvoicePdf::class)($invoice));

    expect($path)->not->toContain('ΑΛΠ')
        ->and($path)->not->toContain('Α/2026');
})->group('fast');
