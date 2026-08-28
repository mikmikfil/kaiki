<?php

declare(strict_types=1);

return [
    'owner' => [
        'label' => 'Owner',
        'description' => 'Full access, including billing, staff and deleting the account.',
    ],
    'manager' => [
        'label' => 'Manager',
        'description' => 'Runs the day to day: trips, pricing, bookings and guests. No billing.',
    ],
    'crew' => [
        'label' => 'Crew',
        'description' => "Sees today's departures and the check-in screen. Nothing else.",
    ],
];
