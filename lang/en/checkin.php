<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Check-in, on a phone, on a pier (spec BKG-20, BKG-21, BKG-22, BKG-23, CNV-11)
|--------------------------------------------------------------------------
|
| Every sentence here is read by a crew member who is standing outdoors,
| holding a phone in one hand, with somebody waiting in front of them. That
| single fact decides the tone: each refusal says **what to do next**, because
| "check-in failed" is a sentence that turns into a phone call to the office.
|
| CNV-11: none of this comes from an exception message.
|
*/

return [

    'nav' => 'Check-in',
    'title' => 'Check-in',
    'subtitle' => 'Scan a ticket, or search today\'s passengers.',
    // For an operator with QR boarding switched off: the list is the page.
    'subtitle_list' => 'Today\'s passengers. Tap «Check in» beside each one as they board.',
    // The offline page works only once it has been loaded, so the link says when.
    'offline_link' => 'No signal on the quay? Open this page before you leave the office.',

    'scan' => [
        'label' => 'Ticket code',
        'placeholder' => 'Scan the QR code, or type the code',
        // The camera is the normal path and typing is the fallback; the hint
        // says so rather than leaving somebody hunting for a scan button.
        'help' => 'Point the camera at the QR code on the ticket. If it will not scan, type the code printed under it.',
        'submit' => 'Find ticket',
    ],

    'search' => [
        'label' => 'Passenger or booking',
        'placeholder' => 'Name or reference',
        'empty' => 'Nothing sailing today matches that.',
    ],

    'today' => [
        'heading' => 'Today',
        'none' => 'No departures today.',
        // "3 of 8 aboard" — the number a crew member actually wants, and the
        // one that tells them when they can cast off.
        'aboard' => ':checked of :total aboard',
    ],

    'guest' => [
        'unnamed' => 'Name not supplied yet',
        'checked_in_at' => 'Aboard at :time',
        'no_show' => 'No-show',
    ],

    'actions' => [
        'check_in' => 'Check in',
        'undo' => 'Undo',
        'mark_no_show' => 'Mark no-show',
        'clear_no_show' => 'Clear no-show',

        'override' => [
            'label' => 'Check in early',
            // BKG-22. The modal exists to collect the reason, so it says what
            // the reason is for.
            'heading' => 'Check in before the window opens',
            'help' => 'Check-in for this trip opens at :time. An early check-in is recorded in the operator\'s activity log with your reason.',
            'reason' => 'Reason',
            'reason_placeholder' => 'Guest arrived early and the boat is alongside',
            'confirm' => 'Check in early',
        ],
    ],

    'done' => [
        'checked_in' => ':name is aboard.',
        'already' => ':name was already checked in.',
        'no_show' => ':name marked as a no-show.',
        'no_show_cleared' => 'No-show cleared for :name.',
    ],

    'refused' => [
        // BKG-22's early edge — the one refusal with a way through, so it says
        // what the way through is.
        'not_open' => 'Check-in for this trip opens at :time. A manager can check this guest in early with a reason.',
        // And the late edge, which has none.
        'trip_ended' => 'This trip has already finished. Check-in is closed.',
        'status' => 'This booking is :status, so nobody on it can be checked in. Send the guest to the office.',
        // One sentence for "no such code" and for "the booking is gone" — the
        // same reasoning TOK-4 applies to the guest pages.
        'unknown_ticket' => 'That ticket is not valid. Send the guest to the office.',
    ],

];
