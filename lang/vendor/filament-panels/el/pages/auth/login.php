<?php

declare(strict_types=1);

// The sign-in screen's words (product owner, 2026-09-23, direction Α1).
// Filament's own Greek said «Συνδεθείτε στο λογαριασμό σας» — «στον», and far
// too long for a heading — «Διεύθυνση ηλεκτρονικού ταχυδρομείου» for a field
// everybody calls Email, and «Θυμήσου με». Short and sentence case instead,
// as the mockup has them (docs/mockups/login-mobile-directions.html). Only the
// keys that change; Laravel merges this over the package's file.
return [

    'title' => 'Σύνδεση',

    'heading' => 'Σύνδεση',

    'actions' => [
        'request_password_reset' => [
            'label' => 'Ξέχασα τον κωδικό',
        ],
    ],

    'form' => [
        'email' => [
            'label' => 'Email',
        ],
        'password' => [
            'label' => 'Κωδικός',
        ],
        'remember' => [
            'label' => 'Να με θυμάσαι',
        ],
        'actions' => [
            'authenticate' => [
                'label' => 'Σύνδεση',
            ],
        ],
    ],

    'messages' => [
        'failed' => 'Λάθος email ή κωδικός.',
    ],

    'notifications' => [
        'throttled' => [
            'title' => 'Πάρα πολλές προσπάθειες σύνδεσης.',
            'body' => 'Δοκίμασε ξανά σε :seconds δευτερόλεπτα.',
        ],
    ],

];
