<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Statistics — the operator's own numbers (I18N-1)
|--------------------------------------------------------------------------
|
| Every block says what it counts. That is not clutter: an operator reconciles
| revenue against their bank once, and if it does not match while the screen has
| not said what it counted, they conclude the product is wrong about money — and
| they do not tell anybody.
|
*/

return [

    'nav' => 'Statistics',
    'title' => 'Statistics',
    'subtitle' => 'How the business did over a period you choose.',

    'range' => [
        'label' => 'Period',
        'from' => 'From',
        'to' => 'To',
        'apply' => 'Show',
        'presets' => [
            'last_30' => 'Last 30 days',
            'this_month' => 'This month',
            'last_month' => 'Last month',
            'this_year' => 'This year',
            'custom' => 'My own dates',
        ],
    ],

    'compare' => [
        'previous' => 'against the period before',
        'last_year' => 'against the same dates last year',
        'no_basis' => 'nothing to compare with',
    ],

    'headline' => [
        'revenue' => 'Revenue',
        'revenue_basis' => 'Money received in the period, less anything refunded.',
        'bookings' => 'Bookings',
        'bookings_basis' => 'Bookings made in the period — not trips that sailed.',
        'pax' => 'Passengers',
        'pax_basis' => 'People on this period\'s bookings.',
        'average' => 'Average booking',
        'average_basis' => 'What the bookings came to, divided by how many there were.',
    ],

    'series' => [
        'heading' => 'How the period went',
        'help' => 'Revenue per day. The quiet days are shown too, because they are part of the answer.',
        'help_week' => 'Revenue per week.',
        'help_month' => 'Revenue per month.',
        'empty' => 'No money came in during this period.',
    ],

    'products' => [
        'heading' => 'By trip',
        'help' => 'Revenue follows the money: it is what was paid in the period for each trip.',
        'label' => 'Trip',
    ],

    'vessels' => [
        'heading' => 'By boat',
        'label' => 'Boat',
    ],

    'occupancy' => [
        'heading' => 'Occupancy',
        'help' => 'Seats sold against seats offered, on the sailings that went. Cancelled ones do not count: a boat that did not go has no empty seats.',
        'rate' => 'Occupancy',
        'sold' => 'Seats sold',
        'capacity' => 'Seats offered',
        'departures' => 'Sailings',
        'none' => 'Nothing sailed in this period.',
        'by_month' => 'By month',
        'by_product' => 'By trip',
        'month' => 'Month',
    ],

    'quiet' => [
        'heading' => 'The emptiest sailings',
        'help' => 'Under half full. An empty seat cannot be sold afterwards — this list is the part of the page you can act on.',
        'date' => 'Date',
        'time' => 'Time',
        'seats' => 'Seats',
        'none' => 'Nothing sailed half empty.',
    ],

    'sources' => [
        'heading' => 'Where they came from',
        'help' => 'Which screen the booking was made through. Recorded on the booking itself — nobody is tracked.',
        'channel' => 'Channel',
        'campaigns' => 'Campaigns',
        'campaigns_help' => 'From utm tags on the address the visitor arrived with.',
        'referrers' => 'Websites',
        'referrers_help' => 'The site the visitor who booked came from.',
        'none' => 'No bookings in this period.',
    ],

    'cancellations' => [
        'heading' => 'Cancellations',
        'help' => 'Among the bookings made in this period. The denominator includes the cancelled ones — otherwise the rate would fall as cancellations rose.',
        'cancelled' => 'Cancelled',
        'no_show' => 'Did not turn up',
        'rate' => 'Cancellation rate',
    ],

    'columns' => [
        'bookings' => 'Bookings',
        'pax' => 'Passengers',
        'revenue' => 'Revenue',
        'share' => 'Share',
    ],

    'test_data' => 'Test bookings are not counted in any of these figures.',

    'empty' => 'Nothing to show for this period.',
];
