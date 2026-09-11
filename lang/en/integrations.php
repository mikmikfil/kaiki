<?php

declare(strict_types=1);

/*
 * The integrations screen (spec PAY-4, PAY-11, EXT-2, CNV-11, I18N-1).
 *
 * Provider and environment *labels* are not here — they are enum labels and
 * live in `enums.php` with every other one. This file is the screen: the
 * sentences an operator reads while pasting keys they cannot check by eye.
 *
 * The field names below are the shared vocabulary of seven different vendor
 * dashboards, and the help text says where in each dashboard to find the thing.
 * An operator who cannot find `subscription_key` in the AADE portal will not be
 * helped by a label that says "Subscription key".
 */

return [
    'nav' => 'Integrations',

    'page' => [
        'title' => 'Integrations',
        'subheading' => 'Payment, invoicing, SMS and email accounts. Everything here is yours — guests pay you directly and Kaiki never holds your money.',
    ],

    'section' => [
        'payments' => 'Taking payments',
        'invoicing' => 'Invoicing',
        'messaging' => 'Messages to guests',
    ],

    'status' => [
        'verified' => 'Working — last checked :when',
        'unverified' => 'Not checked yet',
        'failed' => 'Not working',
        'inactive' => 'Switched off',
        'incomplete' => 'Some details are missing',
    ],

    'form' => [
        'provider' => [
            'label' => 'Provider',
        ],
        'environment' => [
            'label' => 'Environment',
            'help' => 'Test details let you take a practice booking without a card being charged. Live details take real money.',
        ],
        'is_default' => [
            'label' => 'Use this one at checkout',
            'help' => 'Only matters if you have set up more than one payment provider. Guests will pay through the one you choose here.',
        ],
        'is_active' => [
            'label' => 'Switched on',
        ],
        'secret_placeholder' => 'Saved — paste a new value to replace it',
    ],

    /*
     * Field names, shared by the form and by IntegrationCredentialIncomplete.
     * One list, so the sentence an operator gets when a save is refused names
     * the field with the same words the form labelled it.
     */
    'fields' => [
        'client_id' => 'Client ID',
        'client_secret' => 'Client secret',
        'secret_key' => 'Secret key',
        'publishable_key' => 'Publishable key',
        'user_id' => 'User ID',
        'subscription_key' => 'Subscription key',
        'token' => 'Token',
        'api_key' => 'API key',
        'account_sid' => 'Account SID',
        'auth_token' => 'Auth token',
        'server_token' => 'Server token',
        'webhook_secret' => 'Webhook signing secret',
        'source_code' => 'Payment source code',
        'branch' => 'Branch number',
        'sender_name' => 'Sender name',
        'from_address' => 'From address',
        'from_name' => 'From name',
        'account_id' => 'Account ID',
    ],

    'refused' => [
        'incomplete' => ':provider needs these details before it can be saved: :fields.',
    ],

    'verify' => [
        'action' => 'Check these details',
        'succeeded' => 'These details work. :provider accepted them.',
        'failed' => 'These details were not accepted.',
        'incomplete' => 'Fill in every field before checking.',
        'unavailable' => 'Checking :provider is not available yet. Your details are saved and will be checked as soon as it is.',
        'unreachable' => 'We could not reach :provider. This is usually temporary — try again in a few minutes.',
        'rejected' => ':provider refused these details. Check you copied them from the right account, and that you have not pasted the test details into the live box.',
    ],

    'actions' => [
        'save' => 'Save',
        'deactivate' => 'Switch off',
        'deactivated' => 'Switched off. Your details are kept, so you can switch it back on without pasting them again.',
        'saved' => 'Saved.',
    ],

    'help' => [
        'never_shown' => 'Once saved, we never show these back to you — only the last four characters, so you can tell which one you pasted.',
        'live_warning' => 'These are live details. A booking made with them charges a real card.',
    ],

    /*
     * The two addresses an operator pastes into their Viva payment source.
     * Viva takes no return address per order, so without these a guest who
     * paid lands wherever the dashboard happened to say.
     */
    'viva_return' => [
        'heading' => 'Viva: where guests come back to after paying',
        'help' => 'In your Viva account, open the payment source you use for Kaiki and paste these two addresses into its Success URL and Failure URL, so guests return to their booking — or to the checkout page to try again if the payment does not go through.',
        'success' => 'Success URL',
        'failure' => 'Failure URL',
        'copy' => 'Copy',
        'copied' => 'Copied',
    ],
];
