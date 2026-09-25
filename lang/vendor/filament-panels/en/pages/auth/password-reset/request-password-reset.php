<?php

declare(strict_types=1);

// «Forgot password» (product owner, 2026-09-23, direction Α1). See the Greek file.
return [

    'title' => 'New password',

    'heading' => 'New password',

    'actions' => [
        'login' => [
            'label' => 'Sign in',
        ],
    ],

    'form' => [
        'email' => [
            'label' => 'Email',
        ],
        'actions' => [
            'request' => [
                'label' => 'Send me a link',
            ],
        ],
    ],

    'notifications' => [
        'throttled' => [
            'title' => 'Too many requests.',
            'body' => 'Try again in :seconds seconds.',
        ],
    ],

];
