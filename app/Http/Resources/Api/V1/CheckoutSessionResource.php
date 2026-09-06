<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Payments\Data\RedirectTarget;
use App\Models\Booking;
use App\Models\Payment;
use App\Support\Format\MoneyFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A gateway session (`docs/api.md` §5, schema `CheckoutSession`).
 *
 * @property-read Payment $resource
 *
 * ## `redirect_url` is short-lived and says so
 *
 * The schema: *"Send the guest here. Short-lived; do not cache or email it."*
 * Both halves are enforced rather than hoped for — the response carries
 * `Cache-Control: no-store`, and ADR-0004 is the reason nothing emails one: a
 * balance link mailed today is dead by the time a guest opens it in three
 * weeks, so every message points at `/b/{manage_token}`, which mints a fresh
 * session on demand.
 *
 * ## No card data, no gateway payload, no credential
 *
 * Kaiki never sees a card (brief §1) and this shape could not carry one. What
 * is here is the operator's own session as the guest needs to reach it: an
 * amount, a URL and an expiry.
 */
class CheckoutSessionResource extends JsonResource
{
    private ?RedirectTarget $target = null;

    private ?Booking $booking = null;

    public function withTarget(RedirectTarget $target, Booking $booking): self
    {
        $this->target = $target;
        $this->booking = $booking;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $payment = $this->resource;
        $locale = app()->getLocale();

        return [
            'payment_uuid' => $payment->uuid,
            'booking_uuid' => $this->booking?->uuid,
            'gateway' => $payment->gateway->value,
            'kind' => $payment->kind->value,
            'amount_cents' => $payment->amount_cents,
            'amount_formatted' => MoneyFormatter::format($payment->amount_cents, $locale, MoneyFormatter::currency()),
            'currency' => MoneyFormatter::currency(),
            'redirect_url' => $this->target?->url,
            // The gateway's own session expiry where it gave us one; otherwise
            // the hold, which is the deadline that actually binds the guest.
            'expires_at' => $this->expiresAt(),
            'hold_expires_at' => $this->booking?->hold_expires_at?->toIso8601ZuluString(),
            'is_test' => $this->booking === null ? false : $this->booking->is_test,
        ];
    }

    private function expiresAt(): ?string
    {
        $context = $this->target === null ? [] : $this->target->context;

        $expires = $context['expires_at'] ?? null;

        if (is_string($expires) && $expires !== '') {
            return $expires;
        }

        return $this->booking?->hold_expires_at?->toIso8601ZuluString();
    }
}
