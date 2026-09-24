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

    'not_found' => [
        'title' => 'This page does not exist',
        'lede' => 'The address may have changed, or the trip is no longer available.',
        'back' => 'See our trips',
    ],

    'nav' => [
        'language' => 'Language',
        'menu' => 'Menu',
        'book' => 'Book now',
        'call' => 'Phone: :phone',
    ],

    'index' => [
        'meta_description' => 'Boat trips and charters with :operator. Book online.',
        'trips' => 'Featured trips',
        'no_trips' => 'There are no trips on sale at the moment.',
        'duration' => ':minutes minutes',
        'duration_hours' => '{1} :hours hour|[2,*] :hours hours',
        'duration_hours_minutes' => '{1} :hours hour :minutes minutes|[2,*] :hours hours :minutes minutes',
        'view' => 'See this trip',
        'see_all' => 'See every trip',
        'all_trips' => 'All our trips',
        'search_prompt' => 'When would you like to sail?',
        'from' => 'from',
    ],

    // HOS-10. It says who to contact rather than what went wrong — the visitor
    // cannot fix an operator's subscription and does not need to know about it.
    'blocks' => [
        // «About us» (2026-09-24): the lines a new section starts with.
        'timeline' => ['eyebrow' => 'Our story', 'heading' => 'How we got here'],
        'fleet' => ['eyebrow' => 'The fleet', 'heading' => 'Our boats'],
        'crew' => ['eyebrow' => 'At the helm', 'heading' => 'Our people'],
        'credentials' => ['eyebrow' => 'Licences and insurance', 'heading' => 'All in order'],
        'meeting_point' => ['eyebrow' => 'Meeting point', 'heading' => 'Where we leave from'],
        'hero' => [
            'cta' => [
                'trips' => 'See our trips',
                'contact' => 'Get in touch',
            ],
            // The title of a decorative frame. Nobody reads it out — the
            // element is hidden from assistive technology — but an iframe with
            // no title at all is a finding in every accessibility audit, and a
            // title in the wrong language is the kind of detail these pages are
            // translated to get right.
            'video' => 'Background video',
            'search_title' => 'Find your trip',
        ],
        // The sections of 16 September. The starting content the editor puts
        // into a new section, for the operator to keep, change or delete — so
        // it is written as the operator would.
        'stats' => [
            'heading' => 'At a glance',
        ],
        'steps' => [
            'eyebrow' => 'How it works',
            'heading' => 'From your screen to the deck',
            'defaults' => [
                ['title' => 'Choose a trip', 'text' => 'See the free seats for every day and the price for your group.'],
                ['title' => 'Pay online', 'text' => 'By card, securely. Your ticket arrives by email straight away.'],
                ['title' => 'Meet us at the boat', 'text' => 'A few minutes before departure. We bring the coffee, you bring a swimsuit.'],
            ],
        ],
        'features' => [
            'eyebrow' => 'Why us',
            'heading' => 'Everything you need for an easy day',
            'defaults' => [
                ['title' => 'Small groups', 'text' => 'Never more people than the boat carries comfortably.'],
                ['title' => 'Experienced crew', 'text' => 'Skippers who know every cove in the area.'],
                ['title' => 'Weather guarantee', 'text' => 'If the weather keeps us in, move your day or get your money back.'],
                ['title' => 'Secure payment', 'text' => 'Pay online and your ticket arrives straight away.'],
            ],
        ],
        'testimonials' => [
            'eyebrow' => 'Reviews',
            'heading' => 'What our guests say',
            // For a screen reader. The stars are drawing.
            'rating' => '{1} One star out of five|[2,*] :count stars out of five',
        ],
        'cta' => [
            'eyebrow' => 'Book your day',
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
            'social' => [
                'instagram' => 'Instagram',
                'facebook' => 'Facebook',
                'whatsapp' => 'WhatsApp',
                'tiktok' => 'TikTok',
                'youtube' => 'YouTube',
                'x' => 'X',
            ],
        ],
    ],

    'product' => [
        'breadcrumb' => 'Breadcrumb',
        'max_pax' => 'Up to :count people',
        'all_trips' => 'All trips',
        'departs' => 'Departs :time',
        'all_photos' => 'All photos (:count)',
        'about' => 'About this trip',
        'gallery' => 'Photographs',
        'ask' => [
            'heading' => 'Any questions?',
            'body' => 'Tell us what you would like to know about this trip.',
            'call' => 'Call us',
            'write' => 'Send a message',
        ],
        'tabs' => [
            'departures' => 'Next departures',
            'meeting' => 'Where we meet',
            'vessel' => 'The boat',
        ],
        'boat' => [
            'type' => 'Type',
            'length' => 'Length',
            'capacity' => 'Guests',
            'crew' => 'Crew',
            'captain' => 'Skipper',
            'registration' => 'Registration',
            'metres' => ':length m',
            'knots' => 'knots',
            'specs' => [
                'beam_m' => 'Beam',
                'draft_m' => 'Draft',
                'year_built' => 'Year built',
                'refit_year' => 'Refitted',
                'engine' => 'Engine',
                'engines' => 'Engines',
                'cruising_speed_kn' => 'Cruising speed',
                'max_speed_kn' => 'Top speed',
                'fuel' => 'Fuel',
                'cabins' => 'Cabins',
                'berths' => 'Berths',
                'toilets' => 'Heads',
                'flag' => 'Flag',
                'builder' => 'Builder',
            ],
        ],
        'close' => 'Close',
        'highlights' => 'Highlights',
        'includes' => 'Included',
        'excludes' => 'Not included',
        'what_to_bring' => 'What to bring',
        'itinerary' => 'The itinerary',
        'departures' => 'Upcoming departures',
        'map_title' => 'Map of :place',
        'sold_out' => 'Sold out',
        'seats_left' => '{1} last seat|[2,*] :count seats left',
        'available' => 'Available',
        'meeting_point' => 'Where we meet',
        'check_in' => 'Please be there :minutes minutes before departure.',
        'vessel' => 'The boat',
        'vessel_capacity' => 'Up to :count people on board.',
        'age_bands' => 'Who pays what',
        'age_range' => 'ages :from to :to',
        'age_from' => 'ages :from and over',
        'no_seat' => 'does not take a seat',
        'cancellation_question' => 'Can I cancel?',
        'cancellation' => 'Cancellation',
        'free_cancellation' => 'Free cancellation up to :hours hours before departure.',
        'tier' => ':days days before departure: :percent% refunded',
        'weather_refund' => 'If we cancel for weather, you are refunded :percent%.',
        'price' => [
            'from' => 'From',
            'on_request' => 'On request',
            // Brand decision 4 of 2026-09-04: the sentence, never the rate. The
            // rate is printed on the invoice, which is M6.
            'vat_included' => 'VAT is included in the price',
            'extra_pax' => 'Includes up to :included people · +:extra for each extra person',
        ],
        // ADR-0033's bar, which lives only until the widget pins its own sheet.
        // Its own keys rather than `price` and `booking`, because these copy the
        // widget's peek word for word: when the handover happens not a letter
        // changes. Lowercase "from" and "Continue" because that is what the
        // widget says; the aside keeps its own, where "From" opens the line.
        'bar' => [
            'from' => 'from',
            'action' => 'Continue',
            'enquire_action' => 'Send',
        ],
        'booking' => [
            'heading' => 'Book this trip',
            'enquire' => 'Ask for a quote',
            'pick_date' => 'Pick a date',
            'enquire_summary' => 'We answer with a quote',
            // The four lines above the date picker, brand decision 3.
            'details' => 'Trip details',
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
        'departs_at' => 'departs :time',
        'count' => '{1} One trip found|[2,*] :count trips found',
        'all_count' => '{1} One trip|[2,*] :count trips',
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
        'explore' => 'Explore',
        'home' => 'Home',
        'vat_number' => 'VAT number',
        'tax_office' => 'Tax office',
        'terms' => 'Terms',
        'privacy' => 'Privacy',
        'cancellation' => 'Cancellation policy',
        // Brand decision 6 of 2026-09-04, rendered from a flag.
        'powered_by' => 'Powered by Kaiki',
    ],

    'contact' => [
        'nav' => 'Contact',
        'title' => 'Contact us',
        'meta' => 'Get in touch with :operator — telephone, email and a message form.',
        'lede' => 'Ask us anything about a trip, a date or a private charter. We read every message.',
        'details_heading' => 'Or reach us directly',
        'about' => 'About: :trip',
        'name' => 'Your name',
        'email' => 'Email',
        'phone' => 'Telephone',
        'optional' => '(optional)',
        'message' => 'Your message',
        'submit' => 'Send message',
        // The button on the home page's contact banner. Not the page's own
        // title, which the heading directly above it already says — a button
        // repeating the heading it sits under reads as a mistake.
        'cta' => 'Send us a message',
        'sent' => 'Thank you — your message has arrived and we will answer you by email.',
        // Deliberately not "you failed our bot check": the only person this ever
        // refuses wrongly is a human on a fast connection, and they need a way
        // forward rather than an accusation.
        'rejected' => 'We could not send that message. Please try again.',
        'privacy' => 'We use what you write here only to answer you.',
        'reply_time' => 'We answer within a working day, and usually sooner.',
        // The honeypot's visible label. Never seen by a person — the field is
        // hidden from sight and from the accessibility tree — but a label is
        // what makes it look real to the scripts it is there to catch.
        'honeypot' => 'Company website',
    ],

    // «About us» (2026-09-24).
    'about' => [
        'nav' => 'About us',
        'title' => 'About us · :operator',
        'fleet' => [
            'capacity' => 'People',
            'length' => 'Length',
            'metres' => ':value m',
            'trips' => '{1} 1 trip on this boat|[2,*] :count trips on this boat',
        ],
        'credentials' => [
            'boats' => '{1} 1 boat|[2,*] :count boats',
            'vat' => 'VAT :number',
            'gemi' => 'GEMI number',
        ],
        'port' => [
            'directions' => 'Directions in Maps',
            'north' => 'N',
            'south' => 'S',
            'east' => 'E',
            'west' => 'W',
        ],
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
