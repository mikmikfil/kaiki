<?php

declare(strict_types=1);

/*
 * The operator's first screen (spec OPS-1, OPS-2).
 *
 * Every figure has a `definition`, and it is not a tooltip — it is on the card,
 * always. OPS-2 exists because a dashboard figure nobody can define is a figure
 * nobody can trust: an operator reconciles "revenue this week" against their
 * bank once, and if the two disagree with no explanation on the screen they
 * conclude the product is wrong about money.
 *
 * The definitions are written for the operator, not for a developer. "Succeeded
 * non-refund payments minus succeeded refunds" is the docblock's sentence; this
 * one says the same thing in the words somebody would use out loud.
 */

return [
    'excludes_test' => 'Test bookings are not counted in any of these.',

    'sailing' => [
        'label' => 'Sailing today and tomorrow',
        'definition' => ':pax passengers booked. Cancelled trips are not counted.',
    ],

    'at_risk' => [
        'label' => 'At risk',
        'definition' => 'Departures in the next :hours hours that have not reached their minimum.',
    ],

    'guest_details' => [
        'label' => 'Waiting for passenger details',
        'definition' => 'Confirmed bookings whose passenger list is still incomplete.',
    ],

    'quotes' => [
        'label' => 'Quotes awaiting an answer',
        'definition' => 'Sent to the guest and not yet accepted or declined.',
    ],

    'balances' => [
        'label' => 'Owed to you',
        'definition' => 'Across :count booking(s) that are confirmed or have already sailed.',
    ],

    'revenue' => [
        'label' => 'Taken this week',
        'definition' => 'Since Monday :from, in your own time. Payments received minus refunds given.',
    ],
    'first_steps' => [
        'heading' => 'Four steps to your first booking',
        'description' => 'Do them in this order — each one needs the one before it.',
        'vessel' => [
            'title' => 'Add your boat',
            'why' => 'A trip has to sail on something, and its capacity comes from here.',
            'action' => 'Add a boat',
        ],
        'product' => [
            'title' => 'Create a trip',
            'why' => 'What you sell: the route, how long it takes, and what it costs.',
            'action' => 'Create a trip',
        ],
        'published' => [
            'title' => 'Publish it',
            'why' => 'A trip that is still a draft cannot be seen or booked by anybody.',
            'action' => 'Open your trips',
        ],
        'departure' => [
            'title' => 'Put it on the calendar',
            'why' => 'Dates and times people can actually book. A trip with no departures has nothing to sell.',
            'action' => 'Open the calendar',
        ],
    ],
];
