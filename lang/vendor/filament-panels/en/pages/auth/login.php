<?php

declare(strict_types=1);

// The sign-in screen's words (product owner, 2026-09-23, direction Α1). See
// the Greek file for why; only the keys that change.
return [

    'title' => 'Sign in',

    'heading' => 'Sign in',

    'actions' => [
        'request_password_reset' => [
            'label' => 'Forgot password',
        ],
    ],

    'form' => [
        'email' => [
            'label' => 'Email',
        ],
        'password' => [
            'label' => 'Password',
        ],
        'remember' => [
            'label' => 'Remember me',
        ],
        'actions' => [
            'authenticate' => [
                'label' => 'Sign in',
            ],
        ],
    ],

    'messages' => [
        'failed' => 'Wrong email or password.',
    ],

    'notifications' => [
        'throttled' => [
            'title' => 'Too many sign-in attempts.',
            'body' => 'Try again in :seconds seconds.',
        ],
    ],

];
