<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Exports — the bookings and guests CSVs (spec OPS-17, OPS-18)
|--------------------------------------------------------------------------
|
| The column headers are here rather than in the code because the file itself is
| translated. I18N-1 does not stop at the screen: a CSV is the artefact most
| likely to be forwarded to somebody who never saw the panel — an accountant, a
| harbourmaster — and an English header on a Greek operator's file is a support
| conversation about a file we produced.
|
| The header line is written in the **tenant's** language, not the request's.
| The file outlives the session that asked for it.
*/

return [

    'nav' => 'Exports',
    'model_singular' => 'Export',
    'model_plural' => 'Exports',
    'title' => 'Exports',

    'yes' => 'Yes',
    'no' => 'No',

    'action' => [
        'new' => 'New export',
        'type' => 'What to export',
        'date_basis' => 'Date range measured on',
        'from' => 'From',
        'to' => 'To',
        'statuses' => 'Booking statuses',
        'statuses_help' => 'Leave empty for everything except holds.',
        'product' => 'Trip',
        'vessel' => 'Boat',
        'all' => 'All',
        'submit' => 'Prepare the file',
        'queued_title' => 'Preparing your file',
        'queued_body' => 'It will appear in the list below when it is ready. Large exports take a minute.',
        'download' => 'Download',
        'again' => 'Run again',
    ],

    'table' => [
        'requested' => 'Asked for',
        'by' => 'By',
        'by_system' => 'System',
        'type' => 'Export',
        'window' => 'Range',
        'window_all' => 'Everything',
        'basis' => 'Measured on',
        'status' => 'Status',
        'rows' => 'Rows',
        'size' => 'Size',
        'expires' => 'Link expires',
        'expired' => 'Expired',
        'downloads' => 'Downloads',
        'never_downloaded' => 'Not downloaded',
        'error' => 'What went wrong',
    ],

    'empty' => [
        'heading' => 'No exports yet',
        'body' => 'A bookings CSV is what an accountant asks for. A guests CSV is the head count.',
    ],

    /*
     * Failures, in the operator's own language (NFR-8).
     *
     * Written into the row when the job fails, never rendered from a stack
     * trace. The operator reads a sentence and presses "run again"; the
     * exception is in the structured log, where it is of use to somebody who
     * can act on it.
     */
    'errors' => [
        'generic' => 'The file could not be prepared. Please try again; if it happens twice, tell us.',
        'temporary_file' => 'The server ran out of temporary space while preparing the file. Please try again.',
        'tenant_missing' => 'This export no longer belongs to an active account.',
    ],

    'notice' => [
        /*
         * Said on the form, not in the file. OPS-10's second half is a promise
         * about what is *not* in the CSV, and a promise nobody is told about is
         * one that gets broken by a future feature request for "just one more
         * column".
         */
        'no_documents' => 'Passport and ID numbers are never included. Use the passenger manifest for those.',
        'no_test' => 'Test bookings are left out, and so are unpaid holds.',
        'expiry' => 'The download link works for :hours hours, then the file is deleted.',
    ],

    /*
     * The header line of each file.
     *
     * Keyed by export type so the two files can name the same underlying column
     * differently where the reader differs — an accountant's "Total" and a
     * crew list's "Name" are not the same vocabulary.
     */
    'columns' => [

        'bookings' => [
            'reference' => 'Reference',
            'status' => 'Status',
            'booked_at' => 'Booked at',
            'departure_date' => 'Trip date',
            'departure_time' => 'Trip time',
            'product' => 'Trip',
            'vessel' => 'Boat',
            'guest_name' => 'Guest',
            'guest_email' => 'Email',
            'guest_phone' => 'Phone',
            'pax_total' => 'Passengers',
            'currency' => 'Currency',
            'subtotal' => 'Subtotal',
            'extras' => 'Extras',
            'discount' => 'Discount',
            'total' => 'Total',
            'vat_rate' => 'VAT rate %',
            'vat' => 'VAT',
            'paid' => 'Paid',
            'refunded' => 'Refunded',
            'balance' => 'Balance',
            'source' => 'Source',
            'cancelled_at' => 'Cancelled at',
            'cancel_reason' => 'Cancellation reason',
        ],

        'guests' => [
            'reference' => 'Booking',
            'departure_date' => 'Trip date',
            'product' => 'Trip',
            'vessel' => 'Boat',
            'position' => 'No.',
            'full_name' => 'Name',
            'date_of_birth' => 'Date of birth',
            'nationality' => 'Nationality',
            'age_band' => 'Age band',
            'counts_toward_capacity' => 'Takes a seat',
            'checked_in_at' => 'Checked in',
            'no_show' => 'No show',
        ],

    ],

];
