<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Vouchers (OPS-16, PRC-18…22)
|--------------------------------------------------------------------------
|
| An operator usually looks at this screen while a guest is on the telephone.
| The wording is written for that: short, and the one question that matters —
| "can they use it right now?" — is answered by a filter rather than by reading
| three columns and doing the arithmetic.
*/

return [

    'nav' => 'Vouchers',
    'singular' => 'Voucher',
    'plural' => 'Vouchers',

    'form' => [
        'amount' => [
            'label' => 'Amount',
            'help' => 'The credit you are giving. It cannot be changed afterwards.',
        ],
        'reason' => [
            'label' => 'Reason',
            'help' => 'For your own records. Cancellation reasons are set automatically and are not offered here.',
        ],
        'expires_at' => [
            'label' => 'Expires',
            'help' => 'Leave it empty for a voucher that never expires. It is good until the end of the day you choose.',
        ],
        'notes' => [
            'label' => 'Notes',
            'help' => 'Your own notes. The guest does not see them.',
        ],
    ],

    'table' => [
        'code' => 'Code',
        'copied' => 'Copied',
        'amount' => 'Amount',
        'remaining' => 'Remaining',
        'status' => 'Status',
        'reason' => 'Reason',
        'expires' => 'Expires',
        'no_expiry' => 'No expiry',
        'issued' => 'Issued',
    ],

    'filters' => [
        'spendable' => 'Can be used right now',
    ],

    'empty' => [
        'heading' => 'No vouchers',
        'description' => 'Vouchers are issued automatically when you cancel a trip for weather. You can also write one out by hand.',
    ],

    'actions' => [
        'issue' => [
            'label' => 'New voucher',
            'heading' => 'New voucher',
            'description' => 'For credit you are giving yourself. Cancellations issue their own.',
            'submit' => 'Issue',
            'done' => 'Voucher issued',
            'too_small' => 'The amount has to be at least one cent.',
        ],
        'cancel' => [
            'label' => 'Cancel voucher',
            'heading' => 'Cancel this voucher?',
            'description' => 'The code stops working. If you have already given it to somebody, they will be told it is not valid.',
            'done' => 'The voucher is cancelled.',
        ],
    ],

    'view' => [
        'issued_by' => 'Issued by',
        'issued_by_gone' => 'No longer on the team',
        'issued_for' => 'For booking',
        'issued_for_nobody' => 'No booking',
    ],

    'redemptions' => [
        'title' => 'Where it was used',
        'when' => 'When',
        'booking' => 'Booking',
        'amount' => 'Amount',
        'reversed' => 'Given back',
        'reason' => 'Reason',
        'empty' => 'Not used yet.',
        'empty_description' => 'When the guest spends it on a booking, it appears here.',
    ],

];
