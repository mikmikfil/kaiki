import { useEffect, useMemo, useState } from 'preact/hooks';

import { registerMount, type MountProps } from '../../mounts';

/**
 * The `list` mount (WGT-5, WGT-6): an operator's trips as a grid.
 *
 * ## No price on a quote product, anywhere, ever
 *
 * BKG-24, and the issue's own note about how to test it: *"A `quote` product
 * whose price is hidden by CSS still has the number in the DOM."* So the
 * component never renders the element rather than rendering it hidden, and the
 * test scans the output for a currency symbol.
 *
 * The API already refuses to send one — `from_price_cents` is null for a quote
 * product whatever the column holds — so this is the second of two locks on the
 * same door, which is the right number for a rule an operator cannot undo if it
 * fails.
 *
 * ## The tabs are the operator's own categories
 *
 * Built from what came back rather than from the enum, so a fleet that sells
 * only sunset cruises and private charters gets two tabs and not six. `data-
 * category` narrows the fetch itself, which is the case for an operator putting
 * one row of trips on a page about that kind of trip.
 *
 * ## Equal-height cards with the price row pinned to the bottom
 *
 * Settled on 4 September, and the reason is that a row of cards whose prices sit
 * at different heights reads as a mistake. `margin-top: auto` on the price row
 * inside a flex column, which is the same rule the hosted pages use.
 */

interface ProductCard {
  readonly uuid: string;
  readonly slug: string;
  readonly title: string;
  readonly summary: string | null;
  readonly category: string;
  readonly mode: string;
  readonly duration_minutes: number;
  readonly from_price_cents: number | null;
  readonly from_price_formatted: string | null;
  readonly booking_url: string | null;
  readonly meeting_point?: { readonly name?: string } | null;
}

export function ListMount({ client, category, t, analytics }: MountProps) {
  const [products, setProducts] = useState<ProductCard[] | null>(null);
  const [failed, setFailed] = useState(false);
  const [active, setActive] = useState<string | null>(category);

  useEffect(() => {
    client
      .get<{ data: ProductCard[] }>('/products', { query: { category: category ?? undefined, per_page: 24 } })
      .then((response) => {
        // Defensive on purpose: this runs inside somebody else's page, and a
        // payload that is not the shape the contract promises must render an
        // empty list rather than throw an exception into their console.
        setProducts(Array.isArray(response.data) ? response.data : []);
        analytics.emit('kaiki:ready', { mount: 'list' });
      })
      .catch(() => setFailed(true));
  }, [client, category, analytics]);

  const categories = useMemo(
    () => [...new Set((products ?? []).map((product) => product.category))],
    [products],
  );

  const shown = useMemo(
    () => (active === null ? (products ?? []) : (products ?? []).filter((product) => product.category === active)),
    [products, active],
  );

  if (failed) {
    return (
      <div class="kaiki-error" role="alert">
        <p>{t('widget.error.network')}</p>
      </div>
    );
  }

  if (products === null) {
    return (
      <p class="kaiki-muted" role="status" aria-busy="true">
        {t('widget.loading')}
      </p>
    );
  }

  if (products.length === 0) {
    return <p class="kaiki-muted">{t('list.empty')}</p>;
  }

  return (
    <div class="kaiki-list">
      {categories.length > 1 ? (
        // A tablist, so a keyboard reaches the tabs and a screen reader is told
        // what they are (WGT-21, A11Y-1).
        <div class="kaiki-tabs" role="tablist" aria-label={t('list.categories')}>
          <button
            type="button"
            role="tab"
            aria-selected={active === null}
            class={active === null ? 'kaiki-tab kaiki-tab-active' : 'kaiki-tab'}
            onClick={() => setActive(null)}
          >
            {t('list.all')}
          </button>

          {categories.map((name) => (
            <button
              key={name}
              type="button"
              role="tab"
              aria-selected={active === name}
              class={active === name ? 'kaiki-tab kaiki-tab-active' : 'kaiki-tab'}
              onClick={() => setActive(name)}
            >
              {t(`enums.category.${name}` as never)}
            </button>
          ))}
        </div>
      ) : null}

      <ul class="kaiki-cards" role="list">
        {shown.map((product) => (
          <li class="kaiki-card" key={product.uuid}>
            <h3 class="kaiki-heading">
              {product.booking_url === null ? (
                product.title
              ) : (
                <a href={product.booking_url} onClick={() => analytics.emit('kaiki:product-viewed', { product_uuid: product.uuid })}>
                  {product.title}
                </a>
              )}
            </h3>

            {product.summary === null ? null : <p class="kaiki-muted">{product.summary}</p>}

            <p class="kaiki-muted kaiki-facts">
              {t('booking.lines.minutes').replace(':minutes', String(product.duration_minutes))}
              {product.meeting_point?.name === undefined ? '' : ` · ${product.meeting_point.name}`}
            </p>

            {/* BKG-24: for a quote product the element is not rendered at all.
                Hiding it with CSS would leave the number in the DOM, and the
                requirement is that a guest is never shown one. */}
            <p class="kaiki-card-price">
              {product.mode === 'quote' || product.from_price_formatted === null ? (
                <span class="kaiki-on-request">{t('list.on_request')}</span>
              ) : (
                <>
                  <span class="kaiki-muted">{t('list.from')}</span>{' '}
                  <strong>{product.from_price_formatted}</strong>
                </>
              )}
            </p>
          </li>
        ))}
      </ul>
    </div>
  );
}

registerMount('list', ListMount);
