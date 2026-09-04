<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pricing — cancellation policies (CAT-13, CXL-3, I18N-1)
|--------------------------------------------------------------------------
|
| A guest reads the policy text before paying, not just the operator. That is
| why the name and the summary are translatable fields rather than internal
| labels.
|
*/

return [

    'cancellation' => [
        'nav' => 'Cancellation policies',

        'model' => [
            'singular' => 'Cancellation policy',
            'plural' => 'Cancellation policies',
        ],

        'sections' => [
            'identity' => 'The policy',
            'ladder' => 'Refund ladder',
            'special' => 'Special cases',
        ],

        'form' => [
            'name' => [
                'label' => 'Name',
                'help' => 'The guest sees this before paying — for example Flexible or Strict.',
            ],
            'summary' => [
                'label' => 'Summary',
                'help' => 'One sentence explaining the policy to the guest. Optional.',
            ],
            'free_cancellation_hours' => [
                'label' => 'Free cancellation up to',
                'help' => 'Hours before departure. While at least this many hours remain the refund is 100% and the ladder is not consulted at all. Leave empty if you do not offer free cancellation.',
                'suffix' => 'hours',
            ],
            'weather_refund_percent' => [
                'label' => 'Weather refund',
                'help' => 'Applies when you cancel the departure for weather, regardless of the ladder.',
            ],
            'force_majeure_voucher_months' => [
                'label' => 'Voucher validity',
                'help' => 'Months a voucher stays valid, when you offer one instead of cash in a force-majeure cancellation.',
                'suffix' => 'months',
            ],
            'no_show_refund_percent' => [
                'label' => 'No-show refund',
                'help' => 'When the guest never turns up.',
            ],
            'is_default' => [
                'label' => 'Default policy',
                'help' => 'Applies to every trip without a policy of its own. One per account — setting this one stops the previous default being the default.',
            ],
            'tiers' => [
                'label' => 'Ladder',
                'help' => 'Each row reads: cancel at least this many days ahead, get this much back. The row with the most days that still applies is the one used. If none applies, the refund is 0%.',
                'add' => 'Add a row',
                'days_before' => 'Days before',
                'refund_percent' => 'Refund',
                'preview' => 'Cancel :days days ahead → :percent% back',
            ],
        ],

        'table' => [
            'name' => 'Name',
            'free_cancellation' => 'Free cancellation',
            'tiers' => 'Ladder rows',
            'is_default' => 'Default',
            'none' => 'None',
        ],

        'validation' => [
            'duplicate_days_before' => 'There is already a row for :days days before. Each threshold can appear only once.',
            'last_default' => 'One policy has to be the default. Make another one the default first.',
            'refund_percent_range' => 'The refund percentage must be between 0 and 100.',
        ],
    ],
];
