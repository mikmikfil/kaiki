<?php

declare(strict_types=1);

/*
 * The fleet's day (spec OPS-3, OPS-4).
 *
 * `buffer_hint` is the one that earns its place. The availability engine refuses
 * an overlapping booking because of a turnaround the operator cannot see, and
 * "why can't I book this, the boat is free" is the support call that follows.
 * The margin on the bar is the answer, and this sentence is what it says.
 */

return [
    'title' => 'Calendar',

    // A departure whose trip has been deleted. Drawn as an occupied slot
    // rather than taking the whole panel down.
    'trip_gone' => 'Deleted trip',

    'previous' => 'Previous day',
    'today' => 'Today',
    'next' => 'Next day',

    'drag_hint' => 'Drag across empty time to block a boat. Click a trip to see who is on it.',
    // The phone: a list per boat instead of the timeline (2026-09-23).
    'list_hint' => 'Tap a trip to see who is on it.',
    'free' => 'Free',
    'all_day' => 'All day',
    'no_vessels' => 'Add a boat and it will appear here.',
    'turnaround' => ':minutes min turnaround',
    'buffer_hint' => 'Turnaround: the boat is not available for this long after the trip.',

    'key' => [
        'departure' => 'Trip',
        'block' => 'Blocked',
        'external' => 'From another calendar',
        'buffer' => 'Turnaround',
    ],

    'block' => [
        'title' => 'Block this boat',
        'vessel' => 'Boat',
        'starts_at' => 'From',
        'ends_at' => 'Until',
        'reason' => 'Why',
        'label' => 'Label',
        'notes' => 'Notes',
        'created' => 'The boat is blocked.',
        'covers' => 'This covers :departures trip(s) with :pax passenger(s) already booked. They have not been told — cancel those trips to let them know.',
    ],

    'pax' => [
        'title' => 'Who is on this trip',
        'gone' => 'That trip is no longer there.',
        'nobody' => 'Nobody has booked this trip yet.',
        'seats' => ':sold of :capacity seats',
        'guest' => 'Guest',
        'people' => 'People',
        'reference' => 'Reference',
        'owed' => 'Still owed',

        // The brief above the names (2026-09-23, direction Β). For whoever is
        // standing on the quay: times, boat, where from, and what to tell
        // anybody who asks. No prices — TEN-8 does not move.
        'brief' => [
            'boarding' => 'Boarding',
            'returns' => 'Back at',
            'vessel' => 'Boat',
            'where' => 'From',
            'more' => 'What is included and what to bring',
            'includes' => 'Included',
            'bring' => 'To bring',
        ],
    ],
    'assign' => [
        'title' => 'Assign',
        'saved' => 'Saved',
    ],
    // «Sell now» on the quay (24/9, option Β).
    'sell' => [
        'title' => 'Sell now',
        'which' => ':trip · :time · :left seats left',
        'total' => 'Total',
        'no_price' => 'Choose the people',
        'name' => 'Guest name',
        'email' => 'Email (optional)',
        'phone' => 'Phone (optional)',
        'pos' => 'Card on my POS',
        'cash' => 'Cash',
        'done' => 'Paid · :pax people · :amount',
        'done_body' => ':how · booking :reference',
        'no_pax' => 'Add at least one person',
        'full' => 'There are not that many seats left',
        'refused' => 'The sale did not go through',
        'unavailable' => 'This departure cannot be sold now',
    ],
];
