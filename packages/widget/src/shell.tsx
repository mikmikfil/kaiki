import type { Analytics } from './analytics';
import type { Api } from './api-client';
import type { MountName } from './config';
import type { Translator } from './i18n';
import { resolveMount } from './mounts';

/**
 * What the widget renders around a mount, and instead of one when it cannot.
 *
 * ## There is no blank state
 *
 * WGT-16: *"every failure renders an inline localised error state with a retry
 * action, never a blank widget."* An empty box on an operator's page is the
 * worst of the three possible outcomes — a guest cannot tell it from a broken
 * site, and the operator hears about it from the guest rather than from us.
 *
 * So the shell has exactly three states and all of them say something: the
 * mount, an error with a retry button, and — when a bundle has no such mount
 * registered — a sentence and the operator's own contact route, which is the
 * same fallback the hosted pages show when scripts are blocked.
 */

interface ShellProps {
  readonly mount: MountName;
  readonly productUuid: string | null;
  readonly category: string | null;
  readonly t: Translator;
  readonly poweredBy: boolean;
  readonly client: Api;
  readonly analytics: Analytics;
  readonly locale: string;
}

export function Shell({ mount, productUuid, category, t, poweredBy, client, analytics, locale }: ShellProps) {
  const Mount = resolveMount(mount);

  return (
    <div class="kaiki-shell">
      {Mount === null ? (
        <MissingMount t={t} />
      ) : (
        <Mount
          productUuid={productUuid}
          category={category}
          client={client}
          t={t}
          analytics={analytics}
          locale={locale}
        />
      )}

      {poweredBy ? <p class="kaiki-footer">{t('widget.powered_by')}</p> : null}
    </div>
  );
}

function MissingMount({ t }: { readonly t: Translator }) {
  return (
    <div class="kaiki-error" role="status">
      <p class="kaiki-heading">{t('widget.error.unavailable')}</p>
      <p class="kaiki-muted">{t('widget.mount.missing')}</p>
    </div>
  );
}

interface ErrorStateProps {
  readonly t: Translator;
  readonly onRetry: () => void;
  readonly network: boolean;
}

/**
 * The failure a guest sees, with the one control that can change it.
 *
 * `role="alert"` rather than `status`: this replaces something the guest was
 * waiting for, and a screen reader should interrupt rather than wait for a
 * pause. The retry button is a real button, focusable, 44px tall (A11Y).
 */
export function ErrorState({ t, onRetry, network }: ErrorStateProps) {
  return (
    <div class="kaiki-error" role="alert">
      <p>{t(network ? 'widget.error.network' : 'widget.error.generic')}</p>
      <button type="button" class="kaiki-button" onClick={onRetry}>
        {t('widget.retry')}
      </button>
    </div>
  );
}

export function LoadingState({ t }: { readonly t: Translator }) {
  // `aria-busy` rather than a spinner: the widget is a form, not an animation,
  // and a guest on a slow connection is better served by a word than by motion
  // they cannot interpret.
  return (
    <p class="kaiki-muted" role="status" aria-busy="true">
      {t('widget.loading')}
    </p>
  );
}
