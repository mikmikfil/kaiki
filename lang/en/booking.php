<?php

declare(strict_types=1);

/*
 * Bookings, holds and the reference (spec BKG-3 … BKG-8, AVL-37 … AVL-41).
 *
 * The hold messages are the ones that matter most here, and they are written
 * for a guest at a checkout rather than for a developer reading a log. Three
 * things go wrong and they are three different sentences:
 *
 *   contended  — somebody else is a fraction of a second ahead. Try again; the
 *                seat is probably still there.
 *   expired    — your own hold ran out while the page sat open. The seats went
 *                back and we could not get them again.
 *   not enough — they are genuinely gone.
 *
 * Collapsing those into "we could not hold your seats" is how a guest who could
 * still book leaves the site.
 */

return [
    'hold' => [
        'contended' => 'Someone else is booking the same trip right now. Please try again in a moment — the seats are most likely still available.',
        'expired' => 'Your seats were held for a while and that time has run out. We could not get them back, so please pick another time.',
        'not_enough_seats' => 'Only :available seat(s) are left on this trip and you asked for :requested. Please choose a smaller party or another time.',
        'quote_mode' => 'This trip is priced on request, so there are no seats to hold.',
        'nothing_to_hold' => 'This booking has nobody in it who takes up a seat.',
    ],

    'draft' => [
        'no_departure' => 'There is no departure for this trip on :date.',
    ],

    'reference' => [
        'label' => 'Booking reference',
        /*
         * The rule's message. It says the shape rather than the alphabet,
         * because "31 characters excluding O, I, L and U" is true, unhelpful,
         * and longer than the reference it describes.
         */
        'invalid' => 'That does not look like a booking reference. They look like :example.',
    ],

    'status' => [
        'heading' => 'Status',
    ],
];
