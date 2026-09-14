<?php

declare(strict_types=1);

namespace App\Support\Pdf;

use Spatie\Browsershot\Browsershot;

/**
 * Every PDF this product prints, through one Chromium call (ARC-10, SEC-14,
 * ENV-20).
 *
 * ## Why this exists
 *
 * Four places printed a PDF — the e-ticket, the invoice, the ναυλοσύμφωνο and
 * the departure manifest — and three of them had the same twelve lines of
 * Browsershot setup copied into a private `render()`. The fourth had four of
 * those lines and none of the rest.
 *
 * That is not a tidiness complaint. The manifest was missing `noSandbox()`,
 * the `--disable-web-security=false` argument and the timeout — all three of
 * them SEC-14's, so the one PDF built from a page of passport numbers was the
 * one printed with the weakest settings. It was also missing ENV-20's
 * `chrome_path`, which is how it was found: on 14 September «Κατάσταση
 * επιβατών» threw `ProcessFailedException` on a machine where the other three
 * printed fine, because it was the only one not told where Chrome is.
 *
 * A copied block drifts silently and there is no test that can see it drift.
 * One definition can only be wrong everywhere at once, which is the kind of
 * wrong somebody notices.
 *
 * ## The margin is the only thing a caller chooses
 *
 * The tickets print at 12mm and everything else at 15mm, which is a typographic
 * decision belonging to each document. Nothing else differed between the three
 * copies, and nothing else should: a caller that could switch the sandbox off
 * would be a caller that could switch SEC-14 off.
 */
final class ChromiumPdf
{
    /** Tickets, which are laid out tighter than the paperwork. */
    public const MARGIN_TICKET = 12;

    /** Invoices, agreements, manifests. */
    public const MARGIN_DOCUMENT = 15;

    /**
     * Print rendered HTML to PDF bytes.
     *
     * `html()` and never a URL: the renderer carries no session, so pointing it
     * at a panel page would print a login screen — and pointing it at an
     * unauthenticated route would mean an unauthenticated route that renders
     * passport numbers.
     */
    public static function render(string $html, int $margin = self::MARGIN_DOCUMENT): string
    {
        $shot = Browsershot::html($html)
            ->format('A4')
            ->margins($margin, $margin, $margin, $margin)
            ->showBackground()
            // SEC-14, both halves: no sandbox because the container has no user
            // namespaces, and web security left on so a document can never
            // fetch anything of its own.
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
}
