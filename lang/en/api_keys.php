<?php

declare(strict_types=1);

/*
 * The API keys resource (spec TEN-3, SEC-5, I18N-1).
 *
 * Scope, key-type and environment labels are NOT here — they live in
 * `api.php`, which the public API error messages already use. One vocabulary,
 * one file.
 */

return [
    'nav' => 'API keys',

    'model' => [
        'singular' => 'API key',
        'plural' => 'API keys',
    ],

    'form' => [
        'name' => [
            'label' => 'Name',
            'help' => 'Where this key is used — “Website widget”, “WordPress site”. It is only for you.',
        ],
        'type' => [
            'label' => 'Type',
            'help' => 'Publishable keys are safe to put in a web page. Secret keys must stay on your server and must never appear in anything a visitor can view.',
        ],
        'environment' => [
            'label' => 'Environment',
        ],
        'scopes' => [
            'label' => 'Permissions',
            'help' => 'Give the key only what it needs. A publishable key can never be granted more than reading and creating a booking.',
            'forbidden' => 'A :type key cannot be given the permission “:scope”.',
        ],
        'allowed_origins' => [
            'label' => 'Allowed websites',
            'help' => 'The key will only work when called from these addresses.',
            'placeholder' => 'https://example.gr',
            'empty_warning' => 'Left empty, this key works from any website. Add your own addresses so a copied key cannot be used elsewhere.',
        ],
        'expires_at' => [
            'label' => 'Expires',
            'help' => 'Optional. The key stops working after this moment.',
        ],
    ],

    'table' => [
        'name' => 'Name',
        'type' => 'Type',
        'environment' => 'Environment',
        'prefix' => 'Key',
        'prefix_copied' => 'Copied',
        'last_four' => 'Ends with',
        'last_used_at' => 'Last used',
        'never_used' => 'Never used',
        'stale_warning' => 'Unused for more than :days days',
        'status' => 'Status',
    ],

    'status' => [
        'active' => 'Active',
        'revoked' => 'Revoked',
        'expired' => 'Expired',
    ],

    'actions' => [
        'create' => [
            'label' => 'New API key',
            'heading' => 'Create an API key',
            'description' => 'The key is shown once, immediately after it is created. Creating a key does not affect any existing one.',
            'submit' => 'Create key',
        ],
        'revoke' => [
            'label' => 'Revoke',
            'heading' => 'Revoke “:name”?',
            'description' => 'Anything using this key stops working immediately. This cannot be undone — you would need to create a new key and update wherever it is used.',
            'confirm' => 'Revoke the key',
            'done' => '“:name” has been revoked.',
        ],
    ],

    'reveal' => [
        'heading' => 'Your new API key',
        'intro' => 'This is the key for “:name”. Copy it now and store it wherever it will be used.',
        'warning' => 'This is the only time it will ever be shown.',
        'cannot_show_again' => 'Once you close this window the key cannot be shown again. If you lose it, revoke it and create another.',
        'copy' => 'Copy',
        'copied' => 'Copied',
        'done' => 'I have saved it',
    ],

    'empty' => [
        'heading' => 'No API keys yet',
        'description' => 'Create a key so your website, widget or WordPress site can talk to Kaiki.',
    ],
];
