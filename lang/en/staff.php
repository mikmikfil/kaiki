<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The team (TEN-8, Capability::ManageStaff)
|--------------------------------------------------------------------------
|
| `LangKeyParityTest` asserts this file and `lang/el/staff.php` hold exactly the
| same keys, in both directions.
|
*/

return [

    'nav' => 'Team',

    'model' => [
        'singular' => 'Person',
        'plural' => 'The team',
    ],

    'form' => [
        'name' => ['label' => 'Full name'],
        'email' => [
            'label' => 'Email',
            'help' => 'Where the invitation goes, and what they sign in with. It cannot be changed later.',
        ],
        'locale' => [
            'label' => 'Language',
            'help' => 'Which language they see the panel and receive messages in.',
        ],
        'roles' => [
            'label' => 'Roles',
            'help' => 'You can give more than one. They add up.',
        ],
    ],

    'table' => [
        'name' => 'Name',
        'email' => 'Email',
        'roles' => 'Roles',
        'status' => 'Status',
        'pending' => 'Has not signed in yet',
        'active' => 'Active',
    ],

    'empty' => [
        'heading' => 'Only you',
        'description' => 'Invite somebody and they will get an email to choose their own password.',
    ],

    'actions' => [
        'invite' => [
            'label' => 'Invite',
            'heading' => 'Invite to the team',
            'description' => 'They will get an email with a link to choose their own password. You do not set one for them.',
            'submit' => 'Send invitation',
            'done' => 'Invitation sent to :email',
            'done_body' => 'The account becomes active once they choose a password.',
        ],
        'resend' => [
            'label' => 'Send again',
            'heading' => 'Send a new invitation?',
            'description' => 'The previous link stops working.',
            'done' => 'A new invitation was sent to :email',
        ],
        'edit' => [
            'done' => 'Changes saved',
            'last_owner' => 'One owner has to remain',
        ],
        'delete' => [
            'description' => 'The account stops working immediately. Their bookings and actions stay as they are.',
            'done' => 'Account removed',
            'last_owner' => 'One owner has to remain',
            'last_owner_body' => 'Give the owner role to somebody else first, then try again.',
        ],
    ],

    'invitation' => [
        'subject' => 'An invitation from :operator',
        'heading' => 'You have access to :operator',
        'body' => ':name, :inviter has given you access to the :operator back office. Choose your password to get started.',
        'action' => 'Choose a password',
        'expiry' => 'The link is valid for a limited time. If it expires, ask for a new invitation.',
    ],

];
