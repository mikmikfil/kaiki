<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Actions;

use App\Domain\Booking\Support\TicketQr;
use App\Domain\Branding\Actions\GetBrandPayload;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Tenant;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Browsershot\Browsershot;

/**
 * The invoice a guest can download (spec MYD-12).
 *
 * > *"The invoice PDF carries the myDATA QR code and is downloadable by the
 * > guest from `/b/{manage_token}` once issued."*
 *
 * ## Only a registered document gets a PDF
 *
 * "Once issued" is the operative clause. A `pending` invoice has no number and
 * no MARK, so a PDF of one would be a document that looks official and carries
 * neither — handed to a guest, then contradicted by the real one a minute later.
 * A `failed` one is worse: it says a sale was filed that was not.
 *
 * So this refuses anything that is not `sent`, and the guest page shows a
 * download link only when a file exists.
 *
 * ## The QR is the AADE URL, and it is absent rather than faked when there is none
 *
 * `invoices.qr_url` comes back with the MARK and points at AADE's own
 * verification page — scanning it is how anybody, including a tax inspector,
 * confirms the document is registered. It is **not** something this code can
 * construct: it is issued, not derived.
 *
 * A document that somehow reached `sent` without one therefore prints without a
 * QR rather than with a QR pointing somewhere plausible. A square that scans to
 * the wrong place is worse than no square, because somebody will scan it and
 * believe the answer.
 *
 * ## Rendered once and stored
 *
 * `pdf_path` is written on the row. A guest opening their booking page four
 * times does not start four headless browsers, and — more importantly — the
 * bytes do not change between viewings. An invoice regenerated on each request
 * is an invoice whose printed copy and screen copy can disagree, which is a
 * conversation nobody wants to have with an accountant.
 */
final class GenerateInvoicePdf
{
    /** @return string the stored path */
    public function __invoke(Invoice $invoice): string
    {
        if ($invoice->status !== InvoiceStatus::Sent) {
            throw new RuntimeException(
                "Only a registered invoice has a PDF; this one is {$invoice->status->value} (MYD-12).",
            );
        }

        if ($invoice->pdf_path !== null && $this->disk()->exists($invoice->pdf_path)) {
            // Already rendered. See the class docblock on why the bytes must not
            // change between viewings.
            return $invoice->pdf_path;
        }

        $invoice->loadMissing(['booking.product']);

        $tenant = Tenant::query()->findOrFail($invoice->tenant_id);

        /*
         * Greek, whoever asked for it.
         *
         * A Greek tax document is a Greek document: «ΑΛΠ», «ΦΠΑ», «ΑΦΜ» and the
         * whole template are Greek by law rather than by preference, and the
         * one translated string in it — the document type — has to match. The
         * first version printed «Retail receipt» directly under «ΑΛΠ Α/2026/1»,
         * because the render inherited whatever locale was current — which for a
         * queue worker is whatever the last job set.
         *
         * The same defect `GuestMail` and `AadeErrors::inGreek()` were both
         * written to avoid. An English guest still reads their own language
         * everywhere else; this one page is the tax authority's.
         */
        $previous = App::getLocale();
        App::setLocale('el');

        try {
            $html = view('pdf.invoice', [
                'invoice' => $invoice,
                'tenant' => $tenant,
                'brand' => app(GetBrandPayload::class)($tenant, 'el'),
                // Absent rather than invented — see the class docblock.
                'qr' => $invoice->qr_url === null ? null : TicketQr::svg($invoice->qr_url),
            ])->render();
        } finally {
            // Restored even if the render throws: a worker left in Greek would
            // send the next tenant's English guest a Greek confirmation.
            App::setLocale($previous);
        }

        $pdf = $this->render($html);
        $path = $this->pathFor($invoice);

        $this->disk()->put($path, $pdf);

        $invoice->forceFill(['pdf_path' => $path])->save();

        return $path;
    }

    /**
     * The same locked-down Chromium as the e-ticket and the ναυλοσύμφωνο.
     *
     * `setHtml` rather than `setUrl` (SEC-14): pointing a headless browser at a
     * route would also mean authenticating it, which is a session on a browser
     * nobody wants to own.
     */
    private function render(string $html): string
    {
        $shot = Browsershot::html($html)
            ->format('A4')
            ->margins(15, 15, 15, 15)
            ->showBackground()
            ->noSandbox()
            ->setOption('args', ['--disable-web-security=false'])
            ->timeout((int) config('kaiki.tickets.timeout_seconds', 30));

        $chrome = config('kaiki.tickets.chrome_path');

        if (is_string($chrome) && $chrome !== '') {
            $shot->setChromePath($chrome);
        }

        return $shot->pdf();
    }

    /**
     * Tenant, then the invoice's uuid.
     *
     * The uuid rather than the reference: «ΑΛΠ Α/2026/41» contains slashes and
     * Greek, and a filename is not the place to find out how a storage driver
     * feels about either.
     */
    private function pathFor(Invoice $invoice): string
    {
        return sprintf('tenants/%d/invoices/%s.pdf', $invoice->tenant_id, $invoice->uuid);
    }

    /**
     * The private disk, and the guest reaches it through a tokenised route.
     *
     * An invoice carries an operator's legal identity and, on a ΤΠΥ, a
     * customer's ΑΦΜ. A public URL would make every one of them enumerable.
     */
    private function disk(): Filesystem
    {
        return Storage::disk((string) config('kaiki.tickets.disk', 'local'));
    }
}
