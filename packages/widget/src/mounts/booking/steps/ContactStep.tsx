import type { BookingState } from '../../../booking/machine';
import type { Translator } from '../../../i18n';

/**
 * Step four: who, and the consent.
 *
 * ## The consent is a checkbox with a sentence, not a tick with a link
 *
 * `terms_accepted` records that the guest **saw the cancellation policy and the
 * terms before paying** (§3.4), and a booking is the moment that record is
 * worth something — a refund argument three weeks later is settled by it. So the
 * label names the policy rather than gesturing at it.
 *
 * ## Nothing here is validated cleverly
 *
 * The email pattern is deliberately loose and the phone is not checked at all.
 * The server validates properly, and a widget that refuses a valid address it
 * does not recognise is worse than one that lets the server say so — in the
 * guest's own language, through the error envelope of §4.
 */
export function ContactStep({
  state,
  t,
  onChange,
}: {
  readonly state: BookingState;
  readonly t: Translator;
  readonly onChange: (patch: Partial<BookingState>) => void;
}) {
  const guest = state.guest;
  const patch = (fields: Partial<BookingState['guest']>) => onChange({ guest: { ...guest, ...fields } });

  return (
    <div class="kaiki-step">
      <h3 class="kaiki-heading">{t('booking.contact.heading')}</h3>

      <label class="kaiki-field">
        <span>{t('booking.contact.name')}</span>
        <input
          type="text"
          autocomplete="name"
          required
          value={guest.full_name}
          onInput={(event) => patch({ full_name: (event.currentTarget as HTMLInputElement).value })}
        />
      </label>

      <label class="kaiki-field">
        <span>{t('booking.contact.email')}</span>
        <input
          type="email"
          autocomplete="email"
          required
          value={guest.email}
          onInput={(event) => patch({ email: (event.currentTarget as HTMLInputElement).value })}
        />
      </label>

      <label class="kaiki-field">
        <span>{t('booking.contact.phone')}</span>
        <input
          type="tel"
          autocomplete="tel"
          value={guest.phone}
          onInput={(event) => patch({ phone: (event.currentTarget as HTMLInputElement).value })}
        />
      </label>

      <label class="kaiki-field">
        <span>{t('booking.contact.requests')}</span>
        <textarea
          rows={3}
          value={guest.special_requests}
          onInput={(event) => patch({ special_requests: (event.currentTarget as HTMLTextAreaElement).value })}
        />
      </label>

      <label class="kaiki-field kaiki-consent">
        <input
          type="checkbox"
          checked={guest.terms_accepted}
          onChange={(event) => patch({ terms_accepted: (event.currentTarget as HTMLInputElement).checked })}
        />
        <span>{t('booking.contact.terms')}</span>
      </label>
    </div>
  );
}
