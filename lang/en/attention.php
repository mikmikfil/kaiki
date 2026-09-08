<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The dashboard's fleet strip and its decision list (spec OPS-1, OPS-3)
|--------------------------------------------------------------------------
|
| Every line here is written for somebody reading it at seven in the morning
| with a coffee, deciding what today looks like. So each row names the thing,
| the number that makes it a problem, and nothing else — the screen behind it
| is where the detail lives.
*/

return [

    'today' => [
        'heading' => 'Today at sea',
        'open_calendar' => 'Open the calendar',
        'no_vessels' => 'No boats yet.',
        'nothing_out' => 'Nothing out today.',
    ],

    'heading' => 'Needs attention',
    'subheading' => 'Decisions waiting on you. Nothing here can be fixed by trying again.',

    'no_deadline' => 'no deadline',

    'severity' => [
        'critical' => 'Decide',
        'warning' => 'Chase',
        'info' => 'Watch',
    ],

    /*
     * A trip that will not reach its minimum.
     *
     * The row states the gap rather than a recommendation. Running it short and
     * cancelling it both cost money, in different pockets, and which one is
     * right depends on things the product does not know — a regular customer, a
     * boat that has to move anyway, a forecast for next week.
     */
    'under_minimum' => [
        'title' => ':trip · :time',
        'detail' => ':sold booked, minimum :minimum · :vessel',
    ],

    'guest_details' => [
        'title' => 'Passenger details missing · :reference',
        'detail' => ':guest, :trip — the manifest has to exist before the boat leaves.',
    ],

    'balance' => [
        'title' => 'Balance overdue · :reference',
        'detail' => ':guest still owes :amount.',
    ],

    'quote' => [
        'title' => 'Quote about to expire · :reference',
        'detail' => 'Sent and not answered. When it lapses, the charter quietly did not happen.',
    ],

    /*
     * The most dangerous row on the list, and the one with no clock.
     *
     * A calendar that has stopped syncing is a boat that looks free while
     * somebody else has already sold it — so the sentence says that outright
     * rather than reporting a failure count.
     */
    'calendar' => [
        'title' => 'Calendar not syncing · :name',
        'detail' => ':vessel may look free while it is booked elsewhere. Failed :count times in a row.',
    ],

];
