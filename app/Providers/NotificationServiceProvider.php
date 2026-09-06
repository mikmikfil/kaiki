<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\BookingConfirmed;
use App\Listeners\Booking\GenerateETicketOnConfirmation;
use App\Listeners\Booking\SendBookingConfirmation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * BKG-13's listeners, wired one line each (spec BKG-13, BKG-14).
 *
 * ## Explicit, rather than discovered
 *
 * Laravel can auto-discover listeners by type-hint. This registers them by hand
 * because BKG-13 is a **list of nine** and the useful property of that list is
 * that somebody can read it: a listener that stopped being discovered — renamed
 * a parameter, moved a namespace — fails silently and produces a confirmed
 * booking that nobody was told about. A missing line here is visible in a diff.
 *
 * ## Nine eventually, and the ones not here yet say where they are
 *
 * | # | Listener | Where |
 * |---|---|---|
 * | 1 | e-ticket PDF with QR | **here**, added by #88 |
 * | 2 | confirmation email | **here** |
 * | 3 | confirmation SMS | **here**, same listener |
 * | 4 | myDATA invoice | M6 |
 * | 5 | ναυλοσύμφωνο generation | M6 |
 * | 6 | guest-details email | **here**, in the reminder sweep's first pass |
 * | 7 | schedule the reminders | **here**, as a sweep rather than delayed jobs |
 * | 8 | `booking.confirmed` webhook | M5 |
 * | 9 | departure `guaranteed` state | already wired, since #81 |
 *
 * Two and three are one listener rather than two, and that is the one place
 * this deviates from BKG-13's numbering: they share a template, a locale
 * resolution and a "did the guest give us a phone number" decision, and
 * splitting them would duplicate all three. They remain independently visible
 * because the **log** has a row per channel, which is what BKG-14 asks the
 * panel to show.
 */
class NotificationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // BKG-14: each listener is queued and independently retryable, so a
        // Postmark outage cannot roll back a confirmation or stop the e-ticket.
        Event::listen(BookingConfirmed::class, SendBookingConfirmation::class);

        // BKG-13.1. Registered second and running independently: this one
        // starts a headless Chromium, which is the heaviest of the nine and the
        // one most likely to fail for reasons that have nothing to do with the
        // booking. The email must not wait for it and must not be lost with it.
        Event::listen(BookingConfirmed::class, GenerateETicketOnConfirmation::class);
    }
}
