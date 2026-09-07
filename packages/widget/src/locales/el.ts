/**
 * The Greek strings (WGT-14, I18N-1).
 *
 * Every key in `en.ts` and no others — `npm run widget:guards` fails the build
 * on a key that exists here and not there, or the other way round. A widget that
 * falls back to English for one label is a widget an operator's guests notice
 * before the operator does.
 *
 * Nothing here is upper-cased (I18N-2): Greek capitals drop their accents.
 */
import type { MessageKey } from './en';

export const el: Record<MessageKey, string> = {
  'widget.loading': 'Φόρτωση…',
  'widget.retry': 'Δοκιμάστε ξανά',
  'widget.error.network': 'Δεν μπορέσαμε να συνδεθούμε με το σύστημα κρατήσεων. Δοκιμάστε ξανά.',
  'widget.error.generic': 'Κάτι πήγε στραβά. Δοκιμάστε ξανά.',
  'widget.error.unavailable': 'Η online κράτηση δεν είναι διαθέσιμη αυτή τη στιγμή.',
  'widget.error.contact': 'Επικοινωνήστε μαζί μας και θα κλείσουμε την κράτησή σας απευθείας.',
  'widget.mount.missing': 'Αυτή η ενότητα κράτησης δεν είναι ακόμη διαθέσιμη.',
  'widget.powered_by': 'Με την τεχνολογία του Kaiki',

  // Το μπλοκ κράτησης (#107).
  'booking.next': 'Συνέχεια',
  'booking.back': 'Πίσω',
  'booking.pay': 'Πληρωμή και κράτηση',
  'booking.submitting': 'Μια στιγμή…',
  'booking.lines.trip': 'Εκδρομή',
  'booking.lines.duration': 'Διάρκεια',
  'booking.lines.minutes': ':minutes λεπτά',
  'booking.lines.port': 'Αναχώρηση από',
  'booking.lines.vessel': 'Σκάφος',
  'booking.date.heading': 'Διαλέξτε ημερομηνία',
  'booking.date.label': 'Ημερομηνία',
  'booking.party.heading': 'Πόσα άτομα είστε;',
  'booking.extras.heading': 'Θέλετε κάτι ακόμη;',
  'booking.contact.heading': 'Τα στοιχεία σας',
  'booking.contact.name': 'Ονοματεπώνυμο',
  'booking.contact.email': 'Email',
  'booking.contact.phone': 'Τηλέφωνο',
  'booking.contact.requests': 'Κάτι που πρέπει να ξέρουμε',
  'booking.contact.terms': 'Διάβασα την πολιτική ακύρωσης και τους όρους.',
  'booking.review.heading': 'Έλεγχος και πληρωμή',
  'booking.review.when': 'Πότε',
  'booking.review.who': 'Επικεφαλής κράτησης',
  'booking.review.pending': 'Υπολογίζουμε την τιμή σας…',
  'booking.review.vat_included': 'Στην τιμή περιλαμβάνεται ΦΠΑ',
  'booking.review.deposit': 'Πληρώνετε :amount τώρα και τα υπόλοιπα πριν την αναχώρηση.',
  'booking.hold.holding': 'Οι θέσεις σας κρατούνται για :time',
  'booking.hold.warning': 'Μένουν μόνο :time για να ολοκληρώσετε',
  'booking.confirmed.heading': 'Η κράτηση έγινε',
  'booking.confirmed.body': 'Στείλαμε με email το εισιτήριο και το σημείο συνάντησης.',
  'booking.pending.heading': 'Η πληρωμή στάλθηκε',
  'booking.pending.body': 'Περιμένουμε επιβεβαίωση από την τράπεζα. Θα έχετε email μέσα σε λίγα λεπτά.',
  'booking.sold_out.heading': 'Οι θέσεις μόλις εξαντλήθηκαν',
  'booking.sold_out.body': 'Κάποιος τις έκλεισε όσο αποφασίζατε. Ίσως υπάρχει άλλη ημερομηνία.',
  'booking.sold_out.retry': 'Άλλη ημερομηνία',
  'booking.expired.heading': 'Δεν μπορέσαμε να κρατήσουμε άλλο τις θέσεις',
  'booking.expired.body': 'Η κράτηση θέσεων έληξε και οι θέσεις επέστρεψαν. Δεν χρεωθήκατε — ξεκινήστε ξανά και ίσως είναι ακόμη εκεί.',
  'booking.expired.retry': 'Ξεκινήστε ξανά',

  // Τα μπλοκ λίστας, ημερολογίου και ερωτήματος (issue 108).
  'list.from': 'από',
  'list.all': 'Όλες οι εκδρομές',
  'list.categories': 'Είδη εκδρομής',
  'list.on_request': 'Τιμή κατόπιν ζήτησης',
  'list.empty': 'Δεν υπάρχουν διαθέσιμες εκδρομές αυτή τη στιγμή.',
  'calendar.previous': 'Προηγούμενος',
  'calendar.next': 'Επόμενος',
  'calendar.status.available': 'Διαθέσιμη',
  'calendar.status.sold_out': 'Εξαντλήθηκε',
  'calendar.status.unavailable': 'Δεν εκτελείται',
  'calendar.status.on_request': 'Κατόπιν ζήτησης',
  'calendar.status.past': 'Πέρασε',
  'enquiry.heading': 'Ρωτήστε μας για την εκδρομή',
  'enquiry.preferred_date': 'Ημερομηνία προτίμησης',
  'enquiry.pax': 'Πόσα άτομα',
  'enquiry.message': 'Το μήνυμά σας',
  'enquiry.submit': 'Αποστολή',
  'enquiry.sending': 'Αποστολή…',
  'enquiry.sent.heading': 'Ευχαριστούμε — λάβαμε το μήνυμά σας',
  'enquiry.sent.body': 'Απαντάμε μέσα σε μία ημέρα, συνήθως νωρίτερα.',
  'enums.category.shared_full_day': 'Ημερήσια κρουαζιέρα',
  'enums.category.shared_half_day': 'Ημικρουαζιέρα',
  'enums.category.private_full_day': 'Ιδιωτική ημερήσια',
  'enums.category.private_half_day': 'Ιδιωτική μισής ημέρας',
  'enums.category.sunset': 'Ηλιοβασίλεμα',
  'enums.category.custom': 'Κατά παραγγελία',
};
