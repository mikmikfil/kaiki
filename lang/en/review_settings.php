<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The review request screen in /app (2026-09-17, I18N-1)
|--------------------------------------------------------------------------
|
| An email after the trip asking the guest for a Google review. Written for the
| operator: each help line says what the guest will get, and when.
|
*/

return [

    'nav' => 'Google reviews',
    'title' => 'Google reviews',
    'subtitle' => 'A short email after the trip, asking the guest to write a review.',
    'saved' => 'Review settings saved.',

    'sections' => [
        'request' => 'Review request',
        'request_help' => 'Sent once per booking, only to guests who sailed — never to cancelled bookings or no-shows. Never at night.',
    ],

    'fields' => [
        'enabled' => [
            'label' => 'Send a review request',
            'help' => 'While this is off, nothing is sent.',
        ],
        'delay_hours' => [
            'label' => 'When after the trip',
            'help' => 'Hours after the boat is back. Most operators choose 24.',
            'suffix' => 'hours',
        ],
        'google_url' => [
            'label' => 'Google review link',
            'help' => 'From your Google Business Profile: "Ask for reviews", then copy the link.',
        ],
    ],

    'actions' => [
        'save' => 'Save',
    ],

];
