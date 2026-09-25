<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Catalogue — vessels and ports (spec CAT-1, CAT-2, CAT-3, I18N-1)
|--------------------------------------------------------------------------
|
| Every label, helper text and error the vessel and port resources show. Enum
| labels are NOT here — they live in `enums.php`, which is the one home for
| them (CNV-11).
|
| `LangKeyParityTest` asserts this file and `lang/el/catalog.php` have identical
| key sets in both directions, so an English string added without its Greek
| twin fails CI rather than reaching an operator.
|
*/

return [

    'vessel' => [
        'nav' => 'Boats',

        'model' => [
            'singular' => 'Boat',
            'plural' => 'Boats',
        ],

        'sections' => [
            'identity' => 'The boat',
            'capacity' => 'Capacity and crew',
            'operations' => 'Operations',
            'specs' => 'Specification',
            'media' => 'Photos',
        ],

        'form' => [
            'name' => [
                'label' => 'Name',
                'help' => 'The boat’s own name, as it appears on the hull. It is not translated.',
                'taken' => 'You already have a boat called “:name”.',
            ],
            'type' => ['label' => 'Type'],
            'status' => [
                'label' => 'Status',
                'help' => 'Only active boats can be sold. Maintenance hides the boat without retiring it.',
            ],
            'licence_type' => [
                'label' => 'Licence',
                'help' => 'Day passenger boat or professional pleasure boat. Printed on the passenger list.',
            ],
            'registration_number' => [
                'label' => 'Registration number',
                'help' => 'The ΑΛΣ registration. It is printed on the manifest and the charter agreement.',
            ],
            'length_cm' => [
                'label' => 'Length',
                'help' => 'In metres, to one decimal place.',
            ],
            'capacity_max' => [
                'label' => 'Maximum passengers',
                'help' => 'The legal limit for this boat. No trip and no departure may ever exceed it.',
            ],
            'crew_count' => ['label' => 'Crew'],
            'captain_name' => [
                'label' => 'Usual captain',
                'help' => 'Printed on the passenger list when the departure or its schedule names nobody else.',
            ],
            'home_port' => [
                'label' => 'Home port',
                'help' => 'Where the boat is based. Deleting a port never deletes the boat.',
                'none' => 'No home port',
            ],
            'max_wind_bft' => [
                'label' => 'Maximum wind (Beaufort)',
                'help' => 'The wind this boat does not sail above. Leave it empty for no weather warnings about it — nothing is ever cancelled automatically.',
            ],

            'turnaround_buffer_minutes' => [
                'label' => 'Turnaround time',
                'help' => 'Minutes needed between two trips on this boat. Leave empty to use your account setting (:default minutes).',
                'inherited' => 'Inheriting :minutes minutes from your account settings.',
            ],
            'description' => [
                'label' => 'Description',
                'help' => 'Shown to guests on the trip page.',
            ],
            'specs' => [
                'beam_m' => ['label' => 'Beam (m)'],
                'year_built' => ['label' => 'Year built'],
                'engine' => [
                    'label' => 'Engine',
                    'placeholder' => '2 × 180 hp',
                ],
                'cruising_speed_kn' => ['label' => 'Cruising speed (knots)'],
                'cabins' => ['label' => 'Cabins'],
                'wc' => ['label' => 'Toilets'],
                'amenities' => [
                    'label' => 'On board',
                    'help' => 'Shown as icons on the trip page.',
                ],
            ],
            'images' => [
                'label' => 'Photos',
                'help' => 'The first photo is the one guests see first. Drag to reorder.',
                'add' => 'Add a photo',
                'cover' => 'Cover',
                'make_cover' => 'Make it the cover',
                'file' => ['label' => 'File'],
                'alt' => [
                    'label' => 'Description of the photo',
                    'help' => 'Read aloud by screen readers and shown if the photo fails to load.',
                ],
            ],
        ],

        'table' => [
            'name' => 'Name',
            'type' => 'Type',
            'capacity_max' => 'Passengers',
            'home_port' => 'Home port',
            'status' => 'Status',
            'turnaround' => 'Turnaround',
            'turnaround_inherited' => ':minutes min (account)',
            'turnaround_own' => ':minutes min',
        ],

        'capacity' => [
            /*
             * The `capacity_max` guard (data-model §2.3). One string for both
             * the form field error and the exception an import raises, so an
             * operator and a log line say the same thing.
             */
            'refused' => 'This boat cannot carry fewer than :capacity passengers yet — :count record(s) already promise more: :records.',
        ],
    ],

    'port' => [
        'nav' => 'Ports & meeting points',

        'model' => [
            'singular' => 'Port',
            'plural' => 'Ports & meeting points',
        ],

        'sections' => [
            'identity' => 'The place',
            'location' => 'Finding it',
            'media' => 'Photo',
        ],

        'form' => [
            'name' => [
                'label' => 'Name',
                'help' => 'How guests and your crew refer to it — “Marina Zeas”, “Old harbour”.',
            ],
            'address' => [
                'label' => 'Address',
                'help' => 'One line. This is what the “open in maps” link searches for.',
            ],
            'lat' => ['label' => 'Latitude'],
            'lng' => ['label' => 'Longitude'],
            'coordinates' => [
                'help' => 'Optional, but far more precise than an address. Copy them from the map pin.',
            ],
            'instructions' => [
                'label' => 'How to find us',
                'help' => 'Shown on the booking confirmation — “meet at the blue kiosk, 15 minutes before departure”.',
            ],
            'maps_url' => [
                'label' => 'Map link',
                'help' => 'Optional. Use it when the address alone puts the pin in the wrong place.',
            ],
            'photo_path' => [
                'label' => 'Photo',
                'help' => 'A picture of the meeting point helps guests recognise it.',
            ],
            'is_active' => [
                'label' => 'Active',
                'help' => 'Inactive places stay on existing trips but cannot be chosen for new ones.',
            ],
            'sort_order' => [
                'label' => 'Order',
                'help' => 'Lower numbers appear first in lists.',
            ],
        ],

        'table' => [
            'name' => 'Name',
            'address' => 'Address',
            'vessels_count' => 'Boats based here',
            'is_active' => 'Active',
            'has_coordinates' => 'Pinned',
        ],
    ],

    'shared' => [
        'search_placeholder' => 'Search by name',
        'sort_order' => [
            'label' => 'Order',
            'help' => 'Lower numbers appear first in lists.',
        ],
    ],

    'product' => [
        'nav' => 'Trips',

        'model' => [
            'singular' => 'Trip',
            'plural' => 'Trips',
        ],

        'sections' => [
            'extras' => 'Extras',
            'extras_intro' => 'Whatever is offered alongside the seat, free or paid.',
            'questions' => 'Extra questions',
            'questions_intro' => 'Anything you want to ask the guest at checkout.',
            'basics' => 'The basics',
            'schedule' => 'Times and duration',
            'schedule_per_seat' => 'Duration and check-in',
            'content' => 'What the guest sees',
            'capacity' => 'How many people',
            'policy' => 'Terms and cancellation',
            'seo' => 'How it appears on Google',
            'bands' => 'Passenger groups',
            'checklist' => 'Before publishing',
            'images' => 'Photographs',
            'trip_page' => 'Trip page content',
        ],

        // The section of 16 September. All optional; only what is filled in
        // is shown.
        'trip_page' => [
            'description' => 'Everything here is optional. What you fill in appears on the trip page and on your WordPress site; anything left empty appears nowhere. None of it affects publishing.',
            'add_line' => 'Add a line',
            'line' => 'Line',
            'highlights' => [
                'label' => 'Highlights',
                'help' => 'Short phrases about what makes the day, e.g. "Three swimming stops in crystal-clear coves". Shown only when filled in.',
            ],
            'includes' => [
                'label' => 'Included',
                'help' => 'What the ticket pays for, e.g. "Coffee, water and fruit". Shown only when filled in.',
            ],
            'excludes' => [
                'label' => 'Not included',
                'help' => 'What is paid for separately, e.g. "Hotel transfers". Shown only when filled in.',
            ],
            'what_to_bring' => [
                'label' => 'What to bring',
                'help' => 'E.g. "Swimsuit and towel". Shown only when filled in.',
            ],
            'itinerary' => [
                'label' => 'Itinerary',
                'help' => 'The stops of the day, in order. The time is optional. Shown only when you add stops.',
                'add' => 'Add a stop',
                'untitled' => 'New stop',
                'time' => [
                    'label' => 'Time',
                    'help' => 'Optional.',
                ],
                'name' => [
                    'label' => 'What happens',
                ],
                'description' => [
                    'label' => 'Details',
                    'help' => 'Optional.',
                ],
            ],
        ],

        'form' => [
            // Ο ένας διακόπτης γλώσσας της φόρμας (2026-09-18).
            'locale_switch' => 'Form language',
            'vessel' => [
                'pleasure_per_seat' => 'This boat is licensed as a professional pleasure boat. Those are usually chartered whole and do not sell seats. Ask your lawyer before publishing.',
                'label' => 'Vessel',
                'help' => 'The boat that runs this trip. Its passenger certificate is a legal ceiling and is never exceeded.',
            ],
            'slug' => [
                'label' => 'Slug',
                'help' => 'Lowercase letters and dashes, e.g. "hydra-day-trip". Changing it breaks existing links.',
                'taken' => 'Another trip already uses this slug. Choose a different one.',
            ],
            'category' => [
                'label' => 'Kind of trip',
                'help' => 'Helps a guest filter.',
            ],
            'mode' => [
                'label' => 'How it is sold',
                'help' => 'Per seat or as a whole boat. It cannot change once the first booking is taken.',
            ],
            'images' => [
                'label' => 'Photographs',
                'help' => 'Upload them all at once. The first one leads the card and the search results — drag whichever you want to the front.',
            ],
            'title' => [
                'label' => 'Title',
                'help' => 'The name of the trip, as a guest sees it.',
            ],
            'summary' => [
                'label' => 'Subtitle',
                'help' => 'The line directly under the title on the trip page. The same words go on the trip card and into search results, so keep it to a sentence or two.',
            ],
            'badge' => [
                'label' => 'Label',
                'help' => 'Optional. A word or two on the trip card\'s photograph, e.g. "Popular" or "For two". Up to 24 characters. Leave it empty and nothing is shown.',
            ],
            'description' => [
                'label' => 'Description',
                'help' => 'The whole story of the trip, further down the page. As long as you like — the subtitle above is the short version.',
            ],
            // Two wordings, because «Schedule» is further down this same screen
            // but only appears on a trip that has been saved.
            'schedule_where' => 'Departure days and times are set below, under «Schedule».',
            'schedule_where_unsaved' => 'Departure days and times are set below, under «Schedule», once the trip is saved.',
            'duration_minutes' => [
                'label' => 'Duration',
                'help' => 'In minutes, from departure to return.',
                'suffix' => 'minutes',
            ],
            'default_start_time' => [
                'label' => 'Departure time',
                'help' => 'Local time. Everyone leaves at the same time.',
                'quote_help' => 'Optional. Leave it empty when the guest asks for a time in their request — you then quote for the time they asked for.',
                'optional' => 'Optional',
            ],
            'flexible_start' => [
                'label' => 'Flexible departure time',
                'help' => 'Private charters only: the guest picks a time inside the window below.',
            ],
            'earliest_start_time' => ['label' => 'No earlier than'],
            'latest_start_time' => ['label' => 'No later than'],
            'check_in_offset_minutes' => [
                'label' => 'Check in before',
                'help' => 'How many minutes before departure you expect the guest at the quay.',
                'suffix' => 'minutes',
            ],
            'landing_port' => [
                'label' => 'Landing port',
                'help' => 'If the passengers get off elsewhere. Empty: they come back to the same port.',
            ],
            'meeting_point' => [
                'label' => 'Meeting point',
                'help' => 'The port or marina where you meet.',
            ],
            'min_pax' => [
                'label' => 'Minimum to sail',
                'help' => 'Below this the departure is not guaranteed. Per-seat trips only.',
            ],
            'max_pax' => [
                'label' => 'Maximum passengers',
                'help' => 'Never above the vessel certificate.',
                // With a boat chosen, say the number rather than allude to it:
                // it is the figure the operator is judged against.
                'help_vessel' => ':vessel is certified for :capacity passengers. You cannot set more.',
            ],
            'min_booking_pax' => [
                'label' => 'Minimum per booking',
                'help' => 'How many seats one guest must book at least.',
            ],
            'cancellation_policy' => [
                'label' => 'Cancellation policy',
                'help' => 'Leave empty to use your default policy.',
            ],
            'vat_rate' => [
                'label' => 'VAT rate',
                'help' => 'Pick from the rates the platform keeps — you never type a percentage, because the law sets it. A trip is usually passenger transport; an extra such as food may take a different one. The rate is frozen onto the booking the moment its price is worked out, so a later change never rewrites an old receipt. Which one applies to you is your accountant\'s answer.',
            ],
            'guest_details_required' => [
                'label' => 'Collect passenger details',
                'help' => 'Names and documents, where the port authority requires them.',
            ],
            'guest_details_deadline_hours' => [
                'label' => 'Details deadline',
                'help' => 'Hours before departure.',
                'suffix' => 'hours',
            ],
            'status' => [
                'label' => 'Status',
                'help' => 'It can only go live once everything below is done.',
            ],
            'is_featured' => [
                'label' => 'Featured',
                'help' => 'Shown first in lists.',
            ],
            'meta_title' => [
                'label' => 'Google title',
                'help' => 'Left empty, the trip title is used.',
            ],
            'meta_description' => [
                'label' => 'Google description',
                'help' => 'About 155 characters.',
            ],
            'bands' => [
                'help' => 'Adult, Child and Infant are ready. Add whatever else you sell, e.g. disabled passengers or students. The base group gives the «from» price.',
                'add' => 'Add a group',
                'summary' => ['with_adult' => 'with an adult', 'no_document' => 'no document', 'by_status' => 'by status', 'proof' => 'proof at boarding'],
                'kind' => ['label' => 'What defines it'],
                'requires_proof' => ['label' => 'Shows proof at boarding', 'help' => 'E.g. a student or disability card. The crew see it on the boarding list. No number is asked at checkout.'],
                'code' => ['label' => 'Code', 'help' => 'Your own, e.g. "adult".'],
                'label' => ['label' => 'Name', 'help' => 'The guest reads this when choosing passengers.'],
                'min_age' => ['label' => 'From age'],
                'max_age' => ['label' => 'To age', 'help' => 'Empty means no upper limit.'],
                'counts_toward_capacity' => ['label' => 'Takes a seat', 'help' => 'An infant on a lap does not take a seat.'],
                'pricing_mode' => ['label' => 'Pricing'],
                'price_multiplier_bp' => ['label' => 'Share of the base', 'help' => '10000 = 100%, 5000 = 50%.'],
                'is_base' => ['label' => 'Base band'],
                'requires_adult' => ['label' => 'Needs an adult'],
                'no_document' => ['label' => 'No document', 'help' => 'Checkout asks these passengers for no document number, e.g. infants.'],
            ],
        ],

        'table' => [
            'preview' => 'View the page',
            'reorder' => 'Change the order',
            'title' => 'Trip',
            'vessel' => 'Vessel',
            'mode' => 'How it is sold',
            'status' => 'Status',
            'max_pax' => 'Passengers',
            'price_from' => 'Price from',
            'price_from_value' => 'from :price',
            'featured' => 'Featured',
            'missing' => 'Missing: :items|Missing :count: :items',
            'tabs' => [
                'all' => 'All',
            ],
        ],

        // The form's two tabs (2026-09-17).
        'steps' => [
            'back' => ':tab',
            'next' => 'Next: :tab',
            'last' => 'When you are ready, press «Publish» below.',
        ],
        'wizard' => [
            'continue' => 'Continue',
            'title' => 'New trip',
            // The new-trip guide (product owner, 2026-09-22, direction Α).
            'basics' => [
                'label' => 'The basics',
                'description' => 'What it is and how it sells',
            ],
            'when' => [
                'label' => 'When it sails',
                'description' => 'Time, length and days',
                'days' => 'Which days it sails',
                'days_help' => 'Change them whenever you like.',
                'schedules' => 'Schedules',
                'schedules_help' => 'The departures come from these. Add as many as you need: daily at 10:00 and 18:00, say, plus weekends at 12:00 for August only.',
                'add_schedule' => 'Another schedule',
                // The collapsed header of each schedule. `:days` is empty until
                // a day is picked, and the dash must not be left hanging — hence
                // two keys.
                'schedule_item' => 'Schedule :n — :days',
                'schedule_item_empty' => 'Schedule :n',
                'times' => 'Departure times',
                'times_help' => 'One or more, on the same days.',
                'valid_from_help' => 'Empty means from today.',
                'valid_until_help' => 'Empty means no end.',
                'schedule_refused' => 'The :time schedule was not saved',
            ],
            'prices' => [
                'vessel_section' => 'Boat price',
                'label' => 'Prices',
                'description' => 'What each person pays',
                'intro' => 'First the passenger groups: Adult, Child and Infant are ready; add disabled passengers, students or whatever else you sell. Then tick the periods that have another price and fill in the prices.',
                'price' => 'Price per person',
                'price_help' => 'Applies all year, whenever no period does.',
                'seasons' => 'Prices per period',
                'seasons_help' => 'Optional. If a period costs something else, put it here. Any band you leave blank keeps the price above.',
                'add_season' => 'Price for a period',
                'plan_refused' => 'The “:season” price list was not saved',
                'quote' => 'A trip sold on request has no price here: you send one with each enquiry, from “Quotes”.',
            ],
            'terms' => [
                'description' => 'Cancellation, VAT and what the guest is asked',
            ],
            'page' => [
                'label' => 'Page',
                'description' => 'Words and photographs',
                'intro' => 'This is what your guests read. All of it optional — you can fill it in later, on the trip itself.',
            ],
            'publish' => [
                'label' => 'Publish',
                'description' => 'Terms, and going on sale',
                'question' => 'What should happen when you finish?',
                'now' => 'Publish now',
                'now_help' => 'The trip goes on your page and takes bookings. If something is missing we will say so and keep it a draft.',
                'draft' => 'Keep it a draft',
                'draft_help' => 'Nobody sees it. You publish it from its own page whenever you are ready.',
                'vat_help' => 'Optional for now. It is needed when you issue an invoice, and your accountant says which rate applies.',
            ],
            'modes' => [
                'per_seat' => 'A shared trip: each guest books one seat or several.',
                'per_vessel' => 'A private charter: one customer takes the whole boat.',
                'quote' => 'The guest asks and you send them a price.',
            ],
            'weekdays' => [
                1 => 'Monday',
                2 => 'Tuesday',
                3 => 'Wednesday',
                4 => 'Thursday',
                5 => 'Friday',
                6 => 'Saturday',
                7 => 'Sunday',
            ],
        ],
        'tabs' => [
            'basics' => 'Basics',
            'when' => 'When it leaves',
            'prices' => 'Prices',
            'terms' => 'Terms',
            'page' => 'Page',
            'missing' => ':count missing|:count missing',
            'schedule' => 'Schedules',
            'no_schedule' => 'none',
            'active_schedules' => ':count active|:count active',
            'filled' => ':filled of :total',
        ],

        // Status changes with buttons, not a dropdown (2026-09-17).
        'status_actions' => [
            'save' => 'Save',
            'save_draft' => 'Save as draft',
            'continue_to_prices' => 'Continue to prices',
            'publish' => 'Publish',
            'unpublish' => 'Take off sale',
            'republish' => 'Put back on sale',
            'archive' => 'Archive',
            'archive_confirm' => 'The trip leaves sale for good. Its bookings stay.',
            'unarchive' => 'Restore as draft',
            'published' => 'The trip is published.',
            'unpublished' => 'The trip is off sale.',
            'archived' => 'The trip is archived.',
            'unarchived' => 'The trip is back as a draft.',
            'not_published' => 'Not published yet',
            'draft_hint' => 'Saved as a draft. Once everything is filled in, you will see the Publish button.',
        ],

        'checklist' => [
            'unsaved' => 'Save the trip first and you will see what is missing.',
            'met' => 'Done',
            'ready' => 'All done — this trip can be published.',
            'remaining' => 'One thing still missing.|:count things still missing.',
            'missing' => 'Missing',
            'vessel' => [
                'label' => 'Vessel',
                'unmet' => 'Choose the boat that runs this trip.',
            ],
            'meeting_point' => [
                'label' => 'Meeting point',
                'unmet' => 'Choose where you meet your guests.',
            ],
            'age_bands' => [
                'label' => 'Age bands',
                'unmet' => 'Add at least one age band.',
            ],
            'rate_plan' => [
                'label' => 'Rate plan',
                'unmet' => 'Create an active rate plan for this trip, under "Rate plans".',
            ],
            'prices' => [
                'label' => 'Prices for every category',
                'unmet' => 'Some prices are missing. Fill in the red cells in the «Prices in euros» table.',
            ],
            'cancellation_policy' => [
                'label' => 'Cancellation policy',
                'unmet' => 'Choose a cancellation policy, or set a default one.',
            ],
            'title_locales' => [
                'label' => 'Title in Greek and English',
                'unmet' => 'Fill in the title in both languages.',
            ],
        ],

        'validation' => [
            'max_pax_over_capacity' => 'You asked for :max_pax passengers, but “:vessel” is licensed for :capacity. The vessel limit is a legal one rather than a preference — lower the number or choose another boat.',
            'flexible_start_mode' => 'A flexible start time applies only to whole-boat private charters. This trip is “:mode”, where the departure time is shared by everyone on board.',
            'mode_locked' => '“:title” already has :count bookings, so the selling mode cannot change from “:from” to “:to”. Every departure and every stored price was worked out under the old one. Create a new trip instead.',

            'itinerary' => [
                'shape' => 'The itinerary is not in a valid form.',
                'missing_locale' => 'The itinerary is missing for “:locale”. Stops are needed in both languages.',
                'key' => 'Each stop needs a short identifier of up to :max characters.',
                'name' => 'Each stop needs a name of up to :max characters, in “:locale” too.',
                'description' => 'A stop description cannot be longer than :max characters.',
                'duration' => 'A stop duration must be a positive number of minutes.',
                'time' => 'A stop time is written as HH:MM, e.g. 09:45.',
                'key_mismatch' => 'The stops differ between languages: :keys. Every stop must exist in both, under the same identifier.',
                'geo_unknown' => 'There are coordinates for stops that do not exist: :keys.',
            ],
        ],
    ],

    'age_band' => [
        'nav' => 'Age bands',

        'model' => [
            'singular' => 'Age band',
            'plural' => 'Age bands',
        ],

        'validation' => [
            'empty' => 'Every trip needs at least one age band — otherwise there is nothing to say who may board or what they pay.',
            'overlap' => '“:first” and “:second” cover the same ages. Every age must belong to exactly one band.',
            'no_base' => 'One band has to be the base — usually the adult. Every other price is worked out as a share of it.',
            'many_base' => 'Only one band can be the base. These are marked: :bands.',
            'none_counted' => 'At least one band must take up a seat. Otherwise the trip would accept unlimited passengers on a boat with a limit.',
            'multiplier_required' => '“:band” is priced as a share of the base band, so it needs a percentage. For a price of its own, change how it is priced.',
            'pricing_mode' => 'The pricing mode for “:band” is not recognised.',
            'label_locale' => '“:band” needs a name in “:locale” too — this is the list a guest chooses from.',
            'duplicate_code' => 'A code appears twice: :codes. Each band needs its own.',
        ],
    ],

    'question' => [
        'title' => 'Questions',
        'help' => 'Questions the guest answers at checkout, for each passenger or once for the booking.',
        'empty' => 'No questions',
        'add' => 'New question',
        'label' => ['label' => 'Question', 'help' => 'As the guest will see it.'],
        'type' => 'Type of answer',
        'scope' => 'Who answers',
        'options_el' => 'Choices in Greek',
        'options_en' => 'Choices in English',
        'options_help' => 'One choice per line, in the same order in both languages.',
        'options_mismatch' => 'The Greek and English choices must have the same number of lines.',
        'is_required' => 'Required',
        'is_active' => 'Active',
        'sort_order' => 'Order',
    ],

    'extra' => [
        'nav' => 'Extras',

        'model' => [
            'singular' => 'Extra',
            'plural' => 'Extras',
        ],

        'on_request' => 'On request',

        // On the trip (2026-09-17).
        'on_product' => [
            'title' => 'Extras',
            'help' => 'What you offer with the trip. Free ones are listed under «Included»; the paid ones a guest chooses when booking.',
            'add' => 'New extra',
            'empty' => 'No extras',
            'empty_help' => 'E.g. «Masks and fins» for free, or «Lunch on board» at 25 € per person.',
            'name' => [
                'label' => 'Name',
                'help' => 'As the guest will see it.',
            ],
            'kind' => [
                'label' => 'Charge',
                'free' => 'Free',
                'paid' => 'Paid',
            ],
            'pricing_type' => [
                'label' => 'How it is charged',
            ],
            'price' => [
                'label' => 'Price',
                'help' => 'In euros, VAT included.',
            ],
            'vat_rate' => [
                'label' => 'VAT for this extra',
                'help' => 'Leave empty to use the trip\'s VAT. Fill it in only when this extra belongs to a different category — a meal on board is not the same as carrying passengers.',
            ],
            'is_required' => [
                'label' => 'Required',
                'help' => 'Added to every booking without the guest choosing it.',
            ],
            'is_active' => [
                'label' => 'Active',
            ],
        ],

        'validation' => [
            'on_request_has_price' => 'On-request extras carry no price — you agree it with the guest. Either clear the price or change how it is charged.',
            'price_required' => 'Give it a price, or change how it is charged to on request.',
            'max_qty_exceeded' => '“:extra” can be added at most :max times per booking.',
        ],
    ],

];
