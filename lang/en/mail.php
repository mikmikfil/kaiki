<?php

declare(strict_types=1);

/*
 * Every transactional message this product sends (spec NTF-1, NTF-4, NTF-7).
 *
 * One block per `NotificationTemplate` case, each with a `subject`, a `heading`
 * and a `body`. The enum's value is the key, so a template that exists in code
 * and not here fails the I18N-3 parity gate rather than sending an empty
 * subject line.
 *
 * **Nothing here is marketing** (NTF-7). Every message exists because a guest
 * has to do something or be somewhere, and the footer says so — an operator's
 * own marketing is out of scope for this platform entirely.
 *
 * The `sms` lines are deliberately short: NTF-5 caps a message at two segments,
 * and Greek falls back to UCS-2 at seventy characters a segment. A sentence
 * that reads well in English and runs to three segments in Greek costs the
 * operator half as much again for the same message.
 */

return [

    'common' => [
        'reference' => 'Booking',
        'when' => 'When',
        'meeting_point' => 'Meeting point',
        'balance' => 'Still to pay',
        'open_booking' => 'Open my booking',
        'transactional' => 'You are receiving this because you have a booking with us. We never send marketing email.',

        // The whole trip, design A «Κάρτα εισιτηρίου» (2026-09-17).
        'greeting' => 'Hello :name,',
        'check_in' => 'Check-in',
        'departure' => 'Departure',
        'return' => 'Back',
        'ticket' => 'Your ticket',
        'ticket_help' => 'Show it on your phone when boarding.',
        'open_ticket' => 'Open ticket',
        'manage_booking' => 'Manage booking',

        // The QR codes inside the email (2026-09-23), one per passenger.
        'boarding_heading' => 'Boarding codes',
        'boarding_help' => 'Show it to the crew when you board.|One code per passenger. Show them to the crew when you board.',
        'boarding_alt' => 'Boarding QR code for :name',
        'boarding_text' => 'The boarding QR code is in the full (HTML) version of this email and on your ticket.|The boarding QR codes, one per passenger, are in the full (HTML) version of this email and on your ticket.',
        // Only when the PDF really was attached (2026-09-23).
        'ticket_attached' => 'The ticket is also attached as a PDF.',

        // Add to calendar (2026-09-18). ".ics" is in the label on purpose: it is
        // the word somebody who has met a calendar file before recognises, and
        // the app names tell everybody else which of the two is theirs.
        'add_to_calendar' => 'Add to calendar',
        'calendar_ics' => 'Apple, Outlook and phone (.ics)',
        'calendar_google' => 'Google Calendar',
        'how_to_find' => 'How to find us',
        'open_map' => 'Open in maps',
        'details_title' => 'We still need the passenger details',
        'details_body' => 'The port authority asks for every passenger\'s details before departure.',
        'details_by' => 'Please send them by :date.',
        'details_button' => 'Fill in the details',
        'party' => 'People and payment',
        'discount' => 'Discount',
        'total' => 'Total',
        'paid' => 'Paid',
        'balance_due' => 'Balance, due :date',
        'refunded' => 'Refunded',
        'on_request' => 'On request',
        'bring' => 'What to bring',
        'cancellation' => 'Cancellation',
        'full_policy' => 'Full policy',
        'contact' => 'Contact',
        'leave_review' => 'Write a Google review',

        // The facts under the card, per message (email review, 2026-09-17).
        'refund_method' => 'Refunded to',
        'refund_to_card' => 'The payment method you used',
        // When part was paid in cash or by transfer (2026-09-23): that part does not go back on its own.
        'refund_card_part' => ':amount to the card you used',
        'refund_by_hand' => ':amount we will return to you ourselves, the way you paid it (cash or transfer)',
        'refund_to_voucher' => 'A voucher, code :code',
        'details_by_label' => 'Details due by',
        'charter_by' => 'Accept by',
        'charter_button' => 'Accept the charter agreement',
        'balance_to_pay' => 'Left to pay',
        'balance_due_by' => 'Due by',
        'balance_was_due' => 'Was due',
        'pay_balance' => 'Pay the balance',
        'weather_amount' => 'Owed to you',
        'weather_by' => 'Choose by',
        'weather_button' => 'Make your choice',
        'weather_done' => 'What we did',
        'weather_refund' => 'Money back',
        'weather_voucher' => 'A voucher for a new booking',
        'weather_rebook' => 'Credit for a new date',
        'amount' => 'Amount',
        'voucher' => 'Voucher',
        'voucher_code' => 'Voucher code',
        'voucher_remaining' => 'Available',
        'voucher_expires' => 'Expires',
        'voucher_how' => 'Pick a trip and type the code in the «Code» field when you complete the booking. The amount comes off automatically.',
        'use_voucher' => 'Book a trip with the voucher',
        'quote_items' => 'What the quote includes',
        'quote_valid_until' => 'Valid until',
        'quote_deposit' => 'Deposit to confirm',
        'discount' => 'Discount',
        'view_quote' => 'View the quote',
    ],

    'booking_confirmed' => [
        'subject' => 'Your booking is confirmed — :reference',
        'heading' => 'You are booked',
        'body' => 'Thank you :name. Your booking :reference is confirmed for :date at :time. Everything you need is on your booking page, including the meeting point and what to bring.',
        'sms' => 'Booking confirmed.',
    ],

    'booking_cancelled' => [
        'subject' => 'Your booking has been cancelled — :reference',
        'heading' => 'Your booking has been cancelled',
        'body' => 'Booking :reference for :date has been cancelled. If a refund applies, it is shown below.',
        'sms' => 'Booking cancelled.',
    ],

    'booking_changed' => [
        'subject' => 'Your booking has changed — :reference',
        'heading' => 'Your booking has changed',
        'body' => 'Booking :reference for :date at :time has been updated. The new party, the new total and your tickets are on your booking page.',
        'sms' => 'Booking changed.',
    ],

    'guest_details_requested' => [
        'subject' => 'We need the passenger details — :reference',
        'heading' => 'A few details for each passenger',
        'body' => 'Greek port authorities require a passenger list for every departure. Please add each passenger’s name and travel document before you sail — it takes a couple of minutes.',
        'sms' => 'Passenger details needed.',
    ],

    'guest_details_reminder_48h' => [
        'subject' => 'Passenger details still needed — :reference',
        'heading' => 'We still need the passenger details',
        'body' => 'Your trip on :date is getting close and we still do not have every passenger’s details. Without them :operator cannot file the passenger list.',
        'sms' => 'Passenger details still needed.',
    ],

    'guest_details_reminder_24h' => [
        'subject' => 'Last call for the passenger details — :reference',
        'heading' => 'Tomorrow, and we still need the details',
        'body' => 'Your trip is on :date at :time. Please fill in the missing details today so :operator can file the passenger list.',
        'sms' => 'Last call: passenger details.',
    ],

    'balance_due_reminder' => [
        'subject' => 'Your balance is due — :reference',
        'heading' => 'Time to settle the balance',
        'body' => 'The balance of booking :reference is due by :deadline, before your trip on :date. You can pay it by card from your booking page.',
        'sms' => 'Balance due.',
    ],

    'balance_overdue' => [
        'subject' => 'Your balance is overdue — :reference',
        'heading' => 'Your balance is overdue',
        'body' => 'The balance of booking :reference has not been paid. Please pay it from your booking page, or contact :operator if something went wrong.',
        'sms' => 'Balance overdue.',
    ],

    'pre_departure_24h' => [
        'subject' => 'Tomorrow: your trip — :reference',
        'heading' => 'See you tomorrow',
        'body' => 'Your trip is tomorrow, :date, at :time. The meeting point and check-in time are on your booking page, along with what to bring.',
        'sms' => 'Your trip is tomorrow.',
    ],

    'charter_agreement_72h' => [
        'subject' => 'Please accept the charter agreement — :reference',
        'heading' => 'One document to accept',
        'body' => 'A private charter needs a signed charter agreement by law. Please read and accept it, together with the passenger details, by :deadline.',
        'sms' => 'Charter agreement to accept.',
    ],

    'charter_agreement_24h' => [
        'subject' => 'Charter agreement still outstanding — :reference',
        'heading' => 'The charter agreement is still outstanding',
        'body' => 'Your charter is on :date and the agreement has not been accepted yet. It takes one click, on the passenger details form.',
        'sms' => 'Charter agreement outstanding.',
    ],

    'voucher_expiry_30d' => [
        'subject' => 'Your voucher expires in a month',
        'heading' => 'Your voucher expires soon',
        'body' => 'You still have credit with :operator, and it expires in about a month.',
        'sms' => 'Voucher expires in a month.',
    ],

    'voucher_expiry_7d' => [
        'subject' => 'Your voucher expires next week',
        'heading' => 'Your voucher expires next week',
        'body' => 'You still have credit with :operator, and it expires in about a week.',
        'sms' => 'Voucher expires next week.',
    ],

    'weather_choice_requested' => [
        'subject' => 'Your trip was cancelled because of the weather — :reference',
        'heading' => 'The weather has cancelled your trip',
        'body' => ':operator cancelled your trip on :date because of the weather. You are owed money and you decide what happens to it — a refund, a voucher, or another date.',
        'sms' => 'Trip cancelled: weather.',
    ],

    'weather_choice_reminder' => [
        'subject' => 'Please tell us what you would like — :reference',
        'heading' => 'We still need your answer',
        'body' => 'Your trip on :date was cancelled because of the weather and :operator has not heard from you yet. Choose a refund, a voucher, or another date.',
        'sms' => 'Please choose: refund, voucher or new date.',
    ],

    'weather_choice_applied' => [
        'subject' => 'We have settled your cancelled trip — :reference',
        'heading' => 'Your cancelled trip is settled',
        'body' => 'We did not hear back about your cancelled trip on :date, so we applied :operator’s usual choice.',
        'sms' => 'Cancelled trip settled.',
    ],

    'quote_sent' => [
        'subject' => 'Your quote is ready — :reference',
        'heading' => 'Your quote is ready',
        'body' => ':operator has prepared a quote for you. See what it includes and accept it on the quote page.',
        'sms' => 'Your quote is ready.',
    ],

    'review_request' => [
        'subject' => 'How was your trip?',
        'heading' => 'How was your trip?',
        'body' => 'We hope you had a lovely day on :date. If you enjoyed it, a short Google review helps other travellers find us.',
        'sms' => 'How was your trip?',
    ],

];
