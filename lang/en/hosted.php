<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The operator's own pages (spec HOS-1 … HOS-10, I18N-1)
|--------------------------------------------------------------------------
|
| Read by a visitor deciding whether to book a boat trip, often on a phone,
| often not in their first language. Short sentences, no jargon, and nothing
| that reads like software.
|
*/

return [

    'nav' => [
        'language' => 'Language',
    ],

    'index' => [
        'meta_description' => 'Boat trips and charters with :operator. Book online.',
        'trips' => 'Our trips',
        'no_trips' => 'There are no trips on sale at the moment.',
        'duration' => ':minutes minutes',
    ],

    // HOS-10. It says who to contact rather than what went wrong — the visitor
    // cannot fix an operator's subscription and does not need to know about it.
    'blocks' => [
        'hero' => [
            'cta' => [
                'trips' => 'See our trips',
                'contact' => 'Get in touch',
            ],
        ],
        // The FAQ block renders `faqs` rows (#103); the heading is the only
        // text it carries, and this is what it says when the operator has not
        // written one. A section of questions with no title reads as a mistake
        // on a scrolling page.
        'faq' => [
            'heading' => 'Frequently asked questions',
        ],
        'contact' => [
            'heading' => 'Get in touch',
            'phone' => 'Phone',
            'email' => 'Email',
            'address' => 'Address',
            'meeting_point' => 'Meeting point',
            'open_in_maps' => 'Open in maps',
        ],
    ],

    'read_only' => 'Online booking is unavailable just now. Please contact us at :email and we will take your booking directly.',

    'footer' => [
        'operator' => 'Operator',
        'contact' => 'Contact',
        'legal' => 'Legal',
        'vat_number' => 'VAT number',
        'tax_office' => 'Tax office',
        'terms' => 'Terms',
        'privacy' => 'Privacy',
        'cancellation' => 'Cancellation policy',
        // Brand decision 6 of 2026-09-04, rendered from a flag.
        'powered_by' => 'Powered by Kaiki',
    ],

    'legal' => [
        'title' => 'Legal information',
        'gemi' => 'Company registry (ΓΕΜΗ)',
        'terms_body' => 'Bookings made through this page are a contract between you and :operator. The trip, the price and the cancellation terms are the ones shown at the moment you book, and they are recorded with your booking.',
        // The policy belongs to the trip, not the operator — a fleet can run
        // several. Saying so is more honest than printing one as if it were the
        // only one.
        'cancellation_body' => 'Each trip carries its own cancellation policy. It is shown in full on the trip page and again before you pay, and the terms recorded with your booking are the ones that apply — not any later change.',
        'privacy_body' => 'We hold the details you give us in order to run your trip: your name, your contact details, and the passenger information the coastguard requires. We do not sell them and we do not use them for marketing. Write to :email to see, correct or delete what we hold.',
    ],

];
