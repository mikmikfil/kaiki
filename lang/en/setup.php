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
    'subtitle' => 'Six steps. Skip any of them and come back later — nothing here locks you out of the panel.',

    'skip' => 'Skip for now',
    'unskip' => 'Put it back on the list',
    'done' => 'Done',
    'finish' => 'Finish',

    'finished' => [
        'title' => 'Your account is ready',
        'body' => 'Anything you skipped is waiting in Settings. We will not remind you again.',
    ],

    'steps' => [
        'business' => [
            'label' => 'Your business',
            'description' => 'What you are called on paper',
            'why' => 'These are printed on your receipts and invoices and sent to the tax authority. Without a legal name and a VAT number no document can be issued — everything else here can wait.',
        ],
        'branding' => [
            'label' => 'How you look',
            'description' => 'Logo and colours',
            'action' => 'Open Branding',
        ],
        'vat' => [
            'label' => 'VAT',
            'description' => 'The rate you sell at',
            'why' => 'Choose the rate that applies to most of your trips. It will be pre-filled on every new trip, and you can change it on the ones that need a different one.',
            'caveat' => 'If you do not know which rate applies, skip this step and ask your accountant. We do not suggest one: it is their answer, and a mistake here becomes a mistake on a tax document.',
        ],
        'vessel' => [
            'label' => 'Your first boat',
            'description' => 'What your guests board',
            'action' => 'Add a boat',
        ],
        'product' => [
            'label' => 'Your first trip',
            'description' => 'What you sell on it',
            'action' => 'Add a trip',
        ],
        'ready' => [
            'label' => 'Ready',
            'description' => 'Where your pages are',
            'body' => 'Your website is already live, and it updates itself every time you change something here. Its address, and the embed code that puts booking inside a site of your own, are under Settings → Domains and API keys.',
            'outstanding' => 'Still open: :steps. You will find them under Settings whenever you want them.',
        ],
    ],

    'fields' => [
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

    'widget' => [
        'heading' => 'Setup guide',
        'description' => 'Finish setting your account up.',
        'progress' => ':done of :total',
        'continue' => 'Continue',
        'skipped' => 'Skipped',
    ],
];
