<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| VAT rates — the platform reference table (CAT-11, I18N-1)
|--------------------------------------------------------------------------
|
| This screen lives in /admin only. Rates are set by tax law, not by operators
| — an operator selects a rate per product, they do not author one.
|
| NO percentage appears in this file. `NoHardcodedVatRateTest` scans `lang/`
| too, and a "13%" written here would read as authoritative.
|
*/

return [

    'nav' => 'VAT rates',

    'model' => [
        'singular' => 'VAT rate',
        'plural' => 'VAT rates',
    ],

    'sections' => [
        'identity' => 'The rate',
        'validity' => 'Period in force',
    ],

    'form' => [
        'code' => [
            'label' => 'Code',
            'help' => 'A stable identifier, for example gr_reduced_transport. It stays the same when the rate changes — only the start date differs.',
        ],
        'rate_bp' => [
            'label' => 'Rate',
            'help' => 'In basis points: 1250 means 12.50%. An integer, so nothing rounds on an invoice. Locked once saved — a statutory change is a new row.',
            'suffix' => 'bp',
        ],
        'vat_category' => [
            'label' => 'myDATA category',
            'help' => 'The AADE vatCategory id. It lives beside the rate so the mapping never has to happen in code.',
        ],
        'description' => [
            'label' => 'Description',
            'help' => 'Shown in the product form and on the invoice, so a customer reads it too.',
        ],
        'valid_from' => [
            'label' => 'In force from',
            'help' => 'Locked once saved. To change a rate, create a new row with the same code.',
        ],
        'valid_to' => [
            'label' => 'In force until',
            'help' => 'Leave empty while the rate is current.',
        ],
        'is_selectable' => [
            'label' => 'Available to select',
            'help' => 'Turn this off to retire the rate from the product form. Products already using it keep working.',
        ],
    ],

    'table' => [
        'code' => 'Code',
        'rate' => 'Rate',
        'vat_category' => 'Category',
        'description' => 'Description',
        'valid_from' => 'From',
        'valid_to' => 'Until',
        'in_force' => 'In force',
        'is_selectable' => 'Selectable',
    ],
];
