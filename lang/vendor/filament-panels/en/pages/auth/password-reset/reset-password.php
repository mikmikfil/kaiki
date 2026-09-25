<?php

declare(strict_types=1);

// The page the reset email's link opens (product owner, 2026-09-23). See the Greek file.
return [

    'title' => 'Choose a new password',

    'heading' => 'Choose a new password',

    'form' => [
        'email' => [
            'label' => 'Email',
        ],
        'password' => [
            'label' => 'New password',
        ],
        'password_confirmation' => [
            'label' => 'New password again',
        ],
        'actions' => [
            'reset' => [
                'label' => 'Save password',
            ],
        ],
    ],

];
