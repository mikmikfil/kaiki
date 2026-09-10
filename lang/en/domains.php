<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The custom-domain screen on /app (#109, HOS-3, CNV-11, I18N-1)
|--------------------------------------------------------------------------
|
| The work happens at a registrar we cannot reach, so these strings are mostly
| explanation: what to create, where, and how long it takes.
|
*/

return [

    'nav' => 'Your website',
    'title' => 'Your website',
    'subtitle' => 'Which pages we publish for you, and at what address.',

    'mode' => [
        'heading' => 'What we publish',
        'help' => 'Takes effect at once. Bookings already made are never affected — a guest still reaches their own booking page exactly as before.',
        'label' => 'Pages we publish',
        'saved' => 'Saved.',
        'save' => 'Save',
    ],

    'cname' => [
        'heading' => 'What to create at your registrar',
        'body' => 'Add this record wherever you manage DNS for your domain. It points a subdomain of yours at :target, and we take care of the certificate.',
        'type' => 'Type',
        'name' => 'Name',
        'value' => 'Points to',
    ],

    'form' => [
        'hostname' => [
            'label' => 'Your address',
            'help' => 'The full address guests will use, for example book.example.gr. A subdomain, not your main website.',
        ],
    ],

    'actions' => [
        'add' => 'Add domain',
        'verify' => 'Check now',
        'remove' => 'Remove',
    ],

    'added' => 'Added. Create the record and we will check every fifteen minutes.',
    'verified' => 'It works — your pages are live on your own address.',
    'not_yet' => 'Not there yet',
    'not_yet_body' => 'The record has not reached us. DNS can take up to a day; we keep checking every fifteen minutes and nothing more is needed from you.',
    'removed' => 'Removed.',
    'empty' => 'No domain yet. Your pages are served from our address, which works perfectly well.',
    'last_checked' => 'checked :when',

    'errors' => [
        'invalid' => 'That does not look like a web address. Something like book.example.gr.',
        'reserved' => 'That address belongs to the platform.',
        'taken' => 'That address is already in use.',
    ],

];
