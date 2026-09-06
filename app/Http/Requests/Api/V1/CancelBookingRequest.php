<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Domain\Booking\Support\RefundEntitlement;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/bookings/{uuid}/cancel` (`docs/api.md` §5, schema `CancelRequest`).
 *
 * ## `dry_run` is the whole reason this endpoint takes a body at all
 *
 * The schema: *"Call this first so the guest confirms against a real number,
 * not a guess."* Both calls compute the refund from the same frozen
 * `policy_snapshot` through the same {@see RefundEntitlement},
 * so the figure a guest is shown and the figure they get cannot disagree —
 * which is the one thing they will check.
 *
 * A dry run is still a `POST` and still requires an `Idempotency-Key`, because
 * the contract says so and because a client that had to change verb between
 * the preview and the act would have two code paths where it needs one.
 *
 * ## `reason` is stored verbatim and never translated
 *
 * §2.4: free guest text is *"stored and returned exactly as written"*. A guest
 * writing "μας ακύρωσαν την πτήση" is writing evidence, and a translated copy
 * of it is not the thing they wrote.
 */
class CancelBookingRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'dry_run' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
            'refund_preference' => ['nullable', 'string', 'in:refund,voucher'],
        ];
    }

    public function isDryRun(): bool
    {
        return $this->boolean('dry_run');
    }
}
