import { useMemo, useState } from 'preact/hooks';

import type { ApiError } from '../../api-client';
import { registerMount, type MountProps } from '../../mounts';

/**
 * The `enquiry` mount (WGT-5, BKG-24, #85's spam filters).
 *
 * ## The call to action on a trip that has no price
 *
 * A `quote`-mode product is sold by the operator answering a question, so this
 * is what stands where a booking button would be. No price appears here for the
 * same reason it appears nowhere else on such a product.
 *
 * ## The honeypot and the timing field are sent, not invented
 *
 * #85 built both server-side and the contract names them: `company_website`
 * must arrive empty, and `form_rendered_at` is when the client drew the form —
 * a submission less than three seconds later is refused.
 *
 * The honeypot field is **rendered and hidden from people, not from robots**:
 * `aria-hidden`, `tabindex="-1"` and `autocomplete="off"`, positioned off-screen
 * rather than `display: none`, because a form filler that skips hidden inputs
 * skips the trap too. A guest never sees it; a script filling every input fills
 * it.
 *
 * ## A rejection is the API's sentence, not ours
 *
 * The acceptance criterion is explicit: *"a rejection renders the same localised
 * sentence the API returns rather than 'your message failed'."* §4's envelope
 * carries a message in the guest's language, and a widget substituting its own
 * would be replacing a specific answer with a vague one — and would drift from
 * the server's the first time the server's changed.
 */

interface EnquiryFields {
  name: string;
  email: string;
  phone: string;
  preferred_date: string;
  pax: string;
  message: string;
  /** The honeypot. Empty, always, from a person. */
  company_website: string;
}

export function EnquiryMount({ client, productUuid, t, analytics, locale }: MountProps) {
  // Captured when the component first renders, which is what the field means.
  const renderedAt = useMemo(() => new Date().toISOString(), []);

  const [fields, setFields] = useState<EnquiryFields>({
    name: '',
    email: '',
    phone: '',
    preferred_date: '',
    pax: '',
    message: '',
    company_website: '',
  });

  const [state, setState] = useState<'editing' | 'sending' | 'sent'>('editing');
  const [error, setError] = useState<string | null>(null);

  const patch = (values: Partial<EnquiryFields>) => setFields({ ...fields, ...values });

  const submit = async (event: Event): Promise<void> => {
    event.preventDefault();
    setState('sending');
    setError(null);

    try {
      await client.post('/enquiries', {
        product_uuid: productUuid,
        name: fields.name.trim(),
        email: fields.email.trim(),
        phone: fields.phone.trim() === '' ? null : fields.phone.trim(),
        preferred_date: fields.preferred_date === '' ? null : fields.preferred_date,
        pax: fields.pax === '' ? null : Number(fields.pax),
        message: fields.message.trim(),
        locale,
        company_website: fields.company_website,
        form_rendered_at: renderedAt,
      });

      analytics.emit('kaiki:enquiry-submitted', { product_uuid: productUuid ?? undefined });
      setState('sent');
    } catch (caught) {
      // The API's own sentence, in the guest's language (§4.1). Ours only when
      // there was none — a network failure has no envelope to read.
      const message = (caught as { message?: string } | null)?.message ?? '';
      const code = (caught as ApiError | null)?.code ?? null;

      setError(code === 'network_error' || message === '' ? t('widget.error.network') : message);
      setState('editing');
      analytics.emit('kaiki:error', { error_code: code ?? 'enquiry_failed' });
    }
  };

  if (state === 'sent') {
    return (
      <div class="kaiki-step" role="status">
        <p class="kaiki-heading">{t('enquiry.sent.heading')}</p>
        <p class="kaiki-muted">{t('enquiry.sent.body')}</p>
      </div>
    );
  }

  return (
    <form class="kaiki-step" onSubmit={(event) => void submit(event)} noValidate>
      <h3 class="kaiki-heading">{t('enquiry.heading')}</h3>

      {error === null ? null : (
        <p class="kaiki-error" role="alert">
          {error}
        </p>
      )}

      <label class="kaiki-field">
        <span>{t('booking.contact.name')}</span>
        <input type="text" autocomplete="name" required value={fields.name} onInput={(e) => patch({ name: (e.currentTarget as HTMLInputElement).value })} />
      </label>

      <label class="kaiki-field">
        <span>{t('booking.contact.email')}</span>
        <input type="email" autocomplete="email" required value={fields.email} onInput={(e) => patch({ email: (e.currentTarget as HTMLInputElement).value })} />
      </label>

      <label class="kaiki-field">
        <span>{t('booking.contact.phone')}</span>
        <input type="tel" autocomplete="tel" value={fields.phone} onInput={(e) => patch({ phone: (e.currentTarget as HTMLInputElement).value })} />
      </label>

      <label class="kaiki-field">
        <span>{t('enquiry.preferred_date')}</span>
        <input type="date" value={fields.preferred_date} onInput={(e) => patch({ preferred_date: (e.currentTarget as HTMLInputElement).value })} />
      </label>

      <label class="kaiki-field">
        <span>{t('enquiry.pax')}</span>
        <input type="number" min="1" max="500" inputMode="numeric" value={fields.pax} onInput={(e) => patch({ pax: (e.currentTarget as HTMLInputElement).value })} />
      </label>

      <label class="kaiki-field">
        <span>{t('enquiry.message')}</span>
        <textarea rows={4} required value={fields.message} onInput={(e) => patch({ message: (e.currentTarget as HTMLTextAreaElement).value })} />
      </label>

      {/* The honeypot. Off-screen rather than `display: none`, so a form filler
          that skips hidden inputs still fills it — which is the whole point. */}
      <div class="kaiki-trap" aria-hidden="true">
        <label>
          Company website
          <input
            type="text"
            name="company_website"
            tabIndex={-1}
            autocomplete="off"
            value={fields.company_website}
            onInput={(e) => patch({ company_website: (e.currentTarget as HTMLInputElement).value })}
          />
        </label>
      </div>

      <div class="kaiki-actions">
        <button type="submit" class="kaiki-button" disabled={state === 'sending'}>
          {t(state === 'sending' ? 'enquiry.sending' : 'enquiry.submit')}
        </button>
      </div>
    </form>
  );
}

registerMount('enquiry', EnquiryMount);
