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
    'subheading' => 'Things waiting on you. Where there is a decision, you can take it from here.',

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
        'title' => 'Below its minimum · :trip, :time',
        'detail' => ':sold booked, minimum :minimum · :vessel',
    ],

    'no_captain' => [
        'title' => 'No captain · :trip, :time',
        'detail' => ':vessel · the boat has no usual captain either',
        'more' => ':vessel · and :count more departure on the same schedule|:vessel · and :count more departures on the same schedule',
    ],

    'guest_details' => [
        'title' => 'Passenger details missing · :reference',
        'detail' => ':guest, :trip — the manifest has to exist before the boat leaves.',
    ],

    'balance' => [
        'title' => 'Balance overdue · :reference',
        'detail' => ':guest still owes :amount.',
    ],

    // A charter paid for after its boat had gone to somebody else (2026-09-25).
    // Cancelled and refunded in full on its own; the guest will call.
    'charter_lost' => [
        'title' => 'Charter without its boat · :trip, :time',
        'detail' => ':guest (:reference) paid, but the boat had already been booked. The booking was cancelled and refunded in full.',
    ],

    // Money that came in as cash or by transfer and has to go back by hand
    // (2026-09-23). A card refunds itself; these do not.
    'refund' => [
        'title' => 'Refund :amount · :reference',
        'detail' => 'To :guest, :method. The booking was cancelled and this money does not go back on its own.',
        'cash' => 'in cash',
        'bank_transfer' => 'by bank transfer',
        'pos' => 'back on their card, from your POS',
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

    // Every row leads to where it is fixed (2026-09-17).
    'actions' => [
        'captain' => 'Assign a captain',
        'departure' => 'Open departure',
        'details' => 'Open booking',
        'balance' => 'Record payment',
        'quote' => 'Open quote',
        'refund' => 'Open booking',
        'calendar' => 'Fix calendar sync',
        'call' => 'Call',
        'all' => 'All (:count)',
        'fewer' => 'Fewer',
    ],

    // The answered row stays a few seconds with its outcome (2026-09-23);
    // otherwise the next one slides into its place and nothing seems to happen.
    'done' => [
        'cancelled' => 'Cancelled',
        'sailed' => 'Sails anyway',
        'paid' => 'Paid',
        'refunded' => 'Refunded',
    ],

    // The decisions are taken here, confirmed first (2026-09-17).
    'decide' => [
        'sail' => 'Sail anyway',
        'sail_heading' => 'Does this departure sail anyway?',
        'sail_body' => 'It has :sold of the :minimum people it needs. If you press «Yes, it sails», the departure becomes guaranteed, keeps selling, and you will not be asked again.',
        'sail_confirm' => 'Yes, it sails',
        'sailed' => 'Done: the departure sails as planned.',
        'cancel' => 'Cancel and notify guests',
        'cancel_heading' => 'Cancel: :trip',
        'cancel_body' => '{0} There are no bookings on this departure. It is cancelled and stops selling.|{1} :count booking on this departure is cancelled. The guest gets back everything they paid and is emailed. Card payments go back on their own; anything paid in cash or by transfer will appear here for you to hand back.|[2,*] :count bookings on this departure are cancelled. Every guest gets back everything they paid and is emailed. Card payments go back on their own; anything paid in cash or by transfer will appear here for you to hand back.',
        'cancel_confirm' => 'Yes, cancel',
        'cancelled' => '{0} The departure is cancelled.|{1} The departure is cancelled, with :count booking.|[2,*] The departure is cancelled, with :count bookings.',
        'paid' => 'Paid',
        'paid_heading' => 'Payment for booking :reference',
        'paid_confirm' => 'Record',
        'refunded' => 'Refunded',
        'refunded_heading' => 'Did :guest get :amount back?',
        'refunded_body' => 'Press «Yes» only once you have handed the money back :method. The booking will be marked refunded.',
        'refunded_confirm' => 'Yes, refunded',
        'refunded_done' => 'Refund recorded.',
    ],

];
