<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Enum labels (CNV-11, I18N-1)
|--------------------------------------------------------------------------
|
| One home for every backed enum's operator-facing label, keyed by the enum's
| snake_cased short name and then by its case value. `HasTranslatedLabel`
| resolves `enums.{enum}.{value}.label` and nothing in an enum class may return
| a literal.
|
| These lines used to live in four single-purpose files — `roles.php`,
| `plans.php`, `tenant_status.php` and `domains.php` — plus the API vocabulary
| in `api.php`. Four enums justified four files; M1 adds a dozen more, and the
| answer to "where is this label" has to stay one file rather than a search.
|
| `api.php` keeps the API *error* envelope, which is prose aimed at an
| integrator rather than a label on a form.
|
*/

return [

    'role' => [
        'owner' => [
            'label' => 'Owner',
            'description' => 'Full access, including billing, staff and deleting the account.',
        ],
        'manager' => [
            'label' => 'Manager',
            'description' => 'Runs the day to day: trips, pricing, bookings and guests. No billing.',
        ],
        'crew' => [
            'label' => 'Crew',
            'description' => "Sees today's departures and the check-in screen. Nothing else.",
        ],
    ],

    'plan' => [
        'trial' => ['label' => 'Trial'],
        'solo' => ['label' => 'Solo'],
        'fleet' => ['label' => 'Fleet'],
        'pro' => ['label' => 'Pro'],
    ],

    'tenant_status' => [
        'trialing' => ['label' => 'Trial'],
        'active' => ['label' => 'Active'],
        'past_due' => ['label' => 'Payment overdue'],
        'read_only' => ['label' => 'Read only'],
        'suspended' => ['label' => 'Suspended'],
    ],

    'domain_status' => [
        'pending' => ['label' => 'Waiting for DNS'],
        'verified' => ['label' => 'Verified'],
        'failed' => ['label' => 'Verification failed'],
        'disabled' => ['label' => 'Disabled'],
    ],

    'api_key_type' => [
        'publishable' => ['label' => 'Publishable'],
        'secret' => ['label' => 'Secret'],
    ],

    'api_key_environment' => [
        'live' => ['label' => 'Live'],
        'test' => ['label' => 'Test'],
    ],

    /*
    | Scope values contain a dot, and Laravel reads a dot in a translation key
    | as a path separator — so these are fetched as an array and indexed, never
    | looked up as `enums.api_scope.products.read`. That bug shipped in #6 and
    | was only found in #10, when the labels first reached a form.
    */
    'api_scope' => [
        'products.read' => ['label' => 'Read trips'],
        'availability.read' => ['label' => 'Read availability'],
        'branding.read' => ['label' => 'Read branding'],
        'bookings.write' => ['label' => 'Create bookings'],
        'quotes.write' => ['label' => 'Create quotes'],
        'webhooks.receive' => ['label' => 'Receive webhooks'],
    ],

    'locale' => [
        'el' => ['label' => 'Ελληνικά', 'short' => 'ΕΛ'],
        'en' => ['label' => 'English', 'short' => 'EN'],
    ],

];
