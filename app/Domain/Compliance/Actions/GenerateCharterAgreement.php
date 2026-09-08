<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Actions;

use App\Domain\Branding\Actions\GetBrandPayload;
use App\Enums\AgreementStatus;
use App\Enums\BookingMode;
use App\Models\Booking;
use App\Models\CharterAgreement;
use App\Models\Tenant;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Browsershot\Browsershot;

/**
 * The ναυλοσύμφωνο, rendered and frozen (spec CMP-6, CMP-7, CMP-8, CMP-9).
 *
 * ## The mechanism is buildable; the text is not, and the difference matters
 *
 * A ναυλοσύμφωνο is prescribed by ΚΥΑ Α.Π. 3133.1/47821, and **nobody here has
 * read it**. `CharterAgreement`'s docblock has said since #88 that M6 is blocked
 * on a legal question rather than on code, and that is still true of the
 * *wording*.
 *
 * It is not true of everything else. The document has to be produced from a
 * versioned template, filled from the booking, hashed, stored, sent to both
 * parties and accepted by the guest with a timestamp and an IP — and every one
 * of those is ours. So the machinery is built, and the template it renders says
 * in its own first paragraph, in Greek, that it is provisional and awaiting a
 * lawyer's text.
 *
 * That is a deliberate choice over two worse ones. Building nothing leaves the
 * whole of CMP-6 to be done after a legal answer that may take weeks. Inventing
 * plausible ΚΥΑ wording would produce a document an operator might actually
 * hand to a harbour master, which is a great deal worse than an obviously
 * unfinished one.
 *
 * ## `template_version` is what makes CMP-8 work
 *
 * *"Changing the template never alters an already-accepted agreement. A new
 * version applies to new bookings only."* The version travels with the row and
 * with the snapshot, so a regeneration against an accepted agreement produces a
 * **new row** rather than overwriting evidence — {@see CharterAgreement::openVersionFor()}
 * is that seam, and it existed before this did.
 *
 * ## The snapshot is the point of CMP-7
 *
 * *"…so a regenerated PDF is always byte-comparable in content to the one the
 * guest accepted."* Every value the template prints is copied into
 * `fields_snapshot` at generation. A vessel renamed in March cannot change what
 * a guest agreed to in February, and the hash is what proves the file is the
 * one that was produced.
 *
 * ## Per-vessel only, and it never blocks a booking
 *
 * CMP-9: acceptance is required for a per-vessel charter and **must not block
 * the booking itself**. So this generates and sends; it does not gate anything.
 * An outstanding agreement is chased by reminders and shown in the dashboard,
 * which is a nudge rather than a wall — a guest who cannot pay because a
 * document is unsigned is a guest who books elsewhere.
 */
final class GenerateCharterAgreement
{
    /**
     * The template this build renders.
     *
     * **`provisional-` is load-bearing.** CMP-8 keys immutability to the
     * version, so when a lawyer's text arrives it becomes `v1` and every
     * agreement produced under this one is visibly, permanently distinguishable
     * from a real one — in the row, in the snapshot and in the filename. A
     * version called `v1` today would make that impossible to tell apart later.
     */
    public const TEMPLATE_VERSION = 'provisional-2026-09';

    public const TEMPLATE_KEY = 'default';

    public function __invoke(Booking $booking): CharterAgreement
    {
        if ($booking->mode !== BookingMode::PerVessel) {
            // CMP-6 is about a charter. A per-seat booking is a ticket, and a
            // charter agreement for one would be a document nobody can explain.
            throw new RuntimeException('A ναυλοσύμφωνο belongs to a per-vessel booking (CMP-6).');
        }

        $booking->loadMissing(['product.meetingPoint', 'vessel', 'guests']);

        $agreement = CharterAgreement::openVersionFor($booking, self::TEMPLATE_VERSION, self::TEMPLATE_KEY);

        $snapshot = $this->snapshot($booking);

        $html = view('pdf.charter-agreement', [
            'agreement' => $agreement,
            'fields' => $snapshot,
            'brand' => $this->brand($booking),
        ])->render();

        $pdf = $this->render($html);
        $path = $this->pathFor($booking, $agreement);

        $this->disk()->put($path, $pdf);

        $agreement->forceFill([
            'fields_snapshot' => $snapshot,
            'pdf_path' => $path,
            // CMP-7's other half: the snapshot says what it should contain and
            // this says what it did contain.
            'pdf_hash' => hash('sha256', $pdf),
            'generated_at' => Carbon::now(),
            'status' => AgreementStatus::Generated,
        ])->save();

        return $agreement;
    }

    /**
     * Everything the template prints, frozen at this moment (CMP-7).
     *
     * Read from the booking and its relations **once**, here, rather than by the
     * template reaching back through the model. A template that reads
     * `$booking->vessel->name` renders today's name every time it runs; this
     * renders February's name in February's document, for ever.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Booking $booking): array
    {
        // `findOrFail`: a booking without its tenant is not a state this can
        // produce a document for, and the alternative is a ναυλοσύμφωνο with an
        // empty party — which is worse than an exception because somebody would
        // send it.
        $tenant = Tenant::query()->findOrFail($booking->tenant_id);

        return [
            'template_version' => self::TEMPLATE_VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),

            'operator' => [
                'name' => $tenant->name,
                // The legal identity, not the trading name: a ναυλοσύμφωνο is
                // between two legal persons, and «Aegean Blue Cruises» is a
                // brand while «ΑΙΓΑΙΟ ΝΑΥΤΙΛΙΑΚΗ ΙΚΕ» is a party.
                'legal_name' => $tenant->legal_name ?: $tenant->name,
                'vat_number' => $tenant->vat_number,
                // Through the accessor, so «Πειραιά» and «ΔΟΥ Πειραιά» both
                // land on the document as «ΔΟΥ Πειραιά». See `Tenant`.
                'tax_office' => $tenant->taxOfficeName(),
                'gemi_number' => $tenant->gemi_number,
                'address' => trim(implode(' ', array_filter([
                    $tenant->address_line1,
                    $tenant->address_line2,
                    $tenant->postcode,
                    $tenant->city,
                ]))),
                'phone' => $tenant->phone,
                'email' => $tenant->email,
            ],

            'charterer' => [
                'name' => $booking->guest_name,
                'email' => $booking->guest_email,
                'phone' => $booking->guest_phone,
                'vat_number' => $booking->guest_vat_number,
                'company_name' => $booking->guest_company_name,
            ],

            'vessel' => [
                'name' => $booking->vessel?->name,
                // The ΑΛΣ number is what identifies the boat to a harbour
                // master, and it is the field a hand-written form always has.
                'registration_number' => $booking->vessel?->registration_number,
                'captain_name' => $booking->vessel?->captain_name,
                'capacity_max' => $booking->vessel?->capacity_max,
            ],

            'charter' => [
                'reference' => $booking->reference,
                'local_date' => $booking->local_date?->toDateString(),
                'local_time' => $booking->local_time,
                'duration_minutes' => $booking->product?->duration_minutes,
                'port' => $booking->product?->meetingPoint?->name,
                'pax_total' => $booking->pax_total,
                'product' => $booking->product?->getTranslation('title', 'el'),
            ],

            'money' => [
                'total_cents' => $booking->total_cents,
                'paid_cents' => $booking->paid_cents,
                'balance_cents' => $booking->balance_cents,
                'currency' => $tenant->currency ?? 'EUR',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function brand(Booking $booking): array
    {
        $tenant = Tenant::query()->find($booking->tenant_id);

        return $tenant instanceof Tenant
            ? app(GetBrandPayload::class)($tenant, $booking->locale ?? 'el')
            : [];
    }

    /**
     * HTML in, PDF bytes out — the same locked-down Chromium as the e-ticket.
     *
     * `setHtml` rather than `setUrl` (SEC-14), no file access, and the operator's
     * own text rendered as a string. A charter agreement carries more
     * operator-authored prose than a ticket does, so the argument is stronger
     * here rather than weaker.
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
     * Tenant, then booking uuid, then version.
     *
     * The version is in the filename so a regenerated agreement under a new
     * template does not overwrite the file a guest already accepted — the row
     * is protected by `openVersionFor()`, and the file has to be too, or the
     * hash on the old row points at bytes that are no longer there.
     */
    private function pathFor(Booking $booking, CharterAgreement $agreement): string
    {
        return sprintf(
            'tenants/%d/charter-agreements/%s-%s.pdf',
            $booking->tenant_id,
            $booking->uuid,
            $agreement->template_version,
        );
    }

    /**
     * The private disk. A ναυλοσύμφωνο carries both parties' legal identities.
     */
    private function disk(): Filesystem
    {
        return Storage::disk((string) config('kaiki.tickets.disk', 'local'));
    }
}
