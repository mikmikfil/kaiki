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

    'manifest_column' => [
        'full_name' => ['label' => 'Full name'],
        'date_of_birth' => ['label' => 'Date of birth'],
        'nationality' => ['label' => 'Nationality'],
        'document_type' => ['label' => 'Document'],
        'document_number' => ['label' => 'Document number'],
        'reference' => ['label' => 'Booking'],
        'age_band' => ['label' => 'Passenger type'],
        'checked_in' => ['label' => 'Checked in'],
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
        'payment.recorded' => ['label' => 'Payment recorded by hand'],
        'manifest.generated' => ['label' => 'Passenger list with document numbers generated'],
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

    'home_block_type' => [
        'hero' => [
            'label' => 'Hero',
            'description' => 'One image, a heading and one button. The first thing a visitor sees.',
        ],
        'trips' => [
            'label' => 'Trips',
            'description' => 'Your catalogue, all of it or a slice.',
        ],
        'story' => [
            'label' => 'Text',
            'description' => 'A heading and prose, with an image beside it if you want one. The "about us".',
        ],
        'gallery' => [
            'label' => 'Photographs',
            'description' => 'A row of images, each with a description in both languages.',
        ],
        'contact' => [
            'label' => 'Contact',
            'description' => 'Phone, email, address and meeting point — from your own details.',
        ],
        'faq' => [
            'label' => 'Frequently asked questions',
            'description' => 'The entries you write on the FAQ screen. Only the ones about every trip are shown here.',
        ],
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

    /*
    | Bookings (§4.1, BKG-3 … BKG-9).
    |
    | `draft` and `pending_payment` are the two an operator sees most and the
    | two most easily confused. The labels say what each *means for the seat*
    | rather than naming the state — "holding seats" against "at the payment
    | page" — because that is the difference an operator is actually asking
    | about when they look at a list.
    */
    'booking_status' => [
        'draft' => ['label' => 'Holding seats'],
        'quote_requested' => ['label' => 'Quote requested'],
        'quote_sent' => ['label' => 'Quote sent'],
        'pending_payment' => ['label' => 'At the payment page'],
        'confirmed' => ['label' => 'Confirmed'],
        'checked_in' => ['label' => 'Checked in'],
        'completed' => ['label' => 'Completed'],
        'cancelled' => ['label' => 'Cancelled'],
        'refunded' => ['label' => 'Refunded'],
        'expired' => ['label' => 'Expired'],
    ],

    'booking_source' => [
        'widget' => ['label' => 'Website widget'],
        'hosted' => ['label' => 'Booking page'],
        'wordpress' => ['label' => 'WordPress'],
        'manual' => ['label' => 'Entered by you'],
        'import' => ['label' => 'Imported'],
    ],

    'guest_details_status' => [
        'not_required' => ['label' => 'Not needed'],
        'pending' => ['label' => 'Waiting on the guest'],
        'complete' => ['label' => 'Received'],
    ],

    'guest_document_type' => [
        'passport' => ['label' => 'Passport'],
        'id_card' => ['label' => 'ID card'],
        'other' => ['label' => 'Other document'],
    ],

    /*
    | Who cancelled decides the refund: a guest cancellation is judged against
    | the policy snapshot, an operator or system one is refunded in full
    | whatever the tiers say.
    */
    'cancelled_by' => [
        'guest' => ['label' => 'The guest'],
        'operator' => ['label' => 'You'],
        'system' => ['label' => 'Automatically'],
    ],

    'cancel_reason' => [
        'guest_request' => ['label' => 'The guest asked to cancel'],
        'weather' => ['label' => 'Weather'],
        'operator' => ['label' => 'You cancelled it'],
        'min_pax' => ['label' => 'Not enough passengers'],
        'vessel_booked_privately' => ['label' => 'The boat was chartered privately'],
        'payment_failed' => ['label' => 'Payment failed'],
        'hold_expired' => ['label' => 'The seats were not paid for in time'],
        'quote_declined' => ['label' => 'The guest turned down the quote'],
    ],

    'voucher_status' => [
        'active' => ['label' => 'Can be used'],
        'redeemed' => ['label' => 'Fully used'],
        'expired' => ['label' => 'Expired'],
        'cancelled' => ['label' => 'Cancelled'],
    ],

    'voucher_reason' => [
        'weather_cancellation' => ['label' => 'Weather cancellation'],
        'operator_cancellation' => ['label' => 'You cancelled the trip'],
        'force_majeure' => ['label' => 'Force majeure'],
        'goodwill' => ['label' => 'Goodwill'],
        'manual' => ['label' => 'Issued by hand'],
    ],

    /*
    | Payments (§2.5, PAY-8, PAY-10, ADR-0004 Option D).
    |
    | `pending` and `processing` read almost the same and mean different things
    | to an operator chasing money: one is a guest who was sent to a payment page
    | and may never have arrived, the other is a gateway that has the money and
    | has not settled it. Only `succeeded` counts toward what a booking has paid.
    */
    'payment_status' => [
        'pending' => ['label' => 'Waiting for the guest'],
        'processing' => ['label' => 'With the gateway'],
        'succeeded' => ['label' => 'Paid'],
        'failed' => ['label' => 'Declined'],
        'cancelled' => ['label' => 'Cancelled'],
    ],

    /*
    | Deposit and balance are two independent checkout sessions, months apart if
    | need be (ADR-0004 Option D) — not two halves of one.
    */
    'payment_kind' => [
        'full' => ['label' => 'Paid in full'],
        'deposit' => ['label' => 'Deposit'],
        'balance' => ['label' => 'Balance'],
        'refund' => ['label' => 'Refund'],
    ],

    /*
    | Cash and bank transfer never call anything: they are how a manual booking
    | is recorded as paid (BKG-33), and they are excluded from gateway
    | reconciliation because there is no gateway to reconcile them against.
    */
    'payment_gateway_name' => [
        'viva' => ['label' => 'Viva Wallet'],
        'cash' => ['label' => 'Cash'],
        'bank_transfer' => ['label' => 'Bank transfer'],
    ],

    /*
    | What became of an inbound webhook (§2.7, PAY-7).
    |
    | `orphaned` is the one worth reading twice: a verified, genuine payment
    | that matches no booking. Somebody has been charged and we cannot say for
    | what — which is a different thing from a failure and a very different
    | thing from an event we chose not to act on.
    */
    'webhook_event_status' => [
        'received' => ['label' => 'Received'],
        'processed' => ['label' => 'Processed'],
        'ignored' => ['label' => 'Not applicable'],
        'failed' => ['label' => 'Failed'],
        'orphaned' => ['label' => 'Paid, but unmatched'],
    ],

    /*
    | The guest's answer after a weather cancellation (CXL-6, CXL-7).
    |
    | `rebook` issues the same voucher `voucher` does — there is no
    | seat-transfer flow, and a voucher is the only thing that carries a guest's
    | money to a new booking. What it adds is intent, which is why the two are
    | separate cases rather than one.
    */
    'weather_choice' => [
        'refund' => ['label' => 'Money back'],
        'voucher' => ['label' => 'A voucher'],
        'rebook' => ['label' => 'Book another date'],
    ],

    /*
    | How an operator settled a refund they overrode (CXL-5).
    |
    | `waived` is not a 0% policy outcome: 0% is a term the guest agreed to,
    | waived is a decision somebody made and has to be able to defend.
    */
    'refund_method' => [
        'cash' => ['label' => 'Money back'],
        'voucher' => ['label' => 'A voucher instead'],
        'waived' => ['label' => 'Nothing back'],
    ],

    /*
    | Where a quote stands (§4.4).
    |
    | `expired` covers two things on purpose: an offer that ran out of time and
    | one replaced by a newer version. Either way the link the guest is holding
    | is no longer the offer, which is what they need to be told.
    */
    'quote_status' => [
        'draft' => ['label' => 'Being written'],
        'sent' => ['label' => 'Sent to the guest'],
        'accepted' => ['label' => 'Accepted'],
        'declined' => ['label' => 'Turned down'],
        'expired' => ['label' => 'Expired or replaced'],
    ],

    /*
    | What a line on a quote is (BKG-27). Discount amounts are positive; the
    | kind is what carries the minus sign.
    */
    'quote_line_kind' => [
        'charter' => ['label' => 'Charter'],
        'extra' => ['label' => 'Extra'],
        'fee' => ['label' => 'Fee'],
        'discount' => ['label' => 'Discount'],
    ],

    /*
    | The enquiry inbox (BKG-28).
    |
    | `spam` is kept rather than deleted, so an operator who suspects a real
    | message was thrown away has somewhere to look.
    */
    'enquiry_status' => [
        'new' => ['label' => 'New'],
        'in_progress' => ['label' => 'Being handled'],
        'answered' => ['label' => 'Answered'],
        'converted' => ['label' => 'Became a booking'],
        'spam' => ['label' => 'Spam'],
        'closed' => ['label' => 'Closed'],
    ],

    /*
    | Where a ναυλοσύμφωνο stands (§2.6).
    |
    | The document is M6; the table and this enum land in #88 because §0
    | forbids adding a foreign key to an existing table on SQLite.
    |
    | `accepted` has no way out — see `AgreementStatus`. The label says so in
    | the plainest terms available, because an operator looking for a way to
    | withdraw an agreement needs to be told to raise a new version rather than
    | to hunt for a button that is deliberately absent.
    */
    'agreement_status' => [
        'draft' => ['label' => 'Draft'],
        'generated' => ['label' => 'Generated'],
        'sent' => ['label' => 'Sent to the guest'],
        'accepted' => ['label' => 'Accepted by the guest'],
        'void' => ['label' => 'Withdrawn'],
    ],

    /*
    | How a message went out (§2.7).
    |
    | `webhook` sits beside the two a guest can read because an operator asking
    | "did anything reach my system about this booking" wants one timeline
    | rather than three.
    */
    'notification_channel' => [
        'mail' => ['label' => 'Email'],
        'sms' => ['label' => 'SMS'],
        'webhook' => ['label' => 'Webhook'],
    ],

    /*
    | Where a send got to (NTF-3, NTF-8).
    |
    | `failed` is our side and `bounced` is theirs: an operator can fix the
    | first and can only telephone about the second, so a status that merged
    | them would be a feed nobody could act on.
    */
    'notification_status' => [
        'queued' => ['label' => 'Waiting to go'],
        'sent' => ['label' => 'Sent'],
        'delivered' => ['label' => 'Delivered'],
        'bounced' => ['label' => 'Bounced back'],
        'failed' => ['label' => 'Failed'],
    ],

    /*
    | Who carried it (NTF-1, NTF-2).
    |
    | `null_gateway` is a real provider rather than an absence: it composes and
    | logs the message and sends nothing, so an operator with no SMS account can
    | see that their reminders are being written and dropped.
    */
    'notification_provider' => [
        'postmark' => ['label' => 'Postmark'],
        'apifon' => ['label' => 'Apifon'],
        'twilio' => ['label' => 'Twilio'],
        'null_gateway' => ['label' => 'Not sent — no SMS account'],
    ],

    /*
    | The messages this product sends (BKG-13, BKG-16, NTF-7).
    |
    | Every one is transactional. The offsets are in the names because they are
    | the identity: the 48-hour and 24-hour reminders are two different messages
    | with two different dedupe keys.
    */
    /*
    |--------------------------------------------------------------------------
    | Exports (OPS-17, OPS-18)
    |--------------------------------------------------------------------------
    |
    | The date basis labels are the consequential ones. Each names the question
    | the file answers, because an operator choosing between them is choosing
    | which rows are in it.
    */
    'export_type' => [
        'bookings' => ['label' => 'Bookings, for accounting'],
        'guests' => ['label' => 'Passengers'],
    ],

    'export_date_basis' => [
        'booked' => ['label' => 'the date the booking was made'],
        'departure' => ['label' => 'the date of the trip'],
        'paid' => ['label' => 'the date the money arrived'],
    ],

    'export_status' => [
        'queued' => ['label' => 'Waiting'],
        'processing' => ['label' => 'Preparing'],
        'ready' => ['label' => 'Ready'],
        'failed' => ['label' => 'Failed'],
        'expired' => ['label' => 'Expired'],
    ],

    'notification_template' => [
        'booking_confirmed' => ['label' => 'Booking confirmed'],
        'booking_cancelled' => ['label' => 'Booking cancelled'],
        'guest_details_requested' => ['label' => 'Passenger details requested'],
        'guest_details_reminder_48h' => ['label' => 'Passenger details, 48 hours'],
        'guest_details_reminder_24h' => ['label' => 'Passenger details, 24 hours'],
        'balance_due_reminder' => ['label' => 'Balance due'],
        'balance_overdue' => ['label' => 'Balance overdue'],
        'pre_departure_24h' => ['label' => 'Trip tomorrow'],
        'charter_agreement_72h' => ['label' => 'Charter agreement, 72 hours'],
        'charter_agreement_24h' => ['label' => 'Charter agreement, 24 hours'],
        'voucher_expiry_30d' => ['label' => 'Voucher expires in a month'],
        'voucher_expiry_7d' => ['label' => 'Voucher expires in a week'],
        'weather_choice_requested' => ['label' => 'Weather cancellation, choice needed'],
        'weather_choice_reminder' => ['label' => 'Weather cancellation, reminder'],
        'weather_choice_applied' => ['label' => 'Weather cancellation, settled'],
        'quote_sent' => ['label' => 'Quote sent'],
    ],

    'webhook_event' => [
        'booking.confirmed' => ['label' => 'Booking confirmed'],
        'booking.cancelled' => ['label' => 'Booking cancelled'],
        'departure.cancelled' => ['label' => 'Departure cancelled'],
        'guest_details.completed' => ['label' => 'Passenger details completed'],
    ],

    'delivery_status' => [
        'pending' => ['label' => 'Pending'],
        'delivered' => ['label' => 'Delivered'],
        'failed' => ['label' => 'Failed'],
        'abandoned' => ['label' => 'Abandoned'],
    ],

    'failure_source' => [
        'notification' => ['label' => 'Message'],
        'payment' => ['label' => 'Payment'],
        'gateway_webhook' => ['label' => 'Bank'],
        'ical_sync' => ['label' => 'Calendar'],
        'export' => ['label' => 'Export'],
        'outbound_webhook' => ['label' => 'Webhook'],
    ],

];
