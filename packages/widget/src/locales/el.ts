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
};
