<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Availability — schedule rules (CAT-14, AVL-52, AVL-55, I18N-1)
|--------------------------------------------------------------------------
|
| An operator does not think in "weekday masks". They think "Tuesdays and
| Thursdays, at nine, all summer" — and that is what this screen should read
| like.
|
*/

return [

    'schedule_rule' => [
        'nav' => 'Schedules',

        'model' => [
            'singular' => 'Schedule',
            'plural' => 'Schedules',
        ],

        'sections' => [
            'what' => 'Which trip',
            'when' => 'When it leaves',
            'window' => 'From when to when',
            'capacity' => 'Seats',
            'preview' => 'The next departures',
        ],

        'form' => [
            'product' => [
                'label' => 'Trip',
                'help' => 'Only trips sold per seat have a schedule.',
            ],
            'vessel' => [
                'label' => 'Vessel',
                'help' => "Leave empty for the trip's own boat. Set it only if this schedule sails on a different one.",
            ],
            'weekday_mask' => [
                'label' => 'Days',
                'help' => 'Choose at least one. To pause the schedule, use the "Active" switch instead.',
            ],
            'start_time' => [
                'label' => 'Departure time',
                'help' => 'Local time.',
            ],
            'valid_from' => ['label' => 'Valid from'],
            'valid_until' => [
                'label' => 'Valid until',
                'help' => 'Empty means open-ended. Departures are still only created as far ahead as the horizon below.',
            ],
            'capacity_override' => [
                'label' => 'Seats',
                'help' => "Empty means whatever the trip says. Never above the vessel's certificate.",
            ],
            'generate_days_ahead' => [
                'label' => 'Horizon',
                'help' => 'How many days ahead departures are created.',
                'suffix' => 'days',
            ],
            'is_active' => [
                'label' => 'Active',
                'help' => 'An inactive schedule creates no new departures. The ones already created stay.',
            ],
        ],

        'days' => [
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            7 => 'Sunday',
        ],

        'table' => [
            'product' => 'Trip',
            'days' => 'Days',
            'start_time' => 'Time',
            'window' => 'Period',
            'capacity' => 'Seats',
            'is_active' => 'Active',
            'daily' => 'Daily',
            'open_ended' => 'open-ended',
        ],

        'preview' => [
            'help' => 'The next ten days this schedule would create, in local time.',
            'none' => 'These settings create no departures at all.',
            'unsaved' => 'Choose days and dates to see the next departures.',
        ],

        'validation' => [
            'not_per_seat' => 'Schedules only apply to trips sold per seat. This trip is ":mode" and is booked as a whole boat, so it has no scheduled departures.',
            'empty_mask' => 'Choose at least one day. To stop the schedule, switch off "Active" instead.',
            'inverted_window' => 'The end date cannot be before the start.',
            'unknown_vessel' => 'That vessel was not found.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Schedule reconciliation (ADR-0009, ADR-0016)
    |--------------------------------------------------------------------------
    |
    | Generation only adds. It never cancels, and never lowers the seats on a
    | departure that has bookings. So the operator decides, and this is where
    | they see what is outstanding.
    |
    */

    'reconciliation' => [
        'nav' => 'Needs attention',
        'title' => 'Schedules needing attention',
        'intro' => 'These departures no longer agree with their schedule. Nothing is changed automatically — the decision is yours.',
        'empty' => 'Every schedule agrees with its departures.',

        'table' => [
            'product' => 'Trip',
            'local_date' => 'Date',
            'kind' => 'What happened',
            'detail' => 'Detail',
        ],

        'kinds' => [
            'dst_skipped' => 'That time does not exist',
            'orphaned' => 'No longer scheduled',
            'capacity_drift' => 'Different seat count',
        ],

        'explanations' => [
            'dst_skipped' => 'The clocks change that day and this time does not exist. No departure was created. Add one manually at a real time if you want it.',
            'orphaned' => 'The schedule changed and no longer covers this day. The departure is still standing and still sellable — cancel it if you do not want it.',
            'capacity_drift' => 'The schedule seat count changed, but this departure already has bookings and was left alone.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Departures (AVL-11, AVL-12, AVL-52)
    |--------------------------------------------------------------------------
    |
    | Two empty departures may overlap on one boat — an operator schedules two
    | trips at the same hour and lets the bookings decide. So we warn; we do not
    | block.
    |
    */

    'departure' => [
        'nav' => 'Departures',

        'model' => [
            'singular' => 'Departure',
            'plural' => 'Departures',
        ],

        'sections' => [
            'what' => 'Which trip, and when',
            'seats' => 'Seats',
            'notes' => 'Notes',
        ],

        'form' => [
            'product' => [
                'label' => 'Trip',
                'help' => 'Only trips sold per seat have departures.',
            ],
            'local_date' => ['label' => 'Date'],
            'local_time' => [
                'label' => 'Departure time',
                'help' => 'Local time.',
            ],
            'capacity' => [
                'label' => 'Seats',
                'help' => "Empty means whatever the trip says. Never above the vessel's certificate.",
            ],
            'notes' => [
                'label' => 'Notes',
                'help' => 'For you only. Guests never see these.',
            ],
            'confirm_conflict' => [
                'label' => 'I know — create it anyway',
                'help' => 'Another departure uses the same boat around that time. That is allowed — as soon as the first seat sells on either, the other stops being sellable.',
            ],
        ],

        'table' => [
            'product' => 'Trip',
            'local_date' => 'Date',
            'local_time' => 'Time',
            'vessel' => 'Vessel',
            'capacity' => 'Seats',
            'seats_sold' => 'Sold',
            'seats_held' => 'In checkout',
            'status' => 'Status',
            'source' => 'Source',
            'manual' => 'Manual',
            'generated' => 'From a schedule',
            'dst_ambiguous' => 'Clocks change',
        ],

        'validation' => [
            'not_per_seat' => 'Departures only apply to trips sold per seat. This one is ":mode" and is booked as a whole boat.',
            'no_vessel' => 'This trip has no vessel, so a departure cannot be created for it.',
            'dst_nonexistent' => 'The time :time does not exist on :date because the clocks change. Choose another time.',
            'conflict' => 'There are :count departures on the same boat around this time (for example at :first). That is allowed, but please confirm.',
            'same_product_overlap' => 'This trip already departs at :date :time on the same boat. The same trip cannot depart twice at once.',
            'sold_time_locked' => 'This departure has :sold bookings, so its date and time cannot move. Cancel it and create a new one if you need to.',
            'capacity_below_sold' => ':sold seats are already sold. The seat count cannot go below that.',
            'product_immutable' => "A departure's trip cannot be changed.",
        ],

        'crew_window' => 'You are seeing the departures of the next few days.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Vessel blocks (AVL-3, AVL-4)
    |--------------------------------------------------------------------------
    */

    'block' => [
        'nav' => 'Vessel blocks',

        'model' => [
            'singular' => 'Block',
            'plural' => 'Blocks',
        ],

        'sections' => [
            'what' => 'Which boat, and why',
            'when' => 'When',
        ],

        'form' => [
            'vessel' => ['label' => 'Vessel'],
            'reason' => [
                'label' => 'Reason',
                'help' => 'Blocks from a private charter or an external calendar create themselves.',
            ],
            'is_all_day' => [
                'label' => 'All day',
                'help' => 'Covers the chosen days in full.',
            ],
            'local_date' => ['label' => 'From date'],
            'local_end_date' => [
                'label' => 'To date',
                'help' => 'Included. Empty means the same day.',
            ],
            'start_time' => ['label' => 'From time'],
            'end_time' => ['label' => 'To time'],
            'title' => ['label' => 'Title'],
            'notes' => ['label' => 'Notes'],
        ],

        'table' => [
            'vessel' => 'Vessel',
            'local_date' => 'From',
            'local_end_date' => 'To',
            'reason' => 'Reason',
            'title' => 'Title',
            'all_day' => 'All day',
        ],

        'validation' => [
            'inverted_window' => 'The end must be after the start.',
            'dates_required' => 'Please give a date.',
            'times_required' => 'Please give a start and end time, or choose "All day".',
            'dst_nonexistent' => 'The clocks change that day and the time you chose does not exist. Please pick another.',
            'sold_departures_blocked' => 'Careful: this block covers :count departures that already have bookings. Nothing was cancelled — the decision is yours.',
        ],
    ],

];
