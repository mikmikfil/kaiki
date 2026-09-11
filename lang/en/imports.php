<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Import from WooCommerce / YITH Booking (SAA-13 … SAA-15)
|--------------------------------------------------------------------------
|
| The operator using this is leaving a system they knew for one they do not yet
| know. Every sentence says what will happen before it happens, and every skip
| says why — a "skipped" with no reason is a lost booking.
|
*/

return [

    'nav' => 'Import from WooCommerce',
    'model_singular' => 'Import',
    'model_plural' => 'Imports',
    'title' => 'Import from WooCommerce / YITH',
    'subheading' => 'Bring your trips and upcoming bookings over from WordPress. You see what will happen first; nothing is written until you approve it.',

    'actions' => [
        'new' => 'New import',
        'submit' => 'Read the files',
        'review' => 'Review',
        'save' => 'Save choices',
        'saved' => 'Choices saved. The list below shows what will happen now.',
        'commit' => 'Import',
        'resume' => 'Resume import',
        'commit_heading' => 'Run the import?',
        'commit_body' => ':products trips will be created as drafts and :bookings bookings imported. Guests will not receive any email or text, and no invoice will be issued.',
        'commit_queued' => 'The import has started. This page updates by itself.',
        'queued_title' => 'Reading the files',
        'queued_body' => 'In a few seconds you will see what was found and what is proposed.',
    ],

    'upload' => [
        'wxr' => 'WordPress export (XML)',
        'wxr_help' => 'Tools → Export → Products. Trips, categories and passenger types come from here.',
        'csv' => 'YITH Booking bookings (CSV)',
        'csv_help' => 'Bookings → Export. Only upcoming bookings are imported, not past ones.',
        'one_required' => 'Upload at least one of the two files.',
        'privacy' => 'The files contain customer details. They are kept only as long as the import needs them and deleted when it completes.',
    ],

    'table' => [
        'created' => 'Started',
        'source' => 'Source',
        'status' => 'Status',
        'summary' => 'Contents',
        'by' => 'By',
        'summary_line' => ':products trips, :bookings bookings',
    ],

    'empty' => [
        'heading' => 'No imports yet',
        'body' => 'If you sold trips with WooCommerce and YITH Booking, bring them here with two files from your WordPress.',
    ],

    'review' => [
        'title' => 'Review import',
        'busy' => 'The import is working. This page updates by itself.',
        'products' => 'Trips',
        'products_help' => 'For each WooCommerce trip: create a new one, link it to one you already have, or skip it. New ones are created as drafts.',
        'people_types' => 'Passenger types',
        'people_types_help' => 'How each YITH passenger type maps to an age band in Kaiki.',
        'bookings' => 'Bookings',
        'bookings_help' => 'Bookings from this date onwards are imported. Older ones stay in WooCommerce.',
        'log' => 'What happened to each record',
        'field' => [
            'action' => 'Action',
            'target' => 'Link to',
            'mode' => 'Booking mode',
            'category' => 'Category',
            'vessel' => 'Vessel',
            'band_code' => 'Band code',
            'min_age' => 'From age',
            'max_age' => 'To age',
            'counts' => 'Takes a seat',
            'import_from' => 'Bookings from',
        ],
        'action' => [
            'create' => 'Create',
            'link' => 'Link to existing',
            'skip' => 'Skip',
        ],
        'counts' => ':mapped to import · :skipped skipped · :imported imported · :failed failed',
        'nothing' => 'Nothing in this group.',
    ],

    'default_band' => 'Adult',
    'rate_plan_name' => 'From WooCommerce',
    'unnamed_guest' => 'No name',

    'notes' => [
        'category' => 'Becomes category «:category».',
        'people_type' => 'Becomes age band «:code».',
        'will_create' => 'Will be created as a draft.',
        'will_link' => 'Will be linked to the trip you already have.',
        'created' => 'Created as a draft.',
        'linked' => 'Linked to the trip you already had.',
        'already_imported' => 'Already imported by an earlier import — not created a second time.',
        'booking' => 'Booking for :date at :time.',
        'booking_created' => 'Imported as booking :reference.',
        'departure_created' => 'Departure :date :time created.',
    ],

    'warnings' => [
        'single_language' => 'WooCommerce has the text in one language; it was used for both Greek and English. Check the translation before publishing.',
        'no_price' => 'No price found; add it to the trip before publishing.',
        'price_not_saved' => 'The price was not saved: :detail',
        'duration_guessed' => 'No duration found; :minutes minutes were used. Correct it if needed.',
        'no_email' => 'No customer email.',
        'no_total' => 'No amount found; the booking is imported at zero.',
    ],

    'reasons' => [
        'operator' => 'You chose to skip it.',
        'not_booking' => 'Not a YITH Booking trip (an ordinary shop product).',
        'no_vessel' => 'No vessel chosen. Add a vessel in Kaiki or choose one.',
        'no_link_target' => 'Choose which trip to link it to.',
        'link_target_missing' => 'The trip it was linked to no longer exists.',
        'status' => 'Its status in WooCommerce is «:status».',
        'bad_date' => 'The date «:value» cannot be read.',
        'past' => 'It is for :date, before :from.',
        'unknown_product' => 'The trip «:product» is not in the WordPress file.',
        'product_skipped' => 'The trip «:product» is being skipped.',
        'product_not_imported' => 'The trip «:product» was not imported.',
        'no_pax' => 'It does not say how many people.',
    ],

    'errors' => [
        'wxr_unreadable' => 'The XML file cannot be read. Make sure it is the WordPress export (Tools → Export).',
        'csv_unreadable' => 'The CSV file cannot be read.',
        'csv_columns' => 'The CSV does not have the YITH export columns (ID, Product ID, From). Download it again from Bookings → Export.',
        'file_missing' => 'The uploaded file was not found. Start a new import.',
        'generic' => 'Something went wrong reading the files. Try again, or contact us.',
        'rows_failed' => ':count records were not imported. See the reason beside each, fix it and press «Resume import».',
        'validation' => 'Not accepted: :detail',
        'row' => 'Something went wrong with this record. Press «Resume import» to try it again.',
    ],

    'log' => [
        'analysed' => ':count records read.',
        'started' => 'Import started.',
        'finished' => 'Finished: :imported imported, :failed failed.',
        'files_deleted' => 'The uploaded files were deleted.',
    ],

];
