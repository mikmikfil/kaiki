<?php

declare(strict_types=1);

/*
| Discount codes (2026-09-17).
*/

return [
    'nav' => 'Discount codes',
    'model' => [
        'singular' => 'Discount code',
        'plural' => 'Discount codes',
    ],
    'empty' => 'No discount codes',
    'empty_help' => 'Create a discount code for a campaign and see here how many bookings it brought.',
    'all_trips' => 'All trips',
    'fields' => [
        'name' => 'Internal name',
        'name_help' => 'Only you see this, e.g. “June newsletter”.',
        'code' => 'Code',
        'code_help' => 'What the guest types. Letters, numbers, dashes.',
        'kind' => 'Type of discount',
        'percent' => 'Percentage',
        'amount' => 'Amount',
        'discount' => 'Discount',
        'valid_from' => 'Valid from',
        'valid_until' => 'Valid until',
        'max_uses' => 'Maximum uses',
        'max_uses_help' => 'Leave empty for unlimited.',
        'product' => 'Only for the trip',
        'product_help' => 'Leave empty for every trip.',
        'is_active' => 'Active',
    ],
    'columns' => [
        'uses' => 'Uses',
        'revenue' => 'Revenue brought',
    ],
    'refused' => [
        'unknown' => 'That code does not exist.',
        'inactive' => 'That code is no longer active.',
        'not_yet' => 'That code is not valid yet.',
        'expired' => 'That code has expired.',
        'used_up' => 'That code has been used as many times as allowed.',
        'other_trip' => 'That code is not valid for this trip.',
        'too_late' => 'A code can only be added before payment.',
        'no_longer' => 'Your discount code is no longer valid and was removed. Check the new price before paying.',
    ],
    'checkout' => [
        'label' => 'Discount code',
        'apply' => 'Apply',
        'remove' => 'Remove code',
        'using' => 'Discount code :code',
        'applied' => 'The code was applied.',
        'removed' => 'The code was removed.',
    ],
];
