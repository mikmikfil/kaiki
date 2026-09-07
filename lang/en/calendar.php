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

    'previous' => 'Previous day',
    'today' => 'Today',
    'next' => 'Next day',

    'drag_hint' => 'Drag across empty time to block a boat. Click a trip to see who is on it.',
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
    ],
];
