<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Calendar sync — iCal out and iCal in (spec OPS-13, OPS-14, OPS-15)
|--------------------------------------------------------------------------
|
| The two summary lines at the top are the only text that leaves the platform on
| an unauthenticated URL, and OPS-14 is why they are so plain: the feed says the
| boat is busy and nothing else. No guest name, no trip title, no party size —
| a link pasted into a third-party service ends up in logs and inboxes nobody
| here controls.
*/

return [

    'nav' => 'Calendar sync',
    'title' => 'Calendar sync',
    'subheading' => 'Publish each boat’s busy periods, and pull in bookings made elsewhere.',

    'summary' => [
        // Deliberately neutral. See the file header.
        'blocked' => 'Not available',
        'booked' => 'Booked',
    ],

    'export' => [
        'heading' => 'Publish this boat’s calendar',
        'body' => 'Paste this address into Google Calendar, Airbnb or any other calendar. It shows busy periods only — never a guest name, a trip name or a price.',
        'url' => 'Subscription address',
        'copy' => 'Copy',
        'copied' => 'Copied',
        'rotate' => 'Replace the address',
        'rotate_confirm' => 'Anyone using the old address stops seeing this calendar immediately, and will need the new one. Replace it?',
        'rotated' => 'A new address is ready. Update it wherever you pasted the old one.',
        'enable' => 'Publish',
        'disable' => 'Stop publishing',
        'disabled' => 'Not published',
        'include_departures' => 'Include booked trips',
        'include_blocks' => 'Include days you have blocked',
        'last_read' => 'Last read',
        'never_read' => 'Not read yet',
        'reads' => 'Reads',
    ],

    'import' => [
        'heading' => 'Bring in bookings from elsewhere',
        'body' => 'Kaiki checks each address every fifteen minutes and blocks the boat for anything it finds. Nothing is ever sent back.',
        'add' => 'Add a calendar',
        'name' => 'What is it',
        'name_placeholder' => 'Airbnb, the other agency, …',
        'url' => 'Address of the calendar',
        'url_help' => 'The private “export” or “subscription” link from the other service.',
        'sync_now' => 'Check now',
        'queued' => 'Checking now — this page updates when it finishes.',
        'remove' => 'Remove',
        'remove_confirm' => 'The blocks this calendar created are removed too, except any that already have a booking against them. Remove it?',
        'removed' => 'Calendar removed.',
        'added' => 'Calendar added. The first check runs within fifteen minutes.',
        'duplicate' => 'That calendar is already here.',
        'last_checked' => 'Last checked',
        'never_checked' => 'Not checked yet',
        'events' => 'Blocks from this calendar',
        'status_ok' => 'Working',
        'status_attention' => 'Needs a look',
        'status_off' => 'Switched off',
        'failures' => 'Failed :count times in a row',
        /*
         * OPS-15's "surfaced to the operator after three consecutive failures",
         * in the words an operator can act on. It names the likeliest cause,
         * because "sync failed" sends them to us and "the address may have
         * changed" sends them to the right place.
         */
        'attention' => 'This calendar has not been readable for a while. The other service may have changed or withdrawn the address — open it there and paste the current one in.',
        'disabled_notice' => 'Kaiki stopped checking this calendar after ten failures in a row. Fix the address and switch it back on.',
    ],

    /*
     * Failures, in the operator's own language (NFR-8).
     *
     * Stored on the source row when the sync fails. The exception text goes to
     * the structured log; an operator cannot act on a cURL error number.
     */
    'errors' => [
        'unreachable' => 'The other service did not answer.',
        'http_status' => 'The other service refused the request. The address may have expired.',
        'unparseable' => 'The address answered, but not with a calendar.',
        'too_large' => 'That calendar is too large to read.',
        'invalid_url' => 'That does not look like a calendar address.',
    ],

];
