<?php

declare(strict_types=1);

return [
    'admin' => [
        'brand' => 'Kaiki Platform',
    ],

    'view_frontend' => [
        'label' => 'Your page',
        'title' => 'Opens your public page in a new tab',
        'live' => 'Live',
        // No home page (bookings only): the card opens the search page (2026-09-25).
        'trips_label' => 'Your trips',
        'trips_title' => 'Opens the page listing your trips in a new tab',
    ],

    'groups' => [
        'today' => 'Today',
        'sales' => 'Sales',
        'catalogue' => 'Catalogue',
        'fleet' => 'Fleet',
        'settings' => 'Settings',
    ],

    // Sidebar labels shorter than the screen's own title (Menu 1, 2026-09-16).
    'nav' => [
        'home' => 'Home',
        'scan' => 'Scan tickets',
        'ports' => 'Ports',
        'related' => 'Related screens',
    ],

    // «My profile» (2026-09-17).
    'profile' => [
        'salutation' => [
            'label' => 'What the platform calls you',
            'help' => 'Optional. The home page greets you with it, e.g. «Good morning, Maria». Left empty, only your first name is used.',
        ],
    ],

    // The menu as boxes, on a phone (direction A, 2026-09-17).
    'mobile_menu' => [
        'open' => 'Menu',
        'close' => 'Close',
    ],

    'roles' => [
        'heading' => 'Team',
        'assign' => 'Give a role',
        'revoke' => 'Remove role',
    ],

    'errors' => [
        'no_panel_access' => 'This account cannot open that part of Kaiki.',
    ],

    'locale' => [
        'switcher' => 'Language',
    ],

    'number' => [
        'decrease' => 'Decrease',
        'increase' => 'Increase',
    ],

    'more_actions' => 'More',

    'upload_pick' => 'Choose a file',

];
