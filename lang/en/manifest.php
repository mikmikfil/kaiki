<?php

declare(strict_types=1);

/*
 * The passenger list (spec OPS-8, OPS-9, OPS-10).
 *
 * `on_board` and `of_capacity` are separate lines rather than one sentence
 * because OPS-9's count is against the **vessel's licence**, and a boat with no
 * recorded maximum still has a head count worth printing.
 */

return [
    'title' => 'Passenger list',

    'header' => [
        'trip' => 'Trip',
        'vessel' => 'Boat',
        'date' => 'Date',
        'time' => 'Departs',
        'port' => 'Boarding port',
        'landing_port' => 'Landing port',
        'licence' => 'Licence',
        'captain' => 'Captain',
        'crew' => 'Crew',
    ],

    'on_board' => 'People on board:',
    'of_capacity' => 'of :capacity licensed',
    // Counted per person, including anybody who does not take a seat.
    'missing' => ':count passenger marked * has incomplete details.|:count passengers marked * have incomplete details.',
    'sign' => [
        'captain' => 'The captain',
        'name' => 'Full name',
        'signature' => 'Signature',
        'at' => 'Date and time',
    ],

    'yes' => 'Yes',
    'blank' => '—',
    'missing_column' => 'Details missing',
    'purged' => 'Removed',

    'action' => [
        'label' => 'Passenger list',
        'columns' => 'Columns',
        'layout' => 'Layout',
        'layout_standard' => 'Standard',
        'layout_harbour' => 'For the harbour (larger type)',
        'format' => 'Format',
        'submit' => 'Download',
        'sensitive' => 'Including document numbers is recorded against your account, with the date and time. They appear nowhere else in the system.',
    ],
];
