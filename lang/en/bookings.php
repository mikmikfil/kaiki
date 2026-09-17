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

    // An operator cancelling a booking (2026-09-17).
    'cancel' => [
        'action' => 'Cancel booking',
        'heading' => 'Cancel booking :reference',
        'paid' => 'They have paid :amount. The cancellation policy gives :percent% today (:refund).',
        'who' => [
            'label' => 'Who asked for the cancellation?',
            'guest' => 'The guest',
            'operator' => 'We did (e.g. a problem with the boat)',
        ],
        'refund' => [
            'label' => 'Refund',
            'policy' => 'As the policy says: :percent% · :amount',
            'full' => 'Everything: 100% · :amount',
            'percent' => 'Another percentage',
            'voucher' => 'A voucher instead of money',
        ],
        'percent' => 'Refund percentage',
        'reason' => 'Reason',
        'reason_help' => 'Kept in the booking history. Required when you do not follow the policy.',
        'effects' => 'The seats are released straight away and the guest is emailed.',
        'confirm' => 'Cancel booking',
        'done' => 'The booking is cancelled. :amount is being refunded.',
        'done_nothing' => 'The booking is cancelled with no refund.',
    ],

    // Taking people off a booking (2026-09-17).
    'remove_guests' => [
        'action' => 'Remove people',
        'heading' => 'Remove people from booking :reference',
        'description' => 'Each person comes off at the price they paid. The seats are released, the guest is emailed and gets new tickets.',
        'band' => ':label (booked: :qty)',
        'preview' => ':count person comes off · :removed. New total :total.|:count people come off · :removed. New total :total.',
        'preview_refund' => ':refund of what they paid comes back.',
        'preview_balance' => 'A balance of :balance remains.',
        'preview_none' => 'Choose how many people come off.',
        'reason' => 'Note (optional)',
        'confirm' => 'Remove',
        'done' => ':count person removed. New total :total.|:count people removed. New total :total.',
        'done_refund' => 'The :refund refund has started.',
        'done_manual' => 'Give :refund back to the guest yourself: the payment was not made by card.',
        'validation' => [
            'per_seat_only' => 'People can only be removed from trips sold per seat.',
            'not_changeable' => 'This booking cannot change any more: it is cancelled or the trip has already happened.',
            'nobody' => 'Choose at least one person.',
            'everyone' => 'To remove everyone, cancel the booking.',
            'below_minimum' => 'This trip needs at least :minimum people per booking.',
            'needs_adult' => 'Children cannot be left without an adult.',
        ],
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
