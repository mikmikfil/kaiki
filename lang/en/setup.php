<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The first-run setup guide (#51, SAA-9, SAA-10)
|--------------------------------------------------------------------------
|
| Written for somebody who has just opened an account and does not know where
| to start. Each step says *why* it is needed rather than only what it wants: a
| guide that asks for a VAT number without saying what it is used for is a form
| with arrows on it.
|
| Nowhere does this say which VAT rate applies. That is an accountant's answer
| (CAT-11b), and a wrong hint here becomes a wrong number on a tax document.
|
*/

return [
    'nav' => 'Setup guide',
    'title' => 'Let us set your account up',
    'subtitle' => 'About ten minutes. Anything you leave for later waits for you on the home page.',

    'steps_label' => 'Steps',
    'step_of' => 'Step :n of :total',
    'continue' => 'Continue',
    'back' => 'Back',
    'later' => 'Later',
    'later_tag' => 'later',
    'come_back' => 'When you are done there, open «Setup guide» from the menu again and you will carry on from here.',

    'skip' => 'Skip for now',
    'unskip' => 'Put it back on the list',
    'done' => 'Done',
    'finish' => 'Finish',

    'finished' => [
        'title' => 'Your account is ready',
        'body' => 'Anything you skipped is waiting in Settings. We will not remind you again.',
    ],

    // The two ways out of the guide (2026-09-22). One leaves it in the menu,
    // the other retires it — both open the panel straight away.
    'exit' => [
        'prompt' => 'In a hurry?',
        'later' => 'I will do this later',
        'dismiss' => 'I do not need this',
        'dismiss_confirm' => 'The guide leaves the menu. You will find it again under Settings whenever you want it.',
    ],

    'deferred' => [
        'title' => 'Carry on',
        'body' => 'The guide is waiting first in the menu, right where you left it.',
    ],

    'dismissed' => [
        'title' => 'Done — you will not see it again',
        'body' => 'If you need it, it is under Settings → “First-time setup guide”.',
    ],

    'steps' => [
        'business' => [
            'label' => 'Your business',
            'description' => 'What you are called on paper',
            'question' => 'What is your business called on paper?',
            'why' => 'These are printed on your receipts and invoices and sent to the tax authority. Without a legal name and a VAT number no document can be issued — everything else here can wait.',
        ],
        'branding' => [
            'label' => 'How you look',
            'description' => 'Logo and colours',
            'question' => 'How should your pages look?',
            'why' => 'Your logo and colours go on your booking pages and on the emails guests receive. Optional: the pages work without them.',
            'done' => 'You have already added a logo or colours.',
            'action' => 'Open Branding',
        ],
        'vat' => [
            'label' => 'VAT',
            'description' => 'The rate you sell at',
            'question' => 'Which VAT rate do you sell at?',
            'why' => 'Choose the rate that applies to most of your trips. It will be pre-filled on every new trip, and you can change it on the ones that need a different one.',
            'caveat' => 'If you do not know which rate applies, skip this step and ask your accountant. We do not suggest one: it is their answer, and a mistake here becomes a mistake on a tax document.',
        ],
        'cancellation' => [
            'label' => 'Cancellation policy',
            'description' => 'What you refund',
            'question' => 'What do you refund when a guest cancels?',
            'why' => 'Pick one. You can change it whenever you like, and bookings already made keep the terms they were made under. A trip cannot be published without a cancellation policy.',
        ],
        'port' => [
            'label' => 'Ports',
            'description' => 'Where you sail from',
            'question' => 'Where do your trips leave from?',
            'why' => 'The port or marina where you meet your guests. A trip cannot be published without a meeting point — and your boat wants a home port.',
            'done' => 'You have already added a port.',
        ],
        'vessel' => [
            'label' => 'Your first boat',
            'description' => 'What your guests board',
            'question' => 'What is your first boat?',
            'why' => 'Name, type and how many passengers it carries. Photos and a home port can come later.',
            'done' => 'You have already added a boat.',
            'action' => 'Add a boat',
        ],
        'season' => [
            'label' => 'Periods',
            'description' => 'When your price changes',
            'question' => 'Does your price change through the year?',
            'why' => 'A period is a stretch of the year with its own price — “Summer, 1 June to 15 September”. It is set up once and every trip can use it.',
            'caveat' => 'One price all year? Skip this: every trip already starts with an «All year» price.',
            'done' => 'You have already set up a period.',
            'action' => 'Add a period',
        ],
        'product' => [
            'label' => 'Your first trip',
            'description' => 'What you sell on it',
            'question' => 'What do you sell on the boat?',
            'why' => 'Your trip: name, duration, prices and when it leaves. It stays a draft until you publish it.',
            'done' => 'You have already made a trip.',
            'action' => 'Add a trip',
        ],
        'ready' => [
            'label' => 'Ready',
            'description' => 'Where your pages are',
            'question' => 'You are ready',
            'body' => 'Your website is already live, and it updates itself every time you change something here. Its address, and the embed code that puts booking inside a site of your own, are under Settings → Domains and API keys.',
            'outstanding' => 'Still open: :steps. You will find them under Settings whenever you want them.',
        ],
    ],

    'fields' => [
        'port_name' => [
            'label' => 'Port name',
            'help' => 'As your guests call it — “Chania Old Port”.',
        ],
        'port_address' => [
            'label' => 'Address',
            'help' => 'Optional. It helps anyone arriving by taxi or following a map.',
        ],
        'vessel_name' => [
            'label' => 'Boat name',
            'help' => 'As your guests know it.',
        ],
        'vessel_capacity' => [
            'help' => 'As many as the certificate says. No trip ever goes above this number.',
        ],
        'season_name' => [
            'label' => 'Period name',
            'help' => 'Yours to choose — “Summer”, “Easter”.',
        ],
        'season_starts_on' => [
            'label' => 'From',
        ],
        'season_ends_on' => [
            'label' => 'To',
        ],
        'logo' => [
            'label' => 'Your logo',
            'help' => 'PNG, JPG or SVG. It sits top left on your pages and in your emails. Without one, your name is written instead.',
        ],
        'legal_name' => [
            'label' => 'Legal name',
            'help' => 'Exactly as registered with the tax office — not your trading name, if they differ.',
        ],
        'vat_number' => [
            'label' => 'VAT number',
            'help' => 'Nine digits.',
        ],
        'tax_office' => [
            'label' => 'Tax office',
        ],
        'address_line1' => [
            'label' => 'Address',
        ],
        'city' => [
            'label' => 'City',
        ],
        'postcode' => [
            'label' => 'Postcode',
        ],
        'phone' => [
            'label' => 'Telephone',
        ],
        'default_vat_rate' => [
            'label' => 'Default rate',
            'help' => 'Pre-filled on new trips. Can be changed per trip.',
        ],
    ],

    // The three ready policies on the «Cancellation policy» step (2026-09-17).
    'policy' => [
        'flexible' => [
            'name' => 'Flexible',
            'summary' => 'Full refund up to 24 hours before',
            'ladder' => [
                ['when' => '24 hours or more before', 'refund' => '100%'],
                ['when' => 'Less than 24 hours', 'refund' => '0%'],
            ],
        ],
        'standard' => [
            'name' => 'Standard',
            'summary' => 'Full up to 7 days, half up to 2 days',
            'ladder' => [
                ['when' => '7 days or more before', 'refund' => '100%'],
                ['when' => '2 to 6 days before', 'refund' => '50%'],
                ['when' => 'Less than 2 days', 'refund' => '0%'],
            ],
        ],
        'strict' => [
            'name' => 'Strict',
            'summary' => 'Half up to 14 days, nothing after',
            'ladder' => [
                ['when' => '14 days or more before', 'refund' => '50%'],
                ['when' => 'Less than 14 days', 'refund' => '0%'],
            ],
        ],
        'weather' => 'Cancelled for weather',
        'weather_refund' => '100%',
        'later' => 'The percentages can be changed later, under Cancellation policies.',
        'existing' => 'You already have a cancellation policy: «:name».',
    ],

    'widget' => [
        'heading' => 'Setup guide',
        'description' => 'Finish setting your account up.',
        'progress' => ':done of :total',
        'continue' => 'Continue',
        'skipped' => 'Skipped',
    ],
];
