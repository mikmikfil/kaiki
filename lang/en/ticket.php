<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The e-ticket PDF (spec BKG-13.1, I18N-1)
|--------------------------------------------------------------------------
|
| Rendered in the **guest's** locale, never the operator's — the same NTF-4
| chain the emails follow, for the same reason: a German family who booked in
| English should not be handed a Greek ticket because the operator's panel is
| in Greek.
|
| Deliberately short. Everything here is read by somebody standing in the sun
| holding a phone, and a sentence they have to squint at is a sentence they
| ask a crew member about instead.
|
*/

return [

    'title' => 'E-ticket',
    'trip' => 'Trip',

    // "Passenger 2 of 4". One ticket page per person (BKG-21, BKG-23 both need
    // per-guest identity), so the count is what stops a family wondering
    // whether they were sent a duplicate.
    'passenger_of' => 'Passenger :n of :total',

    'fields' => [
        'guest' => 'Passenger',
        'date' => 'Date',
        'departs' => 'Departure',
        // BKG-22's window, printed. A guest cannot compute this themselves —
        // `check_in_offset_minutes` is not something they can see.
        'check_in' => 'Be there by',
        'meeting_point' => 'Meeting point',
        'vessel' => 'Vessel',
    ],

    'footer' => [
        'show_on_arrival' => 'Show this ticket — printed or on your phone — when you arrive.',
    ],

];
