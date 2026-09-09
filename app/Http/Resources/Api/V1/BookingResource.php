<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Domain\Booking\Support\CheckInWindow;
use App\Domain\Booking\Support\RefundEntitlement;
use App\Domain\Hosted\Support\HostedUrl;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingGuest;
use App\Models\Port;
use App\Support\Format\DateTimeFormatter;
use App\Support\Format\MoneyFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A booking as its own guest sees it (`docs/api.md` §5, schema `Booking`).
 *
 * @property-read Booking $resource
 *
 * ## What the contract says is never in here, and what enforces that
 *
 * > Never contains internal ids, operator notes, other guests' data, gateway
 * > payloads or passport numbers.
 *
 * Four separate rules, and each is a field that is **absent** rather than
 * conditionally hidden. `internal_notes` is not read; `document_number` is
 * masked by {@see self::guest()} and its plaintext never leaves the model
 * (`BookingGuest::$hidden`); no `payments` payload is touched at all.
 * `BookingEndpointTest` walks the response recursively looking for anything
 * that smells like a database id, the way `ProductIndexTest` does — CNV-8 is
 * the kind of rule that is kept by a scanner or not at all.
 *
 * ## `manage_token` appears exactly once in the life of a booking
 *
 * On the `201` from `POST /bookings`, and never again:
 *
 * > Returned **only** in the `201` response … this is the one moment the client
 * > can capture it. Always null on subsequent reads; the caller already holds
 * > it.
 *
 * The default is null and {@see self::withManageToken()} is the single opt-in,
 * so a new endpoint that returns a booking cannot leak it by forgetting to.
 *
 * ## Cancellability is recomputed on every read
 *
 * §5's own note: *"Recomputed on every read, because it changes with the
 * clock."* A guest looking at `/b/{token}` on Monday and Thursday is inside two
 * different refund tiers, and a stored answer would show them the wrong one on
 * the day they act — computed from the **policy snapshot** (CXL-1), never from
 * the operator's current policy.
 */
class BookingResource extends JsonResource
{
    private bool $withManageToken = false;

    /**
     * The one place the token is allowed out.
     *
     * See the class docblock. Opt-in rather than opt-out because the failure
     * modes are not symmetrical: forgetting to include it costs a client one
     * broken flow that is obvious immediately, and forgetting to exclude it
     * puts a cancel-and-refund credential in every read.
     */
    public function withManageToken(): self
    {
        $this->withManageToken = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $booking = $this->resource;
        $locale = app()->getLocale();

        $entitlement = RefundEntitlement::forCancellation($booking);

        return [
            'uuid' => $booking->uuid,
            'reference' => $booking->reference,
            'status' => $booking->status->value,
            'mode' => $booking->mode->value,
            'locale' => $booking->locale,
            'is_test' => $booking->is_test,
            'manage_token' => $this->withManageToken ? $booking->manage_token : null,
            // Where the widget sends the guest next. Emitted with the token and
            // under the same condition, because it *is* the token: a caller that
            // may not see one has no checkout link to be given either.
            'checkout_url' => $this->withManageToken
                ? HostedUrl::checkout($booking->manage_token)
                : null,
            'product' => $this->product($booking),
            'vessel' => $booking->vessel === null ? null : [
                'uuid' => $booking->vessel->uuid,
                'name' => $booking->vessel->name,
                'type' => $booking->vessel->type->value,
                'capacity_max' => $booking->vessel->capacity_max,
            ],
            'departure_uuid' => $booking->departure?->uuid,
            'window' => $this->window($booking),
            'check_in_local_time' => $this->checkInLocalTime($booking),
            'meeting_point' => $this->meetingPoint($booking),
            'guest' => [
                'name' => $booking->guest_name,
                'email' => $booking->guest_email,
                'phone' => $booking->guest_phone,
            ],
            'pax' => $this->pax($booking),
            'pax_total' => $booking->pax_total,
            'pax_capacity_total' => $booking->pax_capacity_total,
            'extras' => $booking->extras_snapshot,
            'money' => $this->money($booking, $locale),
            'policy' => $booking->policy_snapshot,
            'cancellation' => [
                'can_cancel' => $this->canCancel($booking),
                'reason' => $this->cannotCancelBecause($booking),
                'refund_cents' => $entitlement->totalCents,
                'refund_percent' => $entitlement->percent,
                'refund_formatted' => MoneyFormatter::format($entitlement->totalCents, $locale, MoneyFormatter::currency()),
                'currency' => MoneyFormatter::currency(),
            ],
            'guest_details' => $this->guestDetails($booking),
            'guests' => $this->guests($booking),
            'links' => $this->links($booking),
            'hold_expires_at' => $booking->hold_expires_at?->toIso8601ZuluString(),
            'special_requests' => $booking->special_requests,
            'payment_required' => $booking->balance_cents > 0,
            'created_at' => $booking->created_at?->toIso8601ZuluString(),
            'confirmed_at' => $booking->confirmed_at?->toIso8601ZuluString(),
            'cancelled_at' => $booking->cancelled_at?->toIso8601ZuluString(),
            'completed_at' => $booking->completed_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * The frozen pax breakdown, minus the database id.
     *
     * §2.5's snapshot carries `age_band_id` because every internal join needs
     * it; CNV-8 forbids one in a payload and the contract's `PaxBreakdownLine`
     * names `age_band_uuid` instead. Dropping it here rather than not storing
     * it keeps both true.
     *
     * A booking created before #89 has no `age_band_uuid` in its snapshot and
     * simply omits the field, which the schema allows — back-filling it would
     * mean rewriting frozen snapshots, and a snapshot that can be rewritten is
     * not one.
     *
     * @return list<array<string, mixed>>
     */
    private function pax(Booking $booking): array
    {
        return array_values(array_map(
            static function (array $line): array {
                unset($line['age_band_id']);

                return $line;
            },
            $booking->pax_breakdown,
        ));
    }

    /** @return array<string, mixed>|null */
    private function product(Booking $booking): ?array
    {
        $product = $booking->product;

        if ($product === null) {
            return null;
        }

        return [
            'uuid' => $product->uuid,
            'slug' => $product->slug,
            'title' => $product->title,
            'summary' => $product->summary,
            'category' => $product->category->value,
            'mode' => $product->mode->value,
            'duration_minutes' => $product->duration_minutes,
        ];
    }

    /** @return array<string, mixed> */
    private function window(Booking $booking): array
    {
        return [
            'local_date' => $booking->local_date->toDateString(),
            'local_time' => substr((string) $booking->local_time, 0, 5),
            'starts_at' => $booking->starts_at_utc->toIso8601ZuluString(),
            'ends_at' => $booking->ends_at_utc->toIso8601ZuluString(),
            'timezone' => DateTimeFormatter::timezone(),
        ];
    }

    /**
     * BKG-22's window opening, as a local `HH:MM`.
     *
     * The single most useful line on a booking a guest reads on the morning
     * itself, and one they cannot compute: `check_in_offset_minutes` is not in
     * any payload they can see.
     */
    private function checkInLocalTime(Booking $booking): ?string
    {
        if ($booking->product === null) {
            return null;
        }

        return DateTimeFormatter::time(
            CheckInWindow::forBooking($booking, $booking->product)->opensAt,
        );
    }

    /** @return array<string, mixed>|null */
    private function meetingPoint(Booking $booking): ?array
    {
        $port = $booking->product?->meetingPoint;

        return $port instanceof Port
            ? MeetingPointResource::make($port)->resolve()
            : null;
    }

    /** @return array<string, mixed> */
    private function money(Booking $booking, string $locale): array
    {
        $snapshot = $booking->price_snapshot ?? [];

        return [
            'currency' => MoneyFormatter::currency(),
            'subtotal_cents' => $booking->subtotal_cents,
            'extras_cents' => $booking->extras_cents,
            'discount_cents' => $booking->discount_cents,
            'total_cents' => $booking->total_cents,
            'total_formatted' => MoneyFormatter::format($booking->total_cents, $locale, MoneyFormatter::currency()),
            'deposit_cents' => $booking->deposit_cents,
            'paid_cents' => $booking->paid_cents,
            'balance_cents' => $booking->balance_cents,
            'refunded_cents' => $booking->refunded_cents,
            'balance_due_at' => $booking->balance_due_at?->toIso8601ZuluString(),
            'vat' => [
                'rate_bp' => $booking->vat_rate_bp,
                'vat_category' => $booking->vat_category,
                'included' => true,
                'vat_cents' => $booking->vat_cents,
                'net_cents' => $booking->total_cents - $booking->vat_cents,
            ],
            // The frozen derivation, exactly as it was written at confirmation.
            'lines' => is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : [],
        ];
    }

    /** @return array<string, mixed> */
    private function guestDetails(Booking $booking): array
    {
        $guests = $booking->guests;

        return [
            'status' => $booking->guest_details_status->value,
            'required' => $booking->product === null ? false : $booking->product->guest_details_required,
            'deadline_at' => $booking->guest_details_deadline_at?->toIso8601ZuluString(),
            'completed_count' => $guests->filter(
                static fn (BookingGuest $guest): bool => $guest->full_name !== null,
            )->count(),
            'total_count' => $guests->count(),
            'url' => $booking->guest_details_token === null
                ? null
                : route('guest.details', ['token' => $booking->guest_details_token]),
        ];
    }

    /**
     * The manifest, minus the passport number.
     *
     * §5's `BookingGuest`: *"`document_number` is **write-only**: stored
     * encrypted, never returned. `document_number_masked` exists so the guest
     * can see something is on file without this API becoming a way to read
     * passport numbers back out."*
     *
     * Empty before confirmation, because the rows do not exist yet.
     *
     * @return list<array<string, mixed>>
     */
    private function guests(Booking $booking): array
    {
        return $booking->guests
            ->sortBy('position')
            ->map(static fn (BookingGuest $guest): array => [
                'uuid' => $guest->uuid,
                'position' => $guest->position,
                'age_band_code' => $guest->age_band_code,
                'full_name' => $guest->full_name,
                'date_of_birth' => $guest->date_of_birth?->toDateString(),
                'nationality' => $guest->nationality,
                'document_type' => $guest->document_type?->value,
                'document_number_masked' => $guest->document_number === null
                    ? null
                    : '••••' . mb_substr($guest->document_number, -4),
                'document_expires_on' => $guest->document_expires_on?->toDateString(),
                'is_lead' => $guest->is_lead,
                'notes' => $guest->notes,
                // Present once confirmed, because that is when the code is on a
                // ticket somebody is holding.
                'ticket_code' => $guest->ticket_code,
                'checked_in_at' => $guest->checked_in_at?->toIso8601ZuluString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Everything the guest can open, all tokenised.
     *
     * `ticket_pdf_url` is present *"once confirmed"*, and here that means once
     * the file exists: a link to a ticket whose generation failed (BKG-14) is
     * worse than no link, because the guest taps it at a quay.
     *
     * @return array<string, mixed>
     */
    private function links(Booking $booking): array
    {
        return [
            'manage_url' => route('guest.booking', ['token' => $booking->manage_token]),
            'guest_details_url' => $booking->guest_details_token === null
                ? null
                : route('guest.details', ['token' => $booking->guest_details_token]),
            'ticket_pdf_url' => $booking->eticket_path === null
                ? null
                : route('guest.ticket', ['token' => $booking->manage_token]),
        ];
    }

    private function canCancel(Booking $booking): bool
    {
        return $this->cannotCancelBecause($booking) === null;
    }

    /**
     * §5's `BookingCancellability.reason` enum, in the order it is asked.
     *
     * Ordered from the most specific answer to the least: a cancelled booking
     * that has also departed should say `already_cancelled`, because that is
     * the fact the guest is looking at.
     */
    private function cannotCancelBecause(Booking $booking): ?string
    {
        if ($booking->status === BookingStatus::Cancelled || $booking->status === BookingStatus::Refunded) {
            return 'already_cancelled';
        }

        if ($booking->status !== BookingStatus::Confirmed) {
            return 'not_confirmed';
        }

        if ($booking->starts_at_utc->isPast()) {
            return 'departure_started';
        }

        return null;
    }
}
