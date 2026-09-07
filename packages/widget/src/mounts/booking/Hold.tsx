import type { HoldState } from '../../booking/countdown';
import type { Translator } from '../../i18n';

/**
 * The hold countdown (WGT-19).
 *
 * Shown once a draft exists, warning at two minutes. `aria-live="polite"` on the
 * warning and **not** on the ticking number: a screen reader announcing every
 * second would make the widget unusable, and what is worth interrupting for is
 * the change of state rather than the passage of time.
 */
export function Hold({ hold, t }: { readonly hold: HoldState; readonly t: Translator }) {
  if (hold.label === '') {
    return null;
  }

  const warning = hold.phase === 'warning';

  return (
    <p class={warning ? 'kaiki-hold kaiki-hold-warning' : 'kaiki-hold'} aria-live={warning ? 'polite' : 'off'}>
      {t(warning ? 'booking.hold.warning' : 'booking.hold.holding').replace(':time', hold.label)}
    </p>
  );
}
