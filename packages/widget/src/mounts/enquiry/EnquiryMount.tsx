import { useMemo, useRef, useState } from 'preact/hooks';

import type { ApiError } from '../../api-client';
import { registerMount, type MountProps } from '../../mounts';
import type { ProductSummary } from '../booking/BookingMount';
import { FourLines } from '../booking/FourLines';
import { Peek, useSettled, useSheetMode } from '../booking/Sheet';

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
 * ## Two steps (Mike, 2026-09-24, version 3 of docs/mockups/enquiry-form.html)
 *
 * The trip first (the day and how many, the people as − / +), then how to
 * answer (name, email, phone), and «Κάτι ακόμα;» optional. The API still wants
 * a message of five characters or more, so an empty one is sent as a line made
 * from the two choices — «Ερώτηση για την εκδρομή: 27/09/2026, 4 άτομα.» — which
 * is what the operator needs to answer anyway.
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

export interface EnquiryMountProps extends MountProps {
  /**
   * The trip, when a **booking** embed found it was sold by quote.
   *
   * `data-mount="booking"` is what the WordPress shortcode, the Elementor
   * widget and any hand-written embed put on a trip page, and none of them can
   * know the trip's mode without asking the API. The booking mount asks, and on
   * `mode: quote` hands the product here instead of drawing a calendar that
   * could only end in `product_is_quote_only` (BKG-24). With it, the form
   * says «Κατόπιν ζήτησης» and carries the four lines, because on somebody
   * else's page nothing else says which trip is being asked about.
   *
   * Absent on the hosted trip page, which mounts `enquiry` directly and
   * draws the four lines itself — so they are never on screen twice.
   */
  readonly product?: ProductSummary | null;
}

/** `data-date`, when it is a day and not something else. */
function initialPreferredDate(date: string | null | undefined): string {
  return typeof date === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(date) ? date : '';
}

export function EnquiryMount({ client, productUuid, t, analytics, locale, date = null, showVessel = true, showDetails = true, product = null }: EnquiryMountProps) {
  // Captured when the component first renders, which is what the field means.
  const renderedAt = useMemo(() => new Date().toISOString(), []);

  /**
   * A quote trip gets the same bottom sheet, minus the half it cannot have.
   *
   * ADR-0033 is written around a price and a walk, and this mount has neither:
   * BKG-24 forbids a price on a quote product, and there is nothing to step
   * through — it is one form. What it shares is the problem the ADR was raised
   * for. The enquiry form sits in the same aside, at the same bottom of the same
   * page, and a visitor on a phone scrolls past the whole trip to reach it.
   *
   * So the bar carries «Κατόπιν ζήτησης» where a price would be — which is what
   * the page is required to say instead of a number — and opens the form. No
   * summary that changes, because nothing has been chosen; no disabled state,
   * because asking is always allowed.
   */
  const rootRef = useRef<HTMLFormElement>(null);
  const sheet = useSheetMode(rootRef);
  const settled = useSettled(sheet);
  const [open, setOpen] = useState(false);

  const [fields, setFields] = useState<EnquiryFields>({
    name: '',
    email: '',
    phone: '',
    // The day pressed on the operator's calendar, if the embed carried one.
    preferred_date: initialPreferredDate(date),
    pax: '2',
    message: '',
    company_website: '',
  });

  const [state, setState] = useState<'editing' | 'sending' | 'sent'>('editing');
  const [error, setError] = useState<string | null>(null);

  const patch = (values: Partial<EnquiryFields>) => setFields({ ...fields, ...values });

  const paxNumber = Math.max(1, Math.min(500, Number(fields.pax) || 1));
  const stepPax = (by: number) => patch({ pax: String(Math.max(1, Math.min(500, paxNumber + by))) });

  /** The message sent when «Κάτι ακόμα;» is left empty: the two choices, in words. */
  const autoMessage = (): string => {
    const day = /^\d{4}-\d{2}-\d{2}$/.test(fields.preferred_date)
      ? fields.preferred_date.split('-').reverse().join('/')
      : t('enquiry.auto_any_date');

    return t('enquiry.auto_message').replace(':date', day).replace(':pax', String(paxNumber));
  };

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
        message: fields.message.trim().length >= 5 ? fields.message.trim() : [fields.message.trim(), autoMessage()].filter(Boolean).join(' '),
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
      analytics.emit('kaiki:error', { product_uuid: productUuid ?? undefined, error_code: code ?? 'enquiry_failed' });
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
    <>
      {sheet && open ? (
        <button type="button" class="kaiki-scrim" aria-label={t('booking.sheet.close')} onClick={() => setOpen(false)} />
      ) : null}

      <form
        class="kaiki-booking kaiki-step kaiki-enquiry"
        ref={rootRef}
        data-sheet={sheet}
        data-open={sheet && open}
        data-settled={settled}
        onSubmit={(event) => void submit(event)}
        noValidate
      >
        {sheet ? (
          <Peek
            price={t('booking.peek.price_unknown')}
            summary={t('enquiry.peek.summary')}
            action={t('enquiry.submit')}
            ready
            open={open}
            onToggle={() => setOpen((was) => !was)}
          />
        ) : null}

        <div class="kaiki-sheet-scroll">
      {product === null ? null : (
        <>
          <p class="kaiki-on-request">{t('enquiry.on_request')}</p>
          {showDetails ? <FourLines product={product} t={t} showVessel={showVessel} /> : null}
        </>
      )}

      <h3 class="kaiki-heading">{t('enquiry.heading')}</h3>
      <p class="kaiki-muted kaiki-enquiry-sub">{t('enquiry.sub')}</p>

      {error === null ? null : (
        <p class="kaiki-error" role="alert">
          {error}
        </p>
      )}

      <p class="kaiki-enquiry-step"><i aria-hidden="true">1</i>{t('enquiry.step.trip')}</p>
      <div class="kaiki-enquiry-pick">
        <label class="kaiki-enquiry-cell">
          <span>{t('enquiry.date')}</span>
          <input type="date" value={fields.preferred_date} onInput={(e) => patch({ preferred_date: (e.currentTarget as HTMLInputElement).value })} />
        </label>
        <div class="kaiki-enquiry-cell">
          <span id="kaiki-enquiry-pax">{t('enquiry.people')}</span>
          <div class="kaiki-enquiry-stepper">
            <button type="button" aria-label={t('enquiry.fewer')} disabled={paxNumber <= 1} onClick={() => stepPax(-1)}>−</button>
            <input
              type="number"
              min="1"
              max="500"
              inputMode="numeric"
              aria-labelledby="kaiki-enquiry-pax"
              value={fields.pax}
              onInput={(e) => patch({ pax: (e.currentTarget as HTMLInputElement).value })}
            />
            <button type="button" aria-label={t('enquiry.more_people')} disabled={paxNumber >= 500} onClick={() => stepPax(1)}>+</button>
          </div>
        </div>
      </div>

      <p class="kaiki-enquiry-step"><i aria-hidden="true">2</i>{t('enquiry.step.you')}</p>
      <label class="kaiki-field">
        <span>{t('booking.contact.name')}</span>
        <input type="text" autocomplete="name" required value={fields.name} onInput={(e) => patch({ name: (e.currentTarget as HTMLInputElement).value })} />
      </label>

      <div class="kaiki-enquiry-two">
        <label class="kaiki-field">
          <span>{t('booking.contact.email')}</span>
          <input type="email" autocomplete="email" required value={fields.email} onInput={(e) => patch({ email: (e.currentTarget as HTMLInputElement).value })} />
        </label>

        <label class="kaiki-field">
          <span>{t('booking.contact.phone')}</span>
          <input type="tel" autocomplete="tel" value={fields.phone} onInput={(e) => patch({ phone: (e.currentTarget as HTMLInputElement).value })} />
        </label>
      </div>

      <label class="kaiki-field">
        <span>
          {t('enquiry.more')} <em class="kaiki-enquiry-optional">{t('enquiry.optional')}</em>
        </span>
        <textarea rows={2} placeholder={t('enquiry.more_placeholder')} value={fields.message} onInput={(e) => patch({ message: (e.currentTarget as HTMLTextAreaElement).value })} />
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

        </div>

        <div class="kaiki-actions kaiki-enquiry-actions">
          <button type="submit" class="kaiki-button" disabled={state === 'sending'}>
            {t(state === 'sending' ? 'enquiry.sending' : 'enquiry.submit')}
          </button>
          <p class="kaiki-muted kaiki-enquiry-note">{t('enquiry.note')}</p>
        </div>
      </form>
    </>
  );
}

registerMount('enquiry', EnquiryMount);
