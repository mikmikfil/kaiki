<?php

declare(strict_types=1);

/*
| The platform merchant list in /admin (SAA-1).
|
| "Merchants", not "tenants": `tenant` is our word for a row in a table, and the
| audience for this screen is the person who owns the platform and thinks of
| them as the businesses paying for it.
*/

return [

    'nav' => 'Merchants',

    'model' => [
        'singular' => 'Merchant',
        'plural' => 'Merchants',
    ],

    'columns' => [
        'name' => 'Name',
        'slug' => 'Address',
        'plan' => 'Plan',
        'status' => 'Status',
        'locale' => 'Language',
        'vat_number' => 'VAT number',
        'joined' => 'Joined',
        'deleted' => 'Deleted',
    ],

    'filters' => [
        'status' => 'Status',
        'plan' => 'Plan',
        'trashed' => 'Deleted merchants',
    ],

    'empty' => [
        'heading' => 'No merchants yet',
        'description' => 'Operators appear here as soon as they are onboarded.',
    ],

    'overview' => [
        'total' => 'Merchants',
        'total_description' => 'Not counting deleted accounts',
        'active' => 'Active',
        'active_description' => 'Paying and in good standing',
        'trialing' => 'On trial',
        'trialing_description' => 'Yet to convert',
        'past_due' => 'Payment overdue',
        'past_due_description' => 'Still writable, in the dunning window',
    ],

];
