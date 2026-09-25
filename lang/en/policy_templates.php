<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cancellation policy templates — the /admin screen (2026-09-23)
|--------------------------------------------------------------------------
|
| Written for the platform administrator, not the operator. The one thing that
| has to be said outright is that a change here reaches the **next** operator:
| anyone who already chose holds their own copy, and their terms have already
| gone out in guests' confirmation emails.
|
*/

return [

    'nav' => 'Cancellation policies',

    'model' => [
        'singular' => 'Policy template',
        'plural' => 'Policy templates',
    ],

    'sections' => [
        'identity' => 'The option',
        'identity_help' => 'These are what a new operator sees at the «Cancellation policy» step of first-time setup. A change here applies to whoever sets up from now on — anyone who has already chosen keeps their own copy, because their terms have already gone out to guests and are not rewritten backwards.',
        'ladder' => 'What is refunded, and when',
        'ladder_help' => 'The lines an operator reads are built from these numbers rather than typed separately, so the card cannot promise something the policy does not do.',
    ],

    'form' => [
        'code' => [
            'label' => 'Code',
            'help' => 'Stable, letters and dashes. The operator never sees it; it is how the template is recognised after a rename.',
        ],
        'name' => [
            'label' => 'Name',
            'help' => 'The heading on the card, e.g. «Flexible».',
        ],
        'summary' => [
            'label' => 'One-line summary',
            'help' => 'Under the heading, on the card. Optional.',
        ],
        'is_active' => [
            'label' => 'Offered',
            'help' => 'Turn this off to take it out of the list. Operators who already took it are unaffected.',
        ],
        'free_cancellation_hours' => [
            'label' => 'Free cancellation up to',
            'help' => 'Hours before departure, refunded in full. Leave empty when the policy has no such window and only the ladder below decides.',
            'suffix' => 'hours before',
        ],
        'tiers' => [
            'label' => 'Ladder',
            'help' => 'One row per threshold. Leave it empty for a policy that is only a free window.',
            'add' => 'Another threshold',
            'days_before' => 'Days before',
            'refund_percent' => 'Refund',
            'item' => ':days days before — :percent%',
        ],
    ],

    'table' => [
        'name' => 'Name',
        'summary' => 'Summary',
        'ladder' => 'Ladder',
        'is_active' => 'Offered',
    ],
];
