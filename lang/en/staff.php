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
        // «About us» (2026-09-24).
        'public' => ['label' => 'On your site'],
        'photo' => [
            'label' => 'Photo',
            'help' => 'Shown on the «About us» page. Without one, their initials are shown.',
        ],
        'bio' => [
            'el' => 'A few words (Greek)',
            'en' => 'A few words (English)',
            'help' => 'One or two lines, e.g. «Knows every cave on Aegina».',
        ],
        'specialty' => ['label' => 'Specialty', 'help' => 'What they do on the boat. A departure’s «Captain» list offers the captains.'],
        'phone' => ['label' => 'Mobile', 'help' => 'Optional. For you to call them.'],
        'name' => ['label' => 'Full name'],
        'salutation' => [
            'label' => 'What the platform calls them',
            'help' => 'Optional, e.g. «Maria» or «Captain George». Left empty, only the first name is used.',
        ],
        'email' => [
            'label' => 'Email',
            'help' => 'Optional. With an email they get an invitation to sign in and notices about their schedules. Without one they appear on the lists but do not sign in.',
        ],
        'locale' => [
            'label' => 'Language',
            'help' => 'Which language they see the panel and receive messages in.',
        ],
        'role' => [
            'label' => 'Role',
            'help' => 'One role per person. An owner can do everything a manager can, and a manager everything the crew can.',
        ],
    ],

    'table' => [
        'offline' => 'No sign-in',
        'specialty' => 'Specialty',
        'name' => 'Name',
        'email' => 'Email',
        'roles' => 'Role',
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
            'done_offline' => ':name was added to the team, without sign-in.',
            'done_offline_body' => 'They appear in the captain and crew lists. Add an email later and they get an invitation.',
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
            'heading' => 'Edit team member',
            'submit' => 'Save',
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
        'subject' => ':operator: welcome to the team',
        'heading' => 'Welcome to the team',
        'lead' => "You now have an account on the company's back office.",
        'lead_owner' => "Your company's account is ready.",
        'facts' => [
            'name' => 'Name',
            'company' => 'Company',
            'email' => 'Sign-in email',
            'added_by' => 'Added by',
        ],
        'platform_team' => 'The :app team',
        'role_label' => 'Your role',
        'can' => 'You can:',
        'can_owner' => 'You can do everything, including:',
        'roles' => [
            'owner' => [
                'can' => [
                    'Your company page, with its trips and prices',
                    'Bookings, payments and refunds',
                    'Your team and their roles',
                ],
            ],
            'manager' => [
                'can' => [
                    'Trips, prices and availability',
                    'Bookings, passengers and payments',
                    'Departures, boarding and selling on the quay',
                ],
                'cannot' => 'Billing, the subscription and staff stay with the owner.',
            ],
            'crew' => [
                'can' => [
                    "Today's departures and their passengers",
                    'Boarding, by scanning the ticket',
                    'Selling tickets on the quay',
                ],
                'cannot' => 'You do not see bookings, prices or settings.',
            ],
        ],
        'action' => 'Set your password and sign in',
        'expiry' => '{1} The link is valid for 1 day.|[2,*] The link is valid for :days days.',
        'questions' => 'Something not right? Reply to this email or write to :email.',
        'link_text' => 'If the button does not open, copy this address:',
    ],

];
