import { useEffect, useState } from 'preact/hooks';

import { registerMount, type MountProps } from '../../mounts';
import { BookingMount, type ProductSummary } from './BookingMount';

/**
 * The booking mount, joined to the shell (#106's registry, #107's mount).
 *
 * ## The product is fetched here rather than inside the mount
 *
 * The walk needs the trip's age bands, its extras, its meeting point and its
 * boat — the four lines and the party step are built from them. Fetching it in a
 * wrapper keeps `BookingMount` a component that renders what it is given, which
 * is what lets the machine and the flow be tested without a network at all.
 *
 * The read goes through the shared client, so a page with a list mount and a
 * booking mount pays for one catalogue request rather than two (WGT-8, WGT-17).
 */
function BookingMountLoader({ productUuid, client, t, analytics, locale, date = null }: MountProps) {
  const [product, setProduct] = useState<ProductSummary | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    if (productUuid === null) {
      // `data-mount="booking"` with no `data-product` is an operator's embed
      // mistake, and WGT-7 already defaults the other way round — so this is
      // reachable only by writing both attributes and one of them wrongly.
      setFailed(true);

      return;
    }

    client
      .get<{ data: ProductSummary }>(`/products/${productUuid}`)
      .then((response) => setProduct(response.data))
      .catch(() => setFailed(true));
  }, [client, productUuid]);

  if (failed) {
    return (
      <div class="kaiki-error" role="alert">
        <p>{t('widget.error.unavailable')}</p>
        <p class="kaiki-muted">{t('widget.error.contact')}</p>
      </div>
    );
  }

  if (product === null) {
    return (
      <p class="kaiki-muted" role="status" aria-busy="true">
        {t('widget.loading')}
      </p>
    );
  }

  return (
    <BookingMount
      client={client}
      productUuid={productUuid as string}
      product={product}
      t={t}
      analytics={analytics}
      locale={locale}
      initialDate={date}
    />
  );
}

registerMount('booking', BookingMountLoader);
