<?php

declare(strict_types=1);

namespace App\Domain\Booking\Support;

use App\Models\BookingGuest;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * The square a crew member points a phone at (spec BKG-13.1, BKG-20).
 *
 * ## What goes in it is a security decision, not a formatting one
 *
 * The issue for #88 states the rule and #88 sharpens it: the payload carries a
 * **token**, never the booking reference and never a uuid.
 *
 * - The **reference** (BKG-3) is short and human-typeable *on purpose* — that
 *   is what makes it unsuitable. Its alphabet yields about 28.6 million
 *   combinations per tenant, which is a number somebody with a printer and a
 *   morning can work through.
 * - The **uuid** is not guessable, but it is the public identifier CNV-8 puts
 *   in API responses and widget payloads, so it is a value that legitimately
 *   travels. A credential that also appears in a JSON body is not a credential.
 *
 * ## `ticket_code`, and not `manage_token`
 *
 * §2.5 already settled this: `booking_guests.ticket_code` is *"globally unique;
 * the QR payload"*, and `booking_guests_ticket_code_unique` exists so a scan
 * *"resolves with one indexed read and no tenant context"* — which is the exact
 * situation of somebody standing on a pier with a phone.
 *
 * `manage_token` fails here twice over. It is **per booking**, so a family of
 * four would carry four identical QR codes and per-guest check-in — which
 * BKG-21 and BKG-23 both require — would be impossible. And it is the
 * credential for `/b/{manage_token}`, where a guest can **cancel the booking
 * and take a refund**: printing it on a sheet of paper that gets handed around,
 * photographed and left on a seat puts the cancel button in anybody's hands.
 * A ticket code can do exactly one thing, and only for somebody already logged
 * into the operator's panel.
 *
 * ## SVG, inline, and no image files anywhere
 *
 * The SVG backend needs no `imagick` and no `gd`, so the PDF renders on any
 * host. It is embedded in the Blade markup rather than written to disk, which
 * keeps SEC-14's locked-down Chromium honest — a renderer that has to reach a
 * local file is a renderer with local file access.
 *
 * ## PNG, for the email, and only for the email
 *
 * An inbox is the one place SVG does not work: Gmail strips it and Outlook on
 * Windows renders through Word, which has never heard of it. The confirmation
 * carries the codes as PNGs (product owner, 2026-09-23), embedded in the
 * message as `cid:` parts rather than linked — a linked image is a remote
 * fetch every client blocks by default, and a URL that serves a boarding code
 * is a URL somebody can guess. {@see pngFor()} draws the **same payload** with
 * bacon's own GD renderer, so there is one generator and one decision about
 * what a ticket says, in two formats.
 *
 * PNG and not JPEG: a QR is two flat colours with hard edges, which is what
 * PNG compresses losslessly to a few hundred bytes and what JPEG smears into
 * grey halos a phone camera has to guess through.
 *
 * GD is the one requirement this adds, and it is declared (`ext-gd` in
 * `composer.json`) rather than assumed; the SVG path still needs nothing.
 */
final class TicketQr
{
    /** Roughly a 3cm square at print resolution — comfortably scannable, small enough to sit beside the text. */
    private const SIZE_PX = 220;

    /** About twice the 200 px the email draws it at; see {@see png()}. */
    public const PNG_PX = 400;

    /** The white border every QR reader expects, in modules (ISO/IEC 18004). */
    private const QUIET_ZONE_MODULES = 4;

    /**
     * The scan URL for one guest.
     *
     * A URL rather than a bare code, because the thing scanning it is an
     * ordinary phone camera: pointing it at a ticket should open the boarding
     * page with the guest already resolved, not show a string to be typed in.
     * The page accepts a pasted code too, for the ticket that will not scan.
     *
     * ## It points at the offline page now, and the old URL still works
     *
     * Until #128 this was `filament.app.pages.check-in`, the Livewire page —
     * which does nothing at all without a signal, and a pier is where signals
     * go to die. New tickets carry `/app/boarding`, which has today's manifest
     * in its own bytes and queues a scan when the network is gone.
     *
     * **Every ticket already printed keeps working.** The Filament page still
     * accepts `?ticket=…` and still checks people in; nothing was removed from
     * it. That matters more than tidiness here, because a QR on a sheet of
     * paper in somebody's bag cannot be reissued.
     */
    public static function payloadFor(BookingGuest $guest): string
    {
        return route('filament.app.boarding', ['ticket' => $guest->ticket_code]);
    }

    /** The QR as an inline `<svg>` element. */
    public static function svgFor(BookingGuest $guest): string
    {
        return self::svg(self::payloadFor($guest));
    }

    /**
     * The QR as PNG bytes, for the one place SVG cannot go (see above).
     *
     * @see self::png()
     */
    public static function pngFor(BookingGuest $guest): string
    {
        return self::png(self::payloadFor($guest));
    }

    /**
     * Black on white, with the standard four-module white quiet zone, and a
     * whole number of pixels per module.
     *
     * The size is worked out from the encoded matrix rather than fixed, because
     * a fixed size divided by 41 modules is 9.76 pixels each and GD rounds every
     * edge its own way — uneven modules are what a cheap phone camera fails on.
     * The result is roughly {@see PNG_PX} square: twice the size the email shows
     * it at, so it stays sharp on a phone's high-density screen.
     */
    public static function png(string $payload): string
    {
        $qr = Encoder::encode($payload, ErrorCorrectionLevel::L(), Encoder::DEFAULT_BYTE_MODE_ENCODING);
        $modules = $qr->getMatrix()->getWidth() + 2 * self::QUIET_ZONE_MODULES;
        $size = $modules * max(1, intdiv(self::PNG_PX, $modules));

        return (new GDLibRenderer($size, self::QUIET_ZONE_MODULES))->render($qr);
    }

    public static function svg(string $payload): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(self::SIZE_PX, margin: 0),
            new SvgImageBackEnd,
        ));

        // Bacon emits a standalone document; the XML declaration is invalid
        // inside an HTML body and Chromium's parser drops the whole element
        // when it sees one.
        return (string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $writer->writeString($payload));
    }
}
