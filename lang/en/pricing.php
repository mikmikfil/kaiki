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
    'season' => [
        'nav' => 'Seasons',

        'model' => [
            'singular' => 'Season',
            'plural' => 'Seasons',
        ],

        'form' => [
            'name' => [
                'label' => 'Name',
                'help' => 'How you recognise it — for example “High season”.',
            ],
            'code' => [
                'label' => 'Code',
                'help' => 'An optional shorthand, for example HIGH26.',
            ],
            'priority' => [
                'label' => 'Priority',
                'help' => 'When two seasons cover the same date, the higher priority wins. That is how “August bank holiday” beats “Summer”.',
            ],
            'is_active' => [
                'label' => 'Active',
                'help' => 'An inactive season affects no prices.',
            ],
            'ranges' => [
                'label' => 'Dates',
                'help' => 'One or more date ranges. Both ends count: 1 June to 15 September includes both days.',
                'add' => 'Add a range',
                'starts_on' => 'From',
                'ends_on' => 'Until',
            ],
        ],

        'table' => [
            'name' => 'Name',
            'code' => 'Code',
            'priority' => 'Priority',
            'ranges' => 'Dates',
            'is_active' => 'Active',
        ],

        'validation' => [
            'self_overlap' => 'The ranges :first and :second in this season overlap. Merge them into one.',
            'priority_tie' => '“:other” also covers :date at the same priority (:priority). Give one of the two a different priority, otherwise nothing decides which price applies.',
            'ends_before_starts' => 'The end date cannot be before the start.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate plans (CAT-10, PRC-23, AVL-19, AVL-20)
    |--------------------------------------------------------------------------
    |
    | A rate plan is the operator's own tool: a guest sees a price, never a
    | plan. That is why `name` here is a plain text field rather than a
    | translatable one — it never reaches a public page.
    |
    */

    'rate_plan' => [
        'nav' => 'Rate plans',

        'model' => [
            'singular' => 'Rate plan',
            'plural' => 'Rate plans',
        ],

        'sections' => [
            'identity' => 'Where it applies',
            'pricing' => 'Prices',
            'deposit' => 'Deposit',
            'window' => 'When you take bookings',
        ],

        'form' => [
            'product' => [
                'label' => 'Trip',
                'help' => 'A rate plan belongs to one trip.',
            ],
            'season' => [
                'label' => 'Season',
                'help' => 'Leave empty for the default price — the one used when a date falls in no season. One default per trip.',
                'default' => 'Default price (no season)',
            ],
            'name' => [
                'label' => 'Name',
                'help' => 'Your own label, e.g. "Early bird". Guests never see it.',
            ],
            'vessel_price_cents' => [
                'label' => 'Whole boat price',
                'help' => 'Only for trips chartered as a whole boat.',
            ],
            'extra_hour_price_cents' => [
                'label' => 'Extra hour',
                'help' => 'Optional. Whole boat only.',
            ],
            'prices' => [
                'label' => 'Price per age band',
                'help' => 'Per person. Bands with their own price need an amount; bands priced as a share of the base may be left empty.',
                'band' => 'Band',
                'price' => 'Price',
                'empty' => 'This trip has no age bands yet.',
            ],
            'deposit_type' => [
                'label' => 'Deposit',
                'help' => 'What the guest pays at the moment of booking.',
            ],
            'deposit_percent' => [
                'label' => 'Deposit percentage',
                'help' => 'Between 1 and 100.',
            ],
            'deposit_fixed_cents' => [
                'label' => 'Deposit amount',
                'help' => 'A flat amount, whatever the total.',
            ],
            'min_lead_time_hours' => [
                'label' => 'Minimum notice',
                'help' => 'How many hours before departure bookings close. 0 means up to the last moment.',
                'suffix' => 'hours',
            ],
            'max_advance_days' => [
                'label' => 'How far ahead',
                'help' => 'Maximum days before departure. Empty means no limit.',
                'suffix' => 'days',
            ],
            'min_pax_override' => [
                'label' => 'Minimum passengers',
                'help' => "Replaces the trip's minimum for this season only. Empty means the trip's minimum applies.",
            ],
            'is_active' => [
                'label' => 'Active',
                'help' => 'An inactive plan never produces a price.',
            ],
        ],

        'table' => [
            'product' => 'Trip',
            'season' => 'Season',
            'name' => 'Name',
            'price' => 'Price',
            'deposit' => 'Deposit',
            'is_active' => 'Active',
            'default' => 'Default',
        ],

        'validation' => [
            'duplicate_default' => 'This trip already has a default price. Choose a season, or edit the existing one.',
            'deposit_type' => 'Choose how the deposit is taken.',
            'deposit_percent' => 'The deposit percentage must be between 1 and 100.',
            'deposit_fixed' => 'The deposit amount must be greater than zero.',
            'deposit_none_has_value' => 'You chose payment in full but filled in a deposit. Clear the amount or change the deposit type.',
            'vessel_price_required' => 'This trip is chartered as a whole boat, so it needs a whole boat price.',
            'no_band_prices_per_vessel' => 'This trip is chartered as a whole boat: it has one price, not per-person prices.',
            'no_vessel_price_per_seat' => 'This trip is sold per seat, so it has no whole boat price.',
            'missing_band_prices' => 'A price is missing for: :bands.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trips with no price (PRC-5)
    |--------------------------------------------------------------------------
    |
    | The guest sees nothing — no error, no zero. That is right for them and
    | useless for the operator, who would otherwise find out from a phone call.
    |
    */

    'unsellable' => [
        'heading' => 'Trips that cannot be sold',
        'description' => 'These are published but have no rate plan, so guests never see them. Create a rate plan for each one.',
        'empty' => 'Every published trip has a price.',
    ],

    'quote' => [
        'extra_hours' => 'Extra hours',
        'on_request' => 'On request',

        'validation' => [
            'not_sellable' => 'There is no rate plan for :date, so no price can be worked out. Create a rate plan for the trip, or a season that covers the date.',
        ],
    ],

];
