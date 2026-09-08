<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Outbound webhooks (OPS-19, OPS-20)
|--------------------------------------------------------------------------
|
| An operator does not know what an HMAC is and does not need to. What they do
| know is that they gave an address to their developer, so the wording here is
| about that: where we send, what we send, and what happened last time.
|
| The signing secret is shown once. The sentence that says so is the most
| important one in the file.
*/

return [

    'nav' => 'Webhooks',
    'singular' => 'Webhook',
    'plural' => 'Webhooks',

    'form' => [
        'name' => [
            'label' => 'Name',
            'help' => 'For your own use — "Accounts", "Zapier". It is what you see when something goes wrong.',
        ],
        'url' => [
            'label' => 'Address',
            'help' => 'Where we will send. It must start with https://',
            'refused' => 'Only https:// addresses on a public server. Local and internal addresses are not allowed.',
        ],
        'events' => [
            'label' => 'Events',
            'help' => 'We will send only what you tick here.',
        ],
        'is_active' => [
            'label' => 'Active',
            'help' => 'Switch it off for a while without deleting it.',
        ],
    ],

    'events' => [
        'booking.confirmed' => 'A booking has been paid for and confirmed.',
        'booking.cancelled' => 'A booking was cancelled, by the guest or by you.',
        'departure.cancelled' => 'A departure was cancelled — weather, too few people, or your own decision.',
        'guest_details.completed' => 'Every passenger on a booking now has their details filled in. We send the count, never document numbers.',
    ],

    'table' => [
        'name' => 'Name',
        'url' => 'Address',
        'events' => 'Events',
        'is_active' => 'Active',
        'failures' => 'Failures in a row',
        'last_delivery' => 'Last sent',
        'never' => 'Never',
    ],

    'empty' => [
        'heading' => 'No webhooks',
        'description' => 'Add an address if you want your own systems told about bookings automatically.',
    ],

    'actions' => [
        'create' => [
            'label' => 'New webhook',
            'heading' => 'New webhook',
            'description' => 'The signing secret is shown once, immediately afterwards.',
            'submit' => 'Create',
        ],
        'rotate' => [
            'label' => 'Change signing secret',
            'heading' => 'Change the signing secret?',
            'description' => 'The old secret stops working. Whoever receives these webhooks has to be told, or they will start rejecting them.',
        ],
        'reenable' => [
            'label' => 'Switch back on',
            'description' => 'We switched this off after too many failures in a row. If the cause is fixed, we start again from zero.',
            'done' => 'The webhook is on again.',
        ],
    ],

    'reveal' => [
        'heading' => 'Your signing secret',
        'rotated_heading' => 'The new signing secret',
        'warning' => 'Copy it now.',
        'intro' => 'The signing secret for ":name". Your receiver uses it to check that a message really came from us.',
        'copy' => 'Copy',
        'copied' => 'Copied',
        'cannot_show_again' => 'It will not be shown again. If you lose it, make a new one.',
        'done' => 'I have saved it',
    ],

    'deliveries' => [
        'title' => 'What we sent',
        'when' => 'When',
        'event' => 'Event',
        'status' => 'Status',
        'attempts' => 'Attempts',
        'response' => 'Answer',
        'no_answer' => 'No answer',
        'next' => 'Next attempt',
        'duration' => 'Took',
        'resend' => 'Send again',
        'resend_description' => 'We send exactly the same message again. If your receiver already had it, it will ignore the repeat.',
        'resent' => 'Queued to send.',
        'empty' => 'Nothing sent yet.',
    ],

];
