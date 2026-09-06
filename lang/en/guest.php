<?php

declare(strict_types=1);

/*
 * The four tokenised guest pages (spec TOK-1 … TOK-13).
 *
 * These are the only strings in the product a **guest** reads while acting on
 * their own booking, and they are written for somebody on a phone who booked
 * three weeks ago and remembers very little about it.
 *
 * Two of them carry more weight than the rest:
 *
 *   link-not-valid  — TOK-4 requires one sentence for every failure, and it
 *                     must not hint at which. It also has to be kind: the guest
 *                     most likely to see it is one whose email client broke a
 *                     long URL across two lines.
 *
 *   documents       — TOK-9 requires the guest-details form to explain, in
 *                     plain language, *why* a passport number is wanted, how
 *                     long it is kept and who sees it. A form that asks without
 *                     explaining is a form people close.
 */

return [

    'common' => [
        'title' => 'Your booking',
        'reference' => 'Reference',
        'back' => 'Back',
        'save' => 'Save',
        'lang' => 'Ελληνικά',
    ],

    'link' => [
        /*
         * One sentence for every failure. TOK-4: never a distinction between
         * "not found" and "expired" — the distinction is an oracle, and the
         * timing of the answer is the same oracle in a slower form.
         */
        'title' => 'This link is not valid',
        'body' => 'We could not open this page. The link may have been copied incompletely, or it may no longer be in use. Please check the most recent email from the operator, or contact them directly.',
    ],

    'throttled' => [
        'title' => 'Too many requests',
        'body' => 'There have been too many attempts from this connection. Please wait a minute and try again.',
    ],

    'booking' => [
        'title' => 'Your booking',
        'when' => 'When',
        'meeting_point' => 'Meeting point',
        'map' => 'Open in maps',
        'check_in' => 'Check-in',
        'party' => 'Party',
        'people' => ':count people',
        'status' => 'Status',
        'price' => [
            'heading' => 'What you paid',
            'total' => 'Total',
            'paid' => 'Paid',
            'balance' => 'Still to pay',
            'refunded' => 'Refunded',
        ],
        'balance_due' => 'The balance is due by :date.',
        'pay_balance' => 'Pay the balance',
        'ticket' => 'Download your ticket',
        'ticket_soon' => 'Your ticket will be available here shortly before departure.',
        'contact' => [
            'heading' => 'Your details',
            'name' => 'Name',
            'email' => 'Email',
            'phone' => 'Phone',
            'save' => 'Update my details',
        ],
        'cancel' => [
            'heading' => 'Cancel this booking',
            'refund' => 'If you cancel now, :amount will be refunded.',
            /*
             * TOK-7. The action is **shown** when the policy gives nothing, it
             * says so plainly, and it still releases the seat — an operator
             * would far rather have the seat back to resell than have a guest
             * quietly not turn up.
             */
            'no_refund' => 'If you cancel now, no refund is due under the terms you accepted. You can still cancel, and the place will go back on sale.',
            'confirm' => 'Cancel my booking',
            'done' => 'This booking has been cancelled.',
            'past' => 'This trip has already departed. Contact the operator if you need to discuss it.',
        ],
        'weather' => [
            'heading' => 'Your trip was cancelled because of the weather',
            'body' => 'The operator called this sailing off. You are owed :amount and it is yours to decide what happens to it. Please choose by :deadline — after that we will apply the operator’s usual choice and let you know.',
            'refund' => 'Refund the money',
            'voucher' => 'Give me a voucher',
            'rebook' => 'I would like to book another date',
            'chosen' => 'Thank you — we have recorded your choice.',
        ],
    ],

    'details' => [
        'title' => 'Passenger details',
        'intro' => 'The operator needs a few details for each person travelling.',
        /*
         * TOK-9, in plain language and in both languages. Three questions, in
         * the order a person actually asks them.
         */
        'why' => [
            'heading' => 'Why we ask for this',
            'manifest' => 'Greek port authorities (Λιμεναρχείο) require a passenger list for every departure, with each passenger’s name and travel document. The operator cannot sail without it.',
            'retention' => 'Document numbers are stored encrypted, are used only for that list, and are deleted automatically after the retention period the operator has set.',
            'who' => 'Only the operator and their crew can see them. They are never shared with anyone else and never used for marketing.',
        ],
        'guest' => 'Passenger :position',
        'full_name' => 'Full name, as on the document',
        'date_of_birth' => 'Date of birth',
        'nationality' => 'Nationality',
        'document_type' => 'Document type',
        'document_number' => 'Document number',
        'document_expires_on' => 'Document expires',
        'partial' => 'You can save what you have and come back later.',
        'complete' => 'Everything the operator needs is here. Thank you.',
        'read_only' => 'This trip has already departed, so these details can no longer be changed.',
        'purged' => 'Document numbers for this booking have been deleted, as the operator’s retention period has passed.',
        'charter_agreement' => [
            'heading' => 'Charter agreement',
            'body' => 'Greek law requires a charter agreement (ναυλοσύμφωνο) for a private charter. By ticking this box you confirm you have read and accept it.',
            'accept' => 'I accept the charter agreement',
            'accepted' => 'Accepted on :date.',
        ],
    ],

    'quote' => [
        'title' => 'Your quote',
        'from' => 'Prepared for :reference',
        'valid_until' => 'Valid until :date',
        'total' => 'Total',
        'deposit' => 'To pay now',
        'accept' => 'Accept and pay',
        'decline' => 'No thank you',
        'decline_reason' => 'If you would like to say why, it helps the operator',
        'expired' => 'This quote has expired.',
        'replaced' => 'This quote was replaced by a newer one. Please use the most recent link the operator sent you.',
        'accepted' => 'You accepted this quote. The operator will be in touch.',
        'declined' => 'You turned this quote down. Thank you for letting the operator know.',
        'unavailable' => 'That date is no longer available. A quote does not reserve the boat, and it has since been booked. Contact the operator and they will find you another date.',
        'request_new' => 'Ask for a new quote',
        'request_new_body' => 'Tell the operator what you have in mind and they will send a new offer.',
    ],

    'voucher' => [
        'title' => 'Your voucher',
        'code' => 'Code',
        'original' => 'Original amount',
        'remaining' => 'Remaining',
        'expires' => 'Valid until',
        'no_expiry' => 'No expiry date',
        'spent' => 'This voucher has been fully used.',
        'expired' => 'This voucher has expired.',
        'how' => 'Enter the code at checkout to use it.',
        'products' => 'Trips you can use it on',
    ],

];
