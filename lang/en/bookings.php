<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Bookings in the operator panel (spec BKG-30 to BKG-34, I18N-1)
|--------------------------------------------------------------------------
|
| Read by an operator, mostly while somebody is on the phone. That decides the
| wording: every helper text says what the field *does to the booking*, not
| what it is called in the schema — "take this off the computed price" rather
| than "discount_cents".
|
*/

return [
    'quote' => [
        'build' => 'Write a quote',
        'built' => 'Quote created. Fill in the lines and send it.',
        'open' => 'Open the quote',
    ],

    'nav' => 'Bookings',

    'model' => [
        'singular' => 'Booking',
        'plural' => 'Bookings',
    ],

    'table' => [
        'reference' => 'Reference',
        'date' => 'Date',
        'guest' => 'Guest',
        'product' => 'Trip',
        'pax' => 'People',
        'status' => 'Status',
        'source' => 'Came from',
        'total' => 'Total',
    ],

    'payment' => [
        'action' => 'Record a payment',
        'amount' => 'How much arrived',
        'owed' => 'Still owed: :amount',
        'how' => 'How it arrived',
        'reference' => 'Reference',
        'reference_help' => 'A receipt number or a bank reference, for your own records.',
        'recorded' => 'The payment is recorded.',
        'too_much' => 'That is more than is owed on this booking (:balance).',
        'not_positive' => 'Enter an amount greater than zero.',
        'wrong_gateway' => 'Only cash and bank transfers are recorded by hand. A card payment arrives by itself.',
        'not_live' => 'This booking is cancelled, so no payment can be recorded against it.',
    ],

    'view' => [
        'trip' => 'The trip',
        'guest' => 'The guest',
        'money' => 'Money',
        'time' => 'Departure time',
        'email' => 'Email',
        'phone' => 'Phone',
        'special_requests' => 'Special requests',
        'paid' => 'Paid',
        'balance' => 'Still owed',
    ],

    'actions' => [
        // Not "New booking": every other booking arrives through the widget,
        // the API or an import, and this button is the one a person presses
        // while somebody is talking to them.
        'create_manual' => 'Take a booking by phone',
    ],

    'form' => [
        'trip' => [
            'heading' => 'Which trip',
            'product' => 'Trip',
            'departure' => 'Departure',
            // BKG-32's "may exceed", said plainly. The operator needs to know
            // this list is wider than the website's, and why.
            'departure_help' => 'Every departure of this trip, including ones the website no longer offers because they are too soon or too far ahead.',
            'pax' => 'Who is coming',
            'band' => 'Ticket type',
            'qty' => 'How many',
        ],

        'guest' => [
            'heading' => 'Who is booking',
            'name' => 'Name',
            'email' => 'Email',
            'phone' => 'Phone',
            'special_requests' => 'Special requests',
        ],

        'price' => [
            'heading' => 'Price',
            'help' => 'The price is worked out the same way the website works it out. Anything you change here is recorded beside it, with your reason.',
            'discount' => 'Take off',
            'discount_help' => 'In cents. 2000 takes off €20.00.',
            'override' => 'Or set the total to',
            'override_help' => 'In cents, for a price agreed on the phone. Leave empty to use the computed price.',
            'reason' => 'Why',
        ],

        'settle' => [
            'heading' => 'Payment and capacity',
            'method' => 'Paid by',
            // BKG-33, and the second half is what an operator wants to know:
            // this money never appears in a gateway statement.
            'help' => 'Cash and bank transfer are recorded here and never go through a card provider, so they will not appear in your Viva statement.',
            'override_capacity' => 'Allow more people than this departure has room for',
            'override_capacity_help' => 'Only for a departure you are deliberately overselling. Your reason is recorded in the activity log. The vessel’s licensed limit still applies and cannot be exceeded.',
            'capacity_reason' => 'Why',
        ],
    ],

];
