<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    // The blue left side of the sign-in screen (2026-09-17).
    'intro' => [
        'line' => 'The booking system for boat trips.',
    ],

    // The password reset email, for both panels (2026-09-17).
    'reset_mail' => [
        'subject' => 'Reset your password · :operator',
        'heading' => 'Reset your password',
        'body' => 'Hello :name, we received a request to change the password of your :operator account. Use the button to choose a new one.',
        'action' => 'Choose a new password',
        'expiry' => 'The link works for :minutes minutes.',
        'ignore' => 'If you did not ask for this, ignore this email. Your password stays the same.',
    ],

];
