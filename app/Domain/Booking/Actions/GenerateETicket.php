<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Branding\Actions\GetBrandPayload;
use App\Models\Booking;
use App\Models\CharterAgreement;
use App\Models\Tenant;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Spatie\Browsershot\Browsershot;

/**
 * The PDF a guest shows at the quay (spec BKG-13.1, ENV-20, SEC-14).
 *
 * ## Browsershot, and dompdf is forbidden
 *
 * §3's stack table (ARC-10, FIXED) settles it: *"`spatie/browsershot`
 * (Chromium) with Blade templates. dompdf is forbidden."* The reason is Greek —
 * dompdf's font handling mangles accented text and it cannot lay out the
 * two-column ticket without fighting. Chromium renders what a browser renders,
 * which is also what the template was written and looked at in.
 *
 * ## The Chromium is locked down, because the template contains operator text
 *
 * SEC-14: *"File and PDF generation with Browsershot runs with a locked-down
 * Chromium (no local file access, no arbitrary URL navigation) because
 * templates can contain operator-supplied text."* A meeting point's
 * instructions and a product's title are written by an operator and rendered
 * here; `--disable-web-security` off, no file access, and HTML handed over as a
 * string rather than a path is what keeps a crafted product description from
 * reading `/etc/passwd` into a guest's ticket.
 *
 * The QR is an inline SVG for the same reason ({@see TicketQr}) — a renderer
 * that has to open a local PNG is a renderer with local file access.
 *
 * ## The ticket goes on the private disk, never the public one
 *
 * It carries the guest's name, the meeting point and a scannable ticket code.
 * A file on the `public` disk is served by the web server to anybody who can
 * guess its path, with no session and no policy in the way — which would make
 * the storage layer a wider hole than every token page in #86 put together.
 * It is streamed through a controller instead, behind the manage-token page.
 *
 * ## Regenerating replaces the file and rewrites the hash
 *
 * There is no versioning here, and that is the difference between a ticket and
 * a ναυλοσύμφωνο: a ticket is a **convenience** and the booking is the truth,
 * so a guest who changed their name should get a corrected ticket rather than a
 * second one. The agreement holds evidence and is versioned instead
 * ({@see CharterAgreement}).
 */
final class GenerateETicket
{
    /** @return string the stored path */
    public function __invoke(Booking $booking): string
    {
        $booking->loadMissing(['guests', 'product.meetingPoint', 'vessel']);

        $html = view('pdf.e-ticket', [
            'booking' => $booking,
            'brand' => $this->brand($booking),
        ])->render();

        $pdf = $this->render($html);

        $disk = $this->disk();
        $path = $this->pathFor($booking);

        $disk->put($path, $pdf);

        $booking->forceFill([
            'eticket_path' => $path,
            // Proves the file a guest presents is the file we produced. One
            // `char(64)`, and the only alternative when somebody arrives with a
            // convincing forgery is an argument.
            'eticket_hash' => hash('sha256', $pdf),
            'eticket_generated_at' => Carbon::now(),
        ])->save();

        return $path;
    }

    /**
     * The operator's name and colour.
     *
     * BRD-3 guarantees a profile exists for every tenant, so this never has to
     * decide what an unbranded ticket looks like. The payload is the same one
     * the widget and the emails read, which is what stops a guest's ticket and
     * their confirmation email disagreeing about the operator's colour.
     *
     * @return array<string, mixed>
     */
    private function brand(Booking $booking): array
    {
        $tenant = Tenant::query()->find($booking->tenant_id);

        if (! $tenant instanceof Tenant) {
            return [];
        }

        return app(GetBrandPayload::class)($tenant, $booking->locale);
    }

    /**
     * HTML in, PDF bytes out.
     *
     * `setHtml` rather than `setUrl`: SEC-14 forbids arbitrary URL navigation,
     * and pointing Chromium at a route would also mean authenticating it,
     * which is a session on a headless browser nobody wants to own.
     */
    private function render(string $html): string
    {
        $shot = Browsershot::html($html)
            ->format('A4')
            ->margins(12, 12, 12, 12)
            ->showBackground()
            // SEC-14, both halves.
            ->noSandbox()
            ->setOption('args', ['--disable-web-security=false'])
            ->timeout((int) config('kaiki.tickets.timeout_seconds', 30));

        $chrome = config('kaiki.tickets.chrome_path');

        if (is_string($chrome) && $chrome !== '') {
            // ENV-20: locally this points at an installed Chrome through an
            // `.env` path. In CI the binary is on `PATH` and this is unset.
            $shot->setChromePath($chrome);
        }

        return $shot->pdf();
    }

    /**
     * Where the file lives.
     *
     * Tenant-first, then the booking's **uuid** rather than its reference:
     * CNV-8 keeps integer keys out of anything the outside world sees, and a
     * path built from a reference would be guessable in exactly the way the
     * reference is (BKG-3's alphabet). The private disk makes that academic;
     * defence in depth makes it worth two characters.
     */
    private function pathFor(Booking $booking): string
    {
        return sprintf('tenants/%d/tickets/%s.pdf', $booking->tenant_id, $booking->uuid);
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('kaiki.tickets.disk', 'local'));
    }
}
