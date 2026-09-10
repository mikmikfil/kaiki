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
  // The last button in the walk. It says where it goes, because it leaves
  // this page for the operator's own checkout (ADR-0030).
  'booking.checkout': 'Continue to checkout',
  'booking.resume': 'You have a booking waiting to be paid for — finish it',
  'booking.back': 'Back',
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
  // The three contact labels the *enquiry* mount uses (BKG-24). The booking
  // walk stopped asking for these when the checkout page took them over
  // (ADR-0030); a quote request has no checkout page to hand off to, so it
  // still asks here.
  'booking.contact.name': 'Full name',
  'booking.contact.email': 'Email',
  'booking.contact.phone': 'Phone',
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

  // The list, calendar and enquiry mounts (issue 108).
  'list.from': 'from',
  'list.all': 'All trips',
  'list.categories': 'Kinds of trip',
  'list.on_request': 'Price on request',
  'list.empty': 'There are no trips on sale at the moment.',
  'calendar.previous': 'Previous',
  'calendar.next': 'Next',
  'calendar.status.available': 'Available',
  'calendar.status.sold_out': 'Sold out',
  'calendar.status.unavailable': 'Not sailing',
  'calendar.status.on_request': 'On request',
  'calendar.status.past': 'Past',
  'enquiry.heading': 'Ask us about this trip',
  'enquiry.preferred_date': 'Preferred date',
  'enquiry.pax': 'How many of you',
  'enquiry.message': 'Your message',
  'enquiry.submit': 'Send',
  'enquiry.sending': 'Sending…',
  'enquiry.sent.heading': 'Thank you — your message is with us',
  'enquiry.sent.body': 'We answer within a day, usually sooner.',
  'enums.category.shared_full_day': 'Full-day cruise',
  'enums.category.shared_half_day': 'Half-day cruise',
  'enums.category.private_full_day': 'Private full day',
  'enums.category.private_half_day': 'Private half day',
  'enums.category.sunset': 'Sunset',
  'enums.category.custom': 'Bespoke',
} as const;

export type MessageKey = keyof typeof en;
