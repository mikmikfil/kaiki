<?php

declare(strict_types=1);

namespace App\Domain\Analytics\Support;

/**
 * The complete list of things that may be counted (ADR-0032).
 *
 * ## A closed list, because the writer is a public endpoint
 *
 * `POST /api/v1/events` is open to anybody holding a publishable key, which is
 * a key that sits in the source of a public web page. A store that accepted an
 * arbitrary string as its `metric` would let a stranger fill an operator's
 * table with rows nobody asked for, and the first symptom would be a slow
 * statistics page.
 *
 * So the name arrives as a string and is resolved through here or dropped. The
 * widget's eight events (WGT-11) map onto seven of these; the eighth,
 * `page_view`, is counted on the server for the hosted pages and cannot be sent
 * from a browser at all.
 *
 * ## The order is the funnel
 *
 * {@see self::funnel()} is read top to bottom on the statistics page, and each
 * step is shown as a count with the ratio to the step above it. It is not a
 * per-person conversion rate and the page does not call it one — cookieless
 * means nobody is followed from one step to the next, and a number named
 * something it is not is worse than no number.
 */
enum AnalyticsMetric: string
{
    /** A hosted page served. Counted on the server; never sent by a browser. */
    case PageView = 'page_view';

    /** The widget booted on somebody's page. */
    case WidgetReady = 'widget_ready';

    /** A trip was looked at. */
    case ProductViewed = 'product_viewed';

    /** A calendar was filled in — the visitor got as far as asking about dates. */
    case AvailabilityLoaded = 'availability_loaded';

    /** A hold was taken: seats, a date, a party. */
    case BookingStarted = 'booking_started';

    /** They reached the payment page. */
    case CheckoutStarted = 'checkout_started';

    /** They paid. */
    case BookingConfirmed = 'booking_confirmed';

    /** They asked a question instead, which is the other way this ends well. */
    case EnquirySubmitted = 'enquiry_submitted';

    /** Something in the widget failed in front of a guest. */
    case WidgetError = 'widget_error';

    /**
     * The widget's event name for this metric, where there is one.
     *
     * The mapping lives here rather than in the endpoint so the two lists
     * cannot drift: adding a case without an event name simply means the
     * browser cannot send it.
     */
    public function widgetEvent(): ?string
    {
        return match ($this) {
            self::PageView => null,
            self::WidgetReady => 'kaiki:ready',
            self::ProductViewed => 'kaiki:product-viewed',
            self::AvailabilityLoaded => 'kaiki:availability-loaded',
            self::BookingStarted => 'kaiki:booking-started',
            self::CheckoutStarted => 'kaiki:checkout-started',
            self::BookingConfirmed => 'kaiki:booking-confirmed',
            self::EnquirySubmitted => 'kaiki:enquiry-submitted',
            self::WidgetError => 'kaiki:error',
        };
    }

    /** Does this metric carry money worth summing? */
    public function carriesValue(): bool
    {
        return $this === self::BookingConfirmed;
    }

    /**
     * The one a browser is allowed to send, or null.
     *
     * Matched on the **event name** rather than the metric name, so what the
     * endpoint accepts is exactly what WGT-11 fixed and nothing else.
     */
    public static function fromWidgetEvent(string $event): ?self
    {
        foreach (self::cases() as $metric) {
            if ($metric->widgetEvent() === $event) {
                return $metric;
            }
        }

        return null;
    }

    /**
     * The funnel, in the order a visitor goes through it.
     *
     * @return list<self>
     */
    public static function funnel(): array
    {
        return [
            self::PageView,
            self::ProductViewed,
            self::AvailabilityLoaded,
            self::BookingStarted,
            self::CheckoutStarted,
            self::BookingConfirmed,
        ];
    }
}
