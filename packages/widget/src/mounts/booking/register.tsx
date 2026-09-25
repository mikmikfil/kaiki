import { useEffect, useState } from 'preact/hooks';

import { registerMount, type MountProps } from '../../mounts';
import { EnquiryMount } from '../enquiry/EnquiryMount';
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
export function BookingMountLoader({ productUuid, category, client, t, analytics, locale, date = null, departure = null, showVessel = true, showDetails = true }: MountProps) {
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

  if (product.mode === 'quote') {
    /*
     * BKG-24: a trip sold by quote is asked about, not booked.
     *
     * The embed said `booking` because the WordPress shortcode, the Elementor
     * widget and a hand-written tag cannot know the trip's mode — only this
     * read does. A calendar here would walk the guest through a date and a
     * party to meet `product_is_quote_only` at the checkout button, so the
     * enquiry form stands in its place, carrying the trip. It is the same
     * component the hosted trip page mounts as `enquiry`, so the two cannot
     * drift; and no `kaiki:booking-started` can fire for a trip that cannot be
     * booked — what it sends is `kaiki:enquiry-submitted`.
     */
    return (
      <EnquiryMount
        productUuid={productUuid}
        category={category}
        client={client}
        t={t}
        analytics={analytics}
        locale={locale}
        date={date}
        showVessel={showVessel}
        showDetails={showDetails}
        product={product}
      />
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
      initialDeparture={date === null ? null : departure}
      showVessel={showVessel}
      showDetails={showDetails}
    />
  );
}

registerMount('booking', BookingMountLoader);
