<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Booking\Support\RefundEntitlement;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Support\Format\MoneyFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The outcome of a cancellation, real or rehearsed
 * (`docs/api.md` §5, schema `CancellationResult`).
 *
 * @property-read Booking $resource
 *
 * ## The dry run and the real thing render the same object
 *
 * One resource, one `performed` flag. The alternative — a preview shape and a
 * result shape — is two renderings of the same arithmetic that are equal today
 * and diverge the first time somebody fixes one of them. The schema is explicit
 * that a dry run *"returns the same `CancellationResult` shape with
 * `"performed": false`"*.
 *
 * ## `refund.status` is `pending`, and the booking is `cancelled`, not `refunded`
 *
 * The schema again: *"Availability is released immediately; the money refund is
 * queued to the gateway"*. A response that said `refunded` would be telling a
 * guest their money had moved when a job had not yet run — and the status moves
 * only when the refund webhook settles (#84).
 *
 * ## Every number comes from the frozen snapshot
 *
 * CXL-1. {@see RefundEntitlement} reads `policy_snapshot`, never the operator's
 * current policy, so a guest who booked in March under a generous policy is
 * refunded under that policy in July.
 */
class CancellationResultResource extends JsonResource
{
    private ?RefundEntitlement $entitlement = null;

    private bool $performed = false;

    public function withEntitlement(RefundEntitlement $entitlement, bool $performed): self
    {
        $this->entitlement = $entitlement;
        $this->performed = $performed;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $booking = $this->resource;
        $locale = app()->getLocale();
        $entitlement = $this->entitlement ?? RefundEntitlement::forCancellation($booking);

        return [
            'performed' => $this->performed,
            'booking_uuid' => $booking->uuid,
            'status' => $booking->status->value,
            'refund' => [
                'amount_cents' => $entitlement->totalCents,
                'amount_formatted' => MoneyFormatter::format($entitlement->totalCents, $locale, MoneyFormatter::currency()),
                'percent' => $entitlement->percent,
                'currency' => MoneyFormatter::currency(),
                'method' => $this->method($entitlement),
                'status' => $this->status($booking, $entitlement),
            ],
            'policy' => $booking->policy_snapshot,
            'hours_before_departure' => (int) max(0, (int) now()->diffInHours($booking->starts_at_utc, absolute: false)),
        ];
    }

    /**
     * `gateway`, `voucher` or `none` — and `none` is a real answer.
     *
     * TOK-7: where the policy yields nothing the cancel action is still offered
     * and still releases the seat. A `method` of `none` is what that looks like
     * on the wire, and it is not an error.
     */
    private function method(RefundEntitlement $entitlement): string
    {
        if ($entitlement->totalCents === 0) {
            return 'none';
        }

        return $entitlement->voucherCents > 0 && $entitlement->cashCents === 0 ? 'voucher' : 'gateway';
    }

    private function status(Booking $booking, RefundEntitlement $entitlement): string
    {
        if ($entitlement->totalCents === 0) {
            return 'not_applicable';
        }

        if (! $this->performed) {
            return 'not_applicable';
        }

        return $booking->status === BookingStatus::Refunded ? 'succeeded' : 'pending';
    }
}
