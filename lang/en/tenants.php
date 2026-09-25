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
        'check_in' => 'Boarding check-in',
        'qr_check_in' => 'QR boarding',
        'extra_person_pricing' => 'Price «up to N people + per extra person»',
        'sms' => 'Text messages (SMS)',
        'setup_guide' => 'Setup guide',
        'getyourguide' => 'Selling through GetYourGuide',
        'hosted_site_mode' => 'Pages we publish',
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
        'check_in_help' => 'Off: there is no boarding screen at all — no scanning, and no passenger list with a tap beside each name. The manifest still prints, the departure still completes, and bookings still finish on the day. For an operator who can see everybody they are expecting standing in front of them.',
        'qr_check_in_help' => 'Off: tickets are issued without a QR and no screen has a scan box. Boarding is done from the passenger list, one tap beside each name — including on the page that works without a signal. Tickets already sent with a QR will no longer scan.',
        'extra_person_pricing_help' => 'On: on whole-boat trips, each price list can say «the price includes up to N people» and «this many euros for each extra person». Off for everyone unless the operator asks for it.',
        'setup_guide_help' => 'On: at first sign-in the owner sees the guide, and the home page shows what is left. Off: no guide and no reminders. Useful when you set the account up yourself. The details can always be changed under Settings.',
        'setup_progress' => 'Guide progress',
        'setup_finished' => 'Finished',
        'setup_step_done' => 'done',
        'setup_step_later' => 'later',
        'setup_step_open' => 'open',
        // Pluralised, because "1 steps" is the kind of detail that makes a
        // screen look uncared for. Zero says something different: nothing is
        // left, so there is no reason to ring them.
        'setup_remaining' => '{0}nothing left|{1}1 step left|[2,*]:count steps left',
        'setup_reset' => 'Restart the guide',
        'setup_reset_body' => 'The guide starts again for this operator. Anything already filled in stays.',
        'setup_reset_done' => 'The guide starts again',
        // A soft delete: the merchant goes dark, the books stay whole.
        'delete' => 'Delete merchant',
        'delete_body' => 'Their pages stop opening, their API keys stop authenticating and their staff cannot sign in. Bookings, invoices and history all stay — and this is undone from here.',
        'delete_confirm_label' => 'Type the merchant’s name to confirm',
        'delete_confirm_help' => 'Exactly as it reads: “:name”.',
        'delete_confirm_mismatch' => 'That name does not match.',
        'delete_done' => 'Merchant deleted',
        'restore' => 'Restore merchant',
        'restore_body' => 'The merchant works again: pages, keys and staff sign-in, as before.',
        'restore_done' => 'Merchant restored',
        'deleted_banner' => 'This merchant was deleted on :date. They sell nothing and nobody can sign in.',
        'sms_help' => 'On: the operator also sends text messages (confirmation, reminders) and sees the Apifon, Yuboto or Twilio connection under Connections. Every message is charged to their own account. Off for everyone unless they ask for it.',
        'getyourguide_help' => 'On: the operator sees the GetYourGuide connection under Connections and enters their own details. Seats GetYourGuide sells come out of the same availability as their own page — there is no second stock, so there is no double booking. Requires their own contract with GetYourGuide; the platform does not hold one. Off for everyone.',

        /*
        | The group headings inside Features (21 September).
        |
        | They split nine unrelated things by whom each one concerns: the crew,
        | the guest, the money, the start. Sales channels moved out to their own
        | tab, because a channel is not a switch.
        */
        'group_boarding' => 'Boarding',
        'group_guest' => 'What the guest sees',
        'group_money' => 'Pricing and messages',
        'group_start' => 'Getting started',

        // The logo and the colours (25 September): set by the platform when
        // it opens the account, and changed later by the operator.
        'branding' => 'Appearance',
        'branding_help' => 'Logo and colours for their pages and emails. The operator can change them too, in Settings.',

        'channels' => 'Sales channels',
        'channels_help' => 'Where this operator sells besides their own pages. Anything sold here comes out of the same availability.',
        'channels_locked' => 'Locked by the platform',
        'channels_locked_help' => 'No OTA channel runs yet, for any operator. It is opened from the command line with "channels:manager open" once GetYourGuide certification has passed — not from here.',

        'channel_state' => 'Connection state',
        'channel_state_off' => 'The switch is off, so nothing is sent or received.',
        'channel_credentials' => 'GetYourGuide details',
        'channel_credentials_given' => 'entered',
        'channel_credentials_missing' => 'missing',
        'channel_inbound' => 'Keys we issued them',
        'channel_inbound_given' => 'generated',
        'channel_inbound_missing' => 'not generated',
        'channel_mapped' => 'Trips mapped',

        'channel_ical' => 'iCal calendars',
        'channel_ical_none' => 'No calendars. Nothing outside Kaiki is blocking boats.',
        'channel_ical_some' => ':count calendars, last read :when.',
        'channel_ical_never' => 'never',
        'hosted_site_mode_help' => 'The booking pages — a page per trip, search and the legal pages — are published in both. The full website adds the home page. The operator sees this but cannot change it. Takes effect at once; bookings already made are never affected.',
        'confirm_heading' => 'Change this operator’s account',
        'confirm_body' => 'The change is recorded in the operator’s own audit trail, with your name and your reason.',
        'reason' => 'Reason',
        'reason_help' => 'Why the change is being made. The operator sees this in their own trail.',
    ],

    'create' => [
        'action' => 'New operator',
        'business' => 'The business',
        'business_help' => 'The rest — legal name, VAT number, tax office — the operator fills in themselves.',
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

    /*
     * «Sign in as» — TEN-7, SAA-2 (2026-09-23).
     *
     * The wording says the two things an administrator has to know before
     * pressing it: that everything they do will look like the operator's own
     * work, and that it is written into the **operator's** log — so they will
     * see it.
     */
    'impersonation' => [
        'action' => [
            'label' => 'Sign in as',
            'heading' => 'Sign in to the operator\'s account',
            'description' => 'You will work as this person for :minutes minutes, with full access. Anything you change is written into the operator\'s log with your name and the reason you give — and the operator can read it.',
            'submit' => 'Sign in',
            'user' => [
                'label' => 'As whom',
                'help' => 'Their role decides what you can do: crew see neither the catalogue nor the prices.',
            ],
            'reason' => [
                'label' => 'Why',
                'help' => 'One sentence, for the log. E.g. "Reported that the sunset trip will not quote a price".',
            ],
        ],

        'banner' => [
            'viewing' => 'You are signed in as :name',
            'as' => '· really :admin',
            'minutes' => '{0} ending now|{1} 1 minute left|[2,*] :count minutes left',
            'stop' => 'Leave',
        ],

        'refused' => [
            'not_permitted' => 'Only a platform administrator can sign in as an operator.',
            'wrong_tenant' => 'That person belongs to a different operator.',
            'no_reason' => 'Give the reason. It is written into the operator\'s log.',
            'yourself' => 'You are already signed in as yourself.',
        ],
    ],

];
