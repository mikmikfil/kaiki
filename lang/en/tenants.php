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
        'vertical' => 'Trade',
        'days_left' => 'Access',
        'access_ends' => 'Access ends',
        'sandbox' => 'Sandbox account',
        'qr_check_in' => 'QR boarding',
    ],

    'filters' => [
        'status' => 'Status',
        'plan' => 'Plan',
        'trashed' => 'Deleted merchants',
        'lapsing' => 'Lapsing within 7 days',
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

    'days' => [
        'none' => 'No date set',
        'left' => ':days days',
        'lapsed' => 'Lapsed :days days ago',
    ],

    'edit' => [
        'subscription' => 'Subscription',
        'subscription_help' => 'The plan, the state of the account, and how long they have access.',
        'access_ends_help' => 'Empty means the trial end date applies, if there is one.',
        'account' => 'Account',
        'sandbox_help' => 'Bookings are marked as tests and stay out of every report.',
        'features' => 'Features',
        'features_help' => 'What a small business does not need. Switching something off deletes no data.',
        'qr_check_in_help' => 'Off: tickets are issued without a QR and the crew get no scanning page. Boarding is done from the passenger list, one tap beside each name. Tickets already sent with a QR will no longer scan.',
        'confirm_heading' => 'Change this operator’s account',
        'confirm_body' => 'The change is recorded in the operator’s own audit trail, with your name and your reason.',
        'reason' => 'Reason',
        'reason_help' => 'Why the change is being made. The operator sees this in their own trail.',
    ],

    'create' => [
        'action' => 'New operator',
        'business' => 'The business',
        'business_help' => 'The rest — legal name, VAT number, tax office, logo, colours — the operator fills in themselves.',
        'slug_help' => 'Their public address. Lower case, digits and hyphens. Hard to change later, because it goes into every link.',
        'billing_email' => 'Business email',
        'billing_email_help' => 'Where invoices and subscription notices go.',
        'owner' => 'The owner',
        'owner_help' => 'The first account. You do not choose a password — we send an invitation and they set their own.',
        'owner_name' => 'Full name',
        'owner_email' => 'Sign-in email',
        'account' => 'Account',
        'created' => ':name has been created.',
        'invited' => 'An invitation has been sent to the owner to set their password.',
    ],

];
