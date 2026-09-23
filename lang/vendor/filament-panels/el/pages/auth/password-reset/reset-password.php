<?php

declare(strict_types=1);

// The page the reset email's link opens (product owner, 2026-09-23). Filament's
// Greek wrote «Επιβεβαίωση Κωδικού» and «Επαναφορά Κωδικού» in title case; the
// heading now says what the page is for, in the email's own words.
return [

    'title' => 'Ορισμός νέου κωδικού',

    'heading' => 'Ορισμός νέου κωδικού',

    'form' => [
        'email' => [
            'label' => 'Email',
        ],
        'password' => [
            'label' => 'Νέος κωδικός',
        ],
        'password_confirmation' => [
            'label' => 'Ο νέος κωδικός ξανά',
        ],
        'actions' => [
            'reset' => [
                'label' => 'Αποθήκευση κωδικού',
            ],
        ],
    ],

    'notifications' => [
        'throttled' => [
            'title' => 'Πάρα πολλές προσπάθειες.',
            'body' => 'Δοκίμασε ξανά σε :seconds δευτερόλεπτα.',
        ],
    ],

];
