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
        'view' => 'See this trip',
        'all_trips' => 'All our trips',
        'search_prompt' => 'When would you like to sail?',
        'from' => 'from',
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
            'write' => 'Write to us',
            'call' => 'Call us',
            'phone' => 'Phone',
            'email' => 'Email',
            'address' => 'Address',
            'meeting_point' => 'Meeting point',
            'open_in_maps' => 'Open in maps',
        ],
    ],

    'product' => [
        'breadcrumb' => 'Breadcrumb',
        'max_pax' => 'Up to :count people',
        'about' => 'About this trip',
        'includes' => 'What is included',
        'excludes' => 'Not included',
        'what_to_bring' => 'What to bring',
        'itinerary' => 'The route',
        'departures' => 'Upcoming departures',
        'sold_out' => 'Sold out',
        'meeting_point' => 'Where we meet',
        'check_in' => 'Please be there :minutes minutes before departure.',
        'vessel' => 'The boat',
        'vessel_capacity' => 'Up to :count people on board.',
        'age_bands' => 'Who pays what',
        'age_range' => 'ages :from to :to',
        'age_from' => 'ages :from and over',
        'no_seat' => 'does not take a seat',
        'cancellation' => 'Cancellation',
        'free_cancellation' => 'Free cancellation up to :hours hours before departure.',
        'tier' => ':days days before departure: :percent% refunded',
        'weather_refund' => 'If we cancel for weather, you are refunded :percent%.',
        'price' => [
            'from' => 'From',
            // Brand decision 4 of 2026-09-04: the sentence, never the rate. The
            // rate is printed on the invoice, which is M6.
            'vat_included' => 'VAT is included in the price',
        ],
        'booking' => [
            'heading' => 'Book this trip',
            // The four lines above the date picker, brand decision 3.
            'capacity' => 'Capacity',
            'and_more' => 'and :count more',
            'trip' => 'Trip',
            'duration' => 'Duration',
            'port' => 'Departs from',
            'vessel' => 'Boat',
            'fallback' => 'Online booking needs JavaScript. Write or call us and we will book it for you.',
            'enquiry_fallback' => 'This trip is arranged for you. Tell us your dates and how many you are, and we will come back with a price.',
            'email_us' => 'Email us',
        ],
    ],

    'search' => [
        'title' => 'Find a trip',
        'standfirst' => 'Pick a date and tell us how many you are. Prices are for your whole party.',
        'meta_description' => 'Search the trips :operator is running, by date, harbour and party size.',
        'nav' => 'Find a trip',
        'any' => 'Any',
        'submit' => 'Search',
        'on_request' => 'Price on request',
        'count' => '{1} One trip found|[2,*] :count trips found',
        'for_party' => '{1} for one person|[2,*] for :count people',
        'fields' => [
            'date' => 'Date',
            'pax' => 'How many of you',
            'port' => 'Leaving from',
            'type' => 'Kind of trip',
            'duration_max' => 'No longer than (minutes)',
            'price_max' => 'Up to (€, whole party)',
            'vessel' => 'Boat',
        ],
        'empty' => [
            'heading' => 'Nothing sails that day for that many',
            'body' => 'Try another date, or a smaller party — most boats have space midweek.',
            'contact' => 'Or write to :email and we will find something.',
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
