<?php

declare(strict_types=1);

/*
| «Ρυθμίσεις» / Settings: the page of cards (product owner, 11 September 2026).
|
| The card titles are not here. Each is the navigation label of the screen it
| opens (`branding.nav`, `staff.nav` …), so a card and its page always agree on
| what the page is called. What lives here is the one line under each title:
| short, and written for somebody who does not yet know what is inside.
*/

return [

    'nav' => 'Settings',
    'title' => 'Settings',
    'subheading' => 'What you set up once and come back to when you need it.',
    'back' => 'Back to settings',

    'sections' => [
        'business' => 'Your business',
        'website' => 'What guests see',
        'records' => 'History and records',
        'advanced' => 'Advanced',
    ],

    'cards' => [
        'branding' => 'Logo, colours and typeface',
        'staff' => 'Who can sign in, and in which role',
        'payments' => 'What a guest pays, and when',
        'integrations' => 'Payment, invoicing and email accounts',
        'home_page' => 'The first page a guest sees',
        'faq' => 'Answers to what you are asked most',
        'search' => 'How guests can filter your trips',
        'domains' => 'Which pages are published, and where',
        'failures' => 'Anything the system could not do',
        'notifications' => 'The emails and texts that were sent',
        'exports' => 'Bookings and passengers as a CSV file',
        'audit' => 'Who on your team did what, and when',
        'calendar_sync' => 'Availability to and from other platforms',
        'api_keys' => 'For your website, the widget and WordPress',
        'webhooks' => 'Booking notices to your own systems',
    ],

];
