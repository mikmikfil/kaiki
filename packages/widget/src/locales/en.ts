/**
 * The English strings, compiled into the bundle (WGT-14).
 *
 * Compiled rather than fetched: a second request for a translation file is a
 * request the host page's Content-Security-Policy may refuse, and a widget that
 * renders in the wrong language because a fetch failed is worse than one that is
 * a kilobyte larger. The 80 KB budget (WGT-2) is what makes that a decision
 * rather than a shrug — two locales of flat strings cost about 1 KB gzipped.
 *
 * **This file is the key registry.** `el.ts` is checked against it by
 * `npm run widget:guards`, which fails the build when a key exists in one
 * locale and not the other — WGT-14's "fails the build in CI".
 */
export const en = {
  'widget.loading': 'Loading…',
  'widget.retry': 'Try again',
  'widget.error.network': 'We could not reach the booking system. Please try again.',
  'widget.error.generic': 'Something went wrong. Please try again.',
  'widget.error.unavailable': 'Online booking is unavailable just now.',
  'widget.error.contact': 'Please contact us and we will take your booking directly.',
  'widget.mount.missing': 'This booking module is not available yet.',
  'widget.powered_by': 'Powered by Kaiki',

  // The booking mount (#107, WGT-18 … WGT-20).
  'booking.next': 'Continue',
  'booking.back': 'Back',
  'booking.pay': 'Pay and confirm',
  'booking.submitting': 'One moment…',
  'booking.lines.trip': 'Trip',
  'booking.lines.duration': 'Duration',
  'booking.lines.minutes': ':minutes minutes',
  'booking.lines.port': 'Departs from',
  'booking.lines.vessel': 'Boat',
  'booking.date.heading': 'Pick a date',
  'booking.date.label': 'Date',
  'booking.party.heading': 'How many of you?',
  'booking.extras.heading': 'Anything else?',
  'booking.contact.heading': 'Your details',
  'booking.contact.name': 'Full name',
  'booking.contact.email': 'Email',
  'booking.contact.phone': 'Phone',
  'booking.contact.requests': 'Anything we should know',
  'booking.contact.terms': 'I have read the cancellation policy and the terms.',
  'booking.review.heading': 'Check and pay',
  'booking.review.when': 'When',
  'booking.review.who': 'Lead guest',
  'booking.review.pending': 'Working out your price…',
  'booking.review.vat_included': 'VAT is included in the price',
  'booking.review.deposit': 'You pay :amount now; the rest before departure.',
  'booking.hold.holding': 'Your seats are held for :time',
  'booking.hold.warning': 'Only :time left to finish',
  'booking.confirmed.heading': 'You are booked',
  'booking.confirmed.body': 'We have emailed your ticket and the meeting point.',
  'booking.pending.heading': 'Payment received',
  'booking.pending.body': 'We are waiting for your bank to confirm. You will have an email within a few minutes.',
  'booking.sold_out.heading': 'Those seats have just gone',
  'booking.sold_out.body': 'Somebody booked them while you were deciding. Another date may be free.',
  'booking.sold_out.retry': 'Pick another date',
  'booking.expired.heading': 'We could not hold your seats any longer',
  'booking.expired.body': 'The hold ran out, so the seats went back. Nothing was charged — start again and they may still be there.',
  'booking.expired.retry': 'Start again',
} as const;

export type MessageKey = keyof typeof en;
