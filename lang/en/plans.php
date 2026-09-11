<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Plan limits (SAA-3, SAA-8)
|--------------------------------------------------------------------------
|
| Every message says what the plan allows and what to do next — never a bare
| "not allowed". What the operator already has is untouched, and each message
| says so, because the first fear on meeting a limit is losing something.
|
*/

return [

    'upgrade' => 'Upgrade plan',
    'pro_only' => 'Available on the Pro plan',

    'vessels' => [
        'count' => ':count vessel|:count vessels',
        'reached' => 'The :plan plan includes up to :limit, and you already have them. To add another, upgrade your plan. The ones you have stay as they are.',
        'usage' => 'You have :count of the :limit on the :plan plan.',
        'reached_title' => 'You have reached your plan’s vessel limit',
    ],

    'domains' => [
        'body' => 'Your own address — book.example.com, say — is part of the Pro plan. Your pages keep working at Kaiki’s address. Any domains you already have keep working.',
    ],

    'webhooks' => [
        'body' => 'Notifications to your own systems are part of the Pro plan. Any webhooks you already have keep sending.',
    ],

];
