<?php

declare(strict_types=1);

return [
    'errors' => [
        'missing_key' => 'No API key was provided. Send it as `Authorization: Bearer <key>` or in the `X-Kaiki-Key` header.',
        'malformed_key' => 'That API key is not in a valid format. A key looks like `pk_live_…` or `sk_live_…`.',
        'invalid_key' => 'That API key is not valid.',
        'revoked_key' => 'That API key has been revoked. Create a new one in your Kaiki panel.',
        'expired_key' => 'That API key has expired. Create a new one in your Kaiki panel.',
        'secret_key_from_browser' => 'A secret key was sent from a browser. Secret keys must only be used server to server — use your publishable key (`pk_…`) in anything a visitor can see.',
        'origin_not_allowed' => 'This website is not on the allowed list for that API key. Add its address in your Kaiki panel.',
        'insufficient_scope' => 'That API key does not have permission for this action (:scope).',
    ],

    'key_type' => [
        'publishable' => 'Publishable',
        'secret' => 'Secret',
    ],

    'key_environment' => [
        'live' => 'Live',
        'test' => 'Test',
    ],

    'scope' => [
        'products.read' => 'Read trips',
        'availability.read' => 'Read availability',
        'branding.read' => 'Read branding',
        'bookings.write' => 'Create bookings',
        'quotes.write' => 'Create quotes',
        'webhooks.receive' => 'Receive webhooks',
    ],
];
