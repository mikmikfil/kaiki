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
  // In English in both locales, by the product owner's choice (2026-09-11):
  // it is a brand line, and the brand's own words.
  'widget.powered_by': 'Powered by Kaiki',

  // Το μπλοκ κράτησης (#107).
  'booking.next': 'Συνέχεια',
  // Το τελευταίο κουμπί της διαδρομής. Λέει πού πάει, γιατί φεύγει από
  // αυτήν τη σελίδα για το checkout του διοργανωτή (ADR-0030).
  'booking.checkout': 'Συνέχεια στην κράτηση',
  'booking.resume': 'Έχετε μια κράτηση που περιμένει πληρωμή — ολοκληρώστε την',
  'booking.back': 'Πίσω',
  'booking.submitting': 'Μια στιγμή…',
  'booking.lines.trip': 'Εκδρομή',
  'booking.lines.duration': 'Διάρκεια',
  'booking.lines.minutes': ':minutes λεπτά',
  'booking.lines.hour_one': '1 ώρα',
  'booking.lines.hours': ':hours ώρες',
  'booking.lines.hour_one_minutes': '1 ώρα :minutes λεπτά',
  'booking.lines.hours_minutes': ':hours ώρες :minutes λεπτά',
  'booking.lines.port': 'Αναχώρηση από',
  'booking.lines.vessel': 'Σκάφος',
  'booking.date.heading': 'Διαλέξτε ημερομηνία',
  'booking.date.label': 'Ημερομηνία',
  'booking.date.which_departure': 'Διαλέξτε ώρα αναχώρησης',
  'booking.date.departure_time': 'Ώρα αναχώρησης',
  'booking.date.guaranteed': 'Εγγυημένη',
  'booking.party.heading': 'Πόσα άτομα είστε;',
  'booking.party.more': 'Ένα παραπάνω',
  'booking.party.fewer': 'Ένα λιγότερο',
  'booking.party.change': 'Αλλαγή',
  'booking.discount.label': 'Κωδικός έκπτωσης (αν έχετε)',
  'booking.discount.refused': 'Αυτός ο κωδικός δεν ισχύει για αυτή την κράτηση.',
  'booking.peek.from': 'από :amount',
  'booking.peek.price_unknown': 'Κατόπιν ζήτησης',
  'booking.peek.pick_date': 'Διαλέξτε ημερομηνία',
  'booking.peek.people_one': 'ένα άτομο',
  'booking.peek.people_many': ':count άτομα',
  // «€X τώρα, €Y αργότερα» κάτω από το σύνολο, όταν υπάρχει προκαταβολή.
  // Τα ποσά έρχονται μορφοποιημένα από τον server.
  'booking.split.later': ':now τώρα, :later αργότερα',
  'booking.split.on_board': ':now τώρα, :later στο σκάφος',
  'booking.sheet.close': 'Κλείσιμο',
  'enquiry.peek.summary': 'Απαντάμε με προσφορά',
  // Μόνο όταν οι θέσεις σώνονται. Μια αναχώρηση με έντεκα ελεύθερες δεν έχει
  // λόγο να το λέει — πίεση χωρίς πληροφορία.
  'booking.party.seats_left_one': 'Μένει μία θέση.',
  'booking.party.seats_left_many': 'Μένουν :count θέσεις.',
  'booking.party.seats_full': 'Πήρατε όλες τις θέσεις που μένουν.',
  'booking.extras.heading': 'Θέλετε κάτι ακόμη;',
  'booking.extras.required': 'περιλαμβάνεται υποχρεωτικά',
  // Οι τρεις ετικέτες στοιχείων που χρησιμοποιεί το μπλοκ *αιτήματος*
  // (BKG-24). Η διαδρομή κράτησης σταμάτησε να τις ζητά όταν τις ανέλαβε η
  // σελίδα checkout (ADR-0030)· ένα αίτημα προσφοράς δεν έχει checkout να
  // παραδώσει, οπότε ρωτά εδώ.
  'booking.contact.name': 'Ονοματεπώνυμο',
  'booking.contact.email': 'Email',
  'booking.contact.phone': 'Τηλέφωνο',
  'booking.hold.holding': 'Οι θέσεις σας κρατούνται για :time',
  'booking.hold.warning': 'Μένουν μόνο :time για να ολοκληρώσετε',
  'booking.confirmed.heading': 'Η κράτηση έγινε',
  'booking.confirmed.body': 'Στείλαμε με email το εισιτήριο και το σημείο συνάντησης.',
  'booking.pending.heading': 'Εκκρεμεί η επιβεβαίωση',
  'booking.pending.body': 'Αν πληρώσατε, περιμένουμε την επιβεβαίωση της τράπεζας και θα έχετε email μέσα σε λίγα λεπτά. Αν δεν προλάβατε να ολοκληρώσετε, συνεχίστε από εδώ.',
  'booking.pending.resume': 'Συνεχίστε την πληρωμή',
  'booking.pending.dismiss': 'Δεν θέλω να συνεχίσω',
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
  'calendar.status.not_operating': 'Δεν εκτελείται',
  'calendar.status.on_request': 'Κατόπιν ζήτησης',
  'calendar.status.past': 'Πέρασε',
  'enquiry.on_request': 'Κατόπιν ζήτησης',
  'enquiry.heading': 'Ρωτήστε μας για την εκδρομή',
  'enquiry.preferred_date': 'Ημερομηνία προτίμησης',
  'enquiry.pax': 'Πόσα άτομα',
  'enquiry.message': 'Το μήνυμά σας',
  'enquiry.submit': 'Στείλτε την ερώτηση →',
  'enquiry.sub': 'Δύο βήματα, ένα λεπτό.',
  'enquiry.date': 'Ημερομηνία',
  'enquiry.people': 'Άτομα',
  'enquiry.fewer': 'Λιγότερα άτομα',
  'enquiry.more_people': 'Περισσότερα άτομα',
  'enquiry.more': 'Κάτι ακόμα;',
  'enquiry.optional': '(προαιρετικό)',
  'enquiry.more_placeholder': 'Γενέθλια, πρόταση γάμου, πού θα θέλατε να πάμε…',
  'enquiry.note': 'Χωρίς δέσμευση. Σας απαντάμε μέσα στη μέρα.',
  'enquiry.auto_message': 'Ερώτηση για την εκδρομή: :date, :pax άτομα.',
  'enquiry.auto_any_date': 'όποια μέρα',
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
