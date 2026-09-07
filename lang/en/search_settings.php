<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The search-filter settings screen on /app (#105, CNV-11, I18N-1)
|--------------------------------------------------------------------------
|
| Written for an operator deciding what to put in front of a guest, not for
| somebody configuring software. Each help line says what the guest sees.
|
*/

return [

    'nav' => 'Search page',
    'title' => 'Your search page',
    'subtitle' => 'Choose what a guest can filter by. Fewer filters usually find more trips.',
    'saved' => 'Search filters saved.',

    'sections' => [
        'filters' => 'Filters',
        'filters_help' => 'Date and party size are always there — without them it is a list rather than a search.',
    ],

    'filters' => [
        'date' => [
            'label' => 'Date',
            'help' => 'Always on.',
        ],
        'party' => [
            'label' => 'How many people',
            'help' => 'Always on. It is also what the prices are worked out for.',
        ],
        'port' => [
            'label' => 'Departure harbour',
            'help' => 'Worth having when you leave from more than one place.',
        ],
        'type' => [
            'label' => 'Kind of trip',
            'help' => 'Day trip, sunset, private charter.',
        ],
        'duration' => [
            'label' => 'Trip length',
            'help' => 'Only useful when your trips differ by a few hours.',
        ],
        'price' => [
            'label' => 'Price ceiling',
            'help' => 'A guest sets the most they want to pay for the whole party. Off by default — it invites people to sort you by price.',
        ],
        'vessel' => [
            'label' => 'Boat',
            'help' => 'For a fleet whose guests ask for a particular boat.',
        ],
    ],

    'actions' => [
        'save' => 'Save',
        'view' => 'View your search page',
    ],

];
