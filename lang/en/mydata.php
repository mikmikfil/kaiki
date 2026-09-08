<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| myDATA (MYD-1…MYD-16)
|--------------------------------------------------------------------------
|
| `LangKeyParityTest` asserts this file and `lang/el/mydata.php` hold exactly
| the same keys, in both directions.
|
| `errors.unknown` passes AADE's own message through verbatim. See
| `docs/compliance/mydata-errors.md` for why the mapped table is short.
|
*/

return [

    'nav' => 'Invoices',

    'model' => [
        'singular' => 'Invoice',
        'plural' => 'Invoices',
    ],

    'table' => [
        'reference' => 'Document',
        'type' => 'Type',
        'booking' => 'Booking',
        'total' => 'Total',
        'status' => 'Status',
        'issued_at' => 'Issued',
        'mark' => 'MARK',
        'no_number' => 'No number yet',
        'environment' => 'Environment',
    ],

    'environment' => [
        'live' => 'Live',
        'dev' => 'Test',
        'warning' => 'Test environment — documents are not really registered with AADE.',
    ],

    'empty' => [
        'heading' => 'No invoices',
        'description' => 'Invoices are issued a few minutes after a booking is confirmed, once you have connected myDATA.',
    ],

    'actions' => [
        'retry' => [
            'label' => 'Try again',
            'done' => 'The submission was queued again.',
        ],
    ],

    'errors' => [
        'unknown' => 'AADE refused the document. Their message: “:message”. Show it to your accountant.',
        'unknown_without_message' => 'AADE refused the document without saying why. Try again, and if it persists speak to your accountant.',

        '243' => 'The customer’s VAT number was not found or is not active. A receipt was issued instead of an invoice.',

        'not_configured' => 'myDATA is not connected. The document was not sent anywhere.',
        'timeout' => 'AADE did not answer. We will try again on our own.',
        'http_500' => 'AADE is having a problem right now. We will try again on our own.',
        'http_401' => 'AADE did not accept the credentials. Check them under Integrations.',
        'http_403' => 'AADE does not allow issuing with these credentials. Check them under Integrations.',
    ],

];
