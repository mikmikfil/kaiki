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
        'body' => 'Booking :reference for :date has been cancelled. If a refund is due it is on its way; your booking page shows the details.',
        'sms' => 'Booking cancelled.',
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
        'body' => 'Your trip on :date is coming up and we do not yet have the details for every passenger. The operator cannot file the passenger list without them.',
        'sms' => 'Passenger details still needed.',
    ],

    'guest_details_reminder_24h' => [
        'subject' => 'Last call for the passenger details — :reference',
        'heading' => 'Tomorrow, and we still need the details',
        'body' => 'Your trip is on :date at :time. Please add the remaining passenger details today so the operator can file the passenger list.',
        'sms' => 'Last call: passenger details.',
    ],

    'balance_due_reminder' => [
        'subject' => 'Your balance is due — :reference',
        'heading' => 'Time to settle the balance',
        'body' => 'The balance for booking :reference is due before your trip on :date. You can pay it from your booking page.',
        'sms' => 'Balance due.',
    ],

    'balance_overdue' => [
        'subject' => 'Your balance is overdue — :reference',
        'heading' => 'Your balance is overdue',
        'body' => 'The balance for booking :reference has not been paid. Please settle it from your booking page, or contact the operator if something has gone wrong.',
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
        'body' => 'Greek law requires a charter agreement for a private charter. Please read and accept it from your booking page before :date.',
        'sms' => 'Charter agreement to accept.',
    ],

    'charter_agreement_24h' => [
        'subject' => 'Charter agreement still outstanding — :reference',
        'heading' => 'The charter agreement is still outstanding',
        'body' => 'Your charter is on :date and the agreement has not been accepted yet. It takes one click from your booking page.',
        'sms' => 'Charter agreement outstanding.',
    ],

    'voucher_expiry_30d' => [
        'subject' => 'Your voucher expires in a month',
        'heading' => 'Your voucher expires soon',
        'body' => 'You still have credit with us and it expires in about a month. Enter the code at checkout to use it.',
        'sms' => 'Voucher expires in a month.',
    ],

    'voucher_expiry_7d' => [
        'subject' => 'Your voucher expires next week',
        'heading' => 'Your voucher expires next week',
        'body' => 'You still have credit with us and it expires in about a week. Enter the code at checkout to use it.',
        'sms' => 'Voucher expires next week.',
    ],

    'weather_choice_requested' => [
        'subject' => 'Your trip was cancelled because of the weather — :reference',
        'heading' => 'The weather has cancelled your trip',
        'body' => 'The operator has called off your trip on :date. You are owed money and it is yours to decide what happens to it — refund, voucher, or another date. Please choose from your booking page.',
        'sms' => 'Trip cancelled: weather.',
    ],

    'weather_choice_reminder' => [
        'subject' => 'Please tell us what you would like — :reference',
        'heading' => 'We still need your answer',
        'body' => 'Your trip on :date was cancelled because of the weather and we have not heard back. Please choose a refund, a voucher, or another date from your booking page.',
        'sms' => 'Please choose: refund, voucher or new date.',
    ],

    'weather_choice_applied' => [
        'subject' => 'We have settled your cancelled trip — :reference',
        'heading' => 'Your cancelled trip is settled',
        'body' => 'Because we did not hear back about your cancelled trip on :date, we have applied the operator’s usual choice. Your booking page shows what happened.',
        'sms' => 'Cancelled trip settled.',
    ],

    'quote_sent' => [
        'subject' => 'Your quote is ready — :reference',
        'heading' => 'Your quote is ready',
        'body' => 'The operator has prepared a quote for you. It is on your quote page, along with what is included and how long it is valid for.',
        'sms' => 'Your quote is ready.',
    ],

];
