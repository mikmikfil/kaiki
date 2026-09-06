<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Enum labels (CNV-11, I18N-1)
|--------------------------------------------------------------------------
|
| One home for every backed enum's operator-facing label, keyed by the enum's
| snake_cased short name and then by its case value. `HasTranslatedLabel`
| resolves `enums.{enum}.{value}.label` and nothing in an enum class may return
| a literal.
|
| These lines used to live in four single-purpose files — `roles.php`,
| `plans.php`, `tenant_status.php` and `domains.php` — plus the API vocabulary
| in `api.php`. Four enums justified four files; M1 adds a dozen more, and the
| answer to "where is this label" has to stay one file rather than a search.
|
| `api.php` keeps the API *error* envelope, which is prose aimed at an
| integrator rather than a label on a form.
|
*/

return [

    'role' => [
        'owner' => [
            'label' => 'Owner',
            'description' => 'Full access, including billing, staff and deleting the account.',
        ],
        'manager' => [
            'label' => 'Manager',
            'description' => 'Runs the day to day: trips, pricing, bookings and guests. No billing.',
        ],
        'crew' => [
            'label' => 'Crew',
            'description' => "Sees today's departures and the check-in screen. Nothing else.",
        ],
    ],

    'plan' => [
        'trial' => ['label' => 'Trial'],
        'solo' => ['label' => 'Solo'],
        'fleet' => ['label' => 'Fleet'],
        'pro' => ['label' => 'Pro'],
    ],

    'tenant_status' => [
        'trialing' => ['label' => 'Trial'],
        'active' => ['label' => 'Active'],
        'past_due' => ['label' => 'Payment overdue'],
        'read_only' => ['label' => 'Read only'],
        'suspended' => ['label' => 'Suspended'],
    ],

    'domain_status' => [
        'pending' => ['label' => 'Waiting for DNS'],
        'verified' => ['label' => 'Verified'],
        'failed' => ['label' => 'Verification failed'],
        'disabled' => ['label' => 'Disabled'],
    ],

    'api_key_type' => [
        'publishable' => ['label' => 'Publishable'],
        'secret' => ['label' => 'Secret'],
    ],

    'api_key_environment' => [
        'live' => ['label' => 'Live'],
        'test' => ['label' => 'Test'],
    ],

    /*
    | Scope values contain a dot, and Laravel reads a dot in a translation key
    | as a path separator — so these are fetched as an array and indexed, never
    | looked up as `enums.api_scope.products.read`. That bug shipped in #6 and
    | was only found in #10, when the labels first reached a form.
    */
    'api_scope' => [
        'products.read' => ['label' => 'Read trips'],
        'availability.read' => ['label' => 'Read availability'],
        'branding.read' => ['label' => 'Read branding'],
        'bookings.write' => ['label' => 'Create bookings'],
        'quotes.write' => ['label' => 'Create quotes'],
        'webhooks.receive' => ['label' => 'Receive webhooks'],
    ],

    'extra_pricing' => [
        'per_booking' => ['label' => 'Per booking'],
        'per_person' => ['label' => 'Per person'],
        'on_request' => ['label' => 'On request'],
    ],

    'age_band_pricing' => [
        'multiplier' => ['label' => 'Share of the base price'],
        'fixed' => ['label' => 'Its own price'],
    ],

    'booking_mode' => [
        'per_seat' => ['label' => 'Per seat'],
        'per_vessel' => ['label' => 'Whole boat'],
        'quote' => ['label' => 'On request'],
    ],

    'deposit_type' => [
        'none' => ['label' => 'The full amount'],
        'percent' => ['label' => 'A share of the total'],
        'fixed' => ['label' => 'A flat amount'],
    ],

    'product_category' => [
        'shared_full_day' => ['label' => 'Shared full day'],
        'shared_half_day' => ['label' => 'Shared half day'],
        'private_full_day' => ['label' => 'Private full day'],
        'private_half_day' => ['label' => 'Private half day'],
        'sunset' => ['label' => 'Sunset'],
        'custom' => ['label' => 'Other'],
    ],

    'product_status' => [
        'draft' => ['label' => 'Draft'],
        'active' => ['label' => 'On sale'],
        'inactive' => ['label' => 'Off sale'],
        'archived' => ['label' => 'Archived'],
    ],

    'vessel_type' => [
        'catamaran' => ['label' => 'Catamaran'],
        'sailing_yacht' => ['label' => 'Sailing yacht'],
        'motor' => ['label' => 'Motor boat'],
        'rib' => ['label' => 'RIB'],
        'traditional_kaiki' => ['label' => 'Traditional kaiki'],
    ],

    'vessel_status' => [
        'active' => ['label' => 'Active'],
        'inactive' => ['label' => 'Inactive'],
        'maintenance' => ['label' => 'In maintenance'],
    ],

    'vessel_amenity' => [
        // Comfort on deck.
        'shade_canopy' => ['label' => 'Shade canopy'],
        'sun_deck' => ['label' => 'Sun deck'],
        'air_conditioning' => ['label' => 'Air conditioning'],
        'cabin' => ['label' => 'Cabin'],
        'wc' => ['label' => 'Toilet'],

        // Water and swimming.
        'swim_ladder' => ['label' => 'Swim ladder'],
        'freshwater_shower' => ['label' => 'Freshwater shower'],
        'snorkelling_gear' => ['label' => 'Snorkelling gear'],
        'paddleboard' => ['label' => 'Paddleboard'],
        'fishing_gear' => ['label' => 'Fishing gear'],
        'beach_towels' => ['label' => 'Beach towels'],

        // Food and drink.
        'fridge' => ['label' => 'Fridge'],
        'drinking_water' => ['label' => 'Drinking water'],
        'galley' => ['label' => 'Galley'],
        'barbecue' => ['label' => 'Barbecue'],
        'coffee_machine' => ['label' => 'Coffee machine'],

        // Power and connectivity.
        'sound_system' => ['label' => 'Sound system'],
        'usb_charging' => ['label' => 'USB charging'],
        'wifi' => ['label' => 'Wi-Fi'],

        // Who the boat suits.
        'child_life_jackets' => ['label' => "Children's life jackets"],
        'wheelchair_accessible' => ['label' => 'Wheelchair accessible'],
        'pet_friendly' => ['label' => 'Pets welcome'],
    ],

    'departure_status' => [
        'scheduled' => ['label' => 'Scheduled'],
        'guaranteed' => ['label' => 'Guaranteed'],
        'cancelled' => ['label' => 'Cancelled'],
        'completed' => ['label' => 'Completed'],
    ],

    'departure_cancel_reason' => [
        'weather' => ['label' => 'Weather'],
        'operator' => ['label' => 'Operator decision'],
        'min_pax' => ['label' => 'Too few passengers'],
        'vessel_booked_privately' => ['label' => 'Private charter'],
    ],

    'block_reason' => [
        'private_booking' => ['label' => 'Private charter'],
        'maintenance' => ['label' => 'Maintenance'],
        'external_ical' => ['label' => 'External calendar'],
        'manual' => ['label' => 'Manual'],
    ],

    'audit_action' => [
        'vessel.deleted' => ['label' => 'Boat deleted'],
        'product.deleted' => ['label' => 'Trip deleted'],
        'departure.cancelled' => ['label' => 'Departure cancelled'],
        'booking.refunded' => ['label' => 'Booking refunded'],
        'gdpr.purged' => ['label' => 'Personal data purged'],
        'api_key.revoked' => ['label' => 'API key revoked'],
        'record.deleted' => ['label' => 'Record deleted'],
        'override.applied' => ['label' => 'Override applied'],
    ],

    'availability_rejection' => [
        'product_not_active' => ['label' => 'This trip is not available at the moment.'],
        'vessel_not_active' => ['label' => 'This boat is not available at the moment.'],
        'departure_cancelled' => ['label' => 'This departure was cancelled.'],
        'lead_time_too_short' => ['label' => 'Booking has closed for this departure.'],
        'too_far_ahead' => ['label' => 'We are not taking bookings that far ahead yet.'],
        'vessel_busy' => ['label' => 'The boat is already booked at that time.'],
        'not_enough_seats' => ['label' => 'There are not enough seats left.'],
        'legal_capacity_exceeded' => ['label' => 'That would put more people on board than the boat is licensed for.'],
        'no_counted_pax' => ['label' => 'At least one passenger who takes a seat is needed.'],
        'tenant_read_only' => ['label' => 'Bookings are not available at the moment.'],
        'off_grid' => ['label' => 'Please choose a time on the quarter hour (09:00, 09:15 and so on).'],
        'outside_operating_window' => ['label' => 'That time is outside the hours this operator sails.'],
        'extension_too_long' => ['label' => 'That is more extra hours than this trip allows.'],
        'dst_non_existent' => ['label' => 'The clocks change that day and this time does not exist. Please pick another.'],
        'no_proposed_window' => ['label' => 'No departure time was given.'],
        'vessel_held' => ['label' => 'Someone else is checking out for this time. Please try again shortly.'],
    ],

    'locale' => [
        'el' => ['label' => 'Ελληνικά', 'short' => 'ΕΛ'],
        'en' => ['label' => 'English', 'short' => 'EN'],
    ],

    'font_source' => [
        'system' => ['label' => 'System font'],
        'google' => ['label' => 'Google Fonts'],
    ],

    'widget_theme' => [
        'light' => ['label' => 'Light'],
        'dark' => ['label' => 'Dark'],
        'auto' => ['label' => 'Match the visitor'],
    ],

    /*
    | Integration providers and their environment (data-model §2.7, PAY-4).
    |
    | The provider names are vendor trademarks and stay in Latin script in both
    | languages — a Greek operator looking for "Viva" in a list is looking for
    | the word on their own Viva dashboard.
    */
    'integration_provider' => [
        'viva' => ['label' => 'Viva Wallet'],
        'stripe' => ['label' => 'Stripe'],
        'mydata' => ['label' => 'myDATA (AADE)'],
        'apifon' => ['label' => 'Apifon'],
        'yuboto' => ['label' => 'Yuboto'],
        'twilio' => ['label' => 'Twilio'],
        'postmark' => ['label' => 'Postmark'],
    ],

    /*
    | Deliberately the same two words as `api_key_environment` above. PAY-11
    | pairs them directly — sandbox mode means a test key reaching test gateway
    | credentials — and two vocabularies for one concept is how a query
    | eventually asks the wrong one.
    */
    'credential_environment' => [
        'live' => ['label' => 'Live'],
        'test' => ['label' => 'Test'],
    ],

];
