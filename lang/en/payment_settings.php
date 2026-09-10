<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The payments screen on /app (PRC-23, PRC-24, PRC-27, CNV-11, I18N-1)
|--------------------------------------------------------------------------
|
| Written for an operator deciding how much money to take at the moment of
| booking, not for somebody configuring software. A deposit is not one setting
| but a chain: a balance is left, a reminder goes out, a guest comes back to
| pay. The helper text says so.
|
*/

return [

    'nav' => 'Payments',
    'title' => 'Booking payments',
    'subtitle' => 'How much a guest pays when they book, and how much later.',
    'saved' => 'Payment settings saved.',

    'sections' => [
        'vat' => 'VAT',
        'vat_help' => 'The rate every new trip starts on. Can be changed per trip.',
        'deposits' => 'Deposit',
        'deposits_help' => 'Off, a guest pays the whole amount when they book. On, they pay the deposit your rate plan asks for and the balance before departure.',
    ],

    'fields' => [
        'default_vat_rate' => [
            'label' => 'Default rate',
            'help' => 'We do not suggest a rate: it is an accountant\'s answer, and a mistake here becomes a mistake on a tax document.',
        ],
        'deposits_enabled' => [
            'label' => 'I take a deposit',
            'help' => 'How much the deposit is belongs to each rate plan. This is whether it applies at all.',
        ],
        'balance_days' => [
            'label' => 'The balance is due',
            'help' => 'How many days before departure the balance falls due. The guest gets a reminder with a payment link. Empty means 14 days.',
            'suffix' => 'days before departure',
        ],
    ],

    'actions' => [
        'save' => 'Save',
    ],

];
