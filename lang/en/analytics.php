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
        // What the chart's top gridline is worth, printed beside the
        // dates. A chart drawn to its own peak says nothing about size
        // until one figure on it is named.
        'peak' => 'peak :amount',
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

    'discount_codes' => [
        'heading' => 'Discount codes',
        'help' => 'Bookings in the period that used a discount code, by code.',
        'none' => 'No bookings used a discount code in this period.',
        'discount' => 'Discount given',
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

    'funnel' => [
        'heading' => 'From a visit to a booking',
        'help' => 'How many reached each step. We count it ourselves, with no cookie and no third party: nobody is followed from one step to the next, so these are counts and the ratio between two consecutive steps — not a per-person conversion rate.',
        'step' => 'Step',
        'count' => 'How many',
        'ratio' => 'Against the step before',
        'empty' => 'Nothing has been counted for this period yet. Visits are counted from the moment Kaiki was updated.',
        'metrics' => [
            'page_view' => 'Visits to your pages',
            'widget_ready' => 'Booking box opened',
            'product_viewed' => 'Looked at a trip',
            'availability_loaded' => 'Saw availability',
            'booking_started' => 'Started a booking',
            'checkout_started' => 'Reached the payment',
            'booking_confirmed' => 'Paid',
            'enquiry_submitted' => 'Sent a question',
            'widget_error' => 'Errors in the booking box',
        ],
    ],

    'test_data' => 'Test bookings are not counted in any of these figures.',

    'empty' => 'Nothing to show for this period.',
];
