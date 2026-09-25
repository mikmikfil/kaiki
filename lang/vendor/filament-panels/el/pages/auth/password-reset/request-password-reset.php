<?php

declare(strict_types=1);

// «Ξέχασα τον κωδικό» (product owner, 2026-09-23, direction Α1). Filament's
// Greek had «Ξεχάσατε τον κωδικό σας?» with a Latin question mark, the back
// link in English («back to login»), and no Greek for the line under the
// «sent» notice, so that one fell back to English too.
return [

    'title' => 'Νέος κωδικός',

    'heading' => 'Νέος κωδικός',

    'actions' => [
        'login' => [
            'label' => 'Σύνδεση',
        ],
    ],

    'form' => [
        'email' => [
            'label' => 'Email',
        ],
        'actions' => [
            'request' => [
                'label' => 'Στείλε μου σύνδεσμο',
            ],
        ],
    ],

    'notifications' => [
        'sent' => [
            'body' => 'Αν δεν υπάρχει λογαριασμός με αυτό το email, δεν θα έρθει μήνυμα.',
        ],
        'throttled' => [
            'title' => 'Πάρα πολλά αιτήματα.',
            'body' => 'Δοκίμασε ξανά σε :seconds δευτερόλεπτα.',
        ],
    ],

];
