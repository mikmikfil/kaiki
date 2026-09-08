<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Support;

use App\Enums\WebhookEvent;
use App\Http\Resources\Api\V1\BookingResource;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The envelope of `docs/api.md` §8.2, and what goes inside it.
 *
 * ## `data` reuses the API's own resources, and that is a rule not a shortcut
 *
 * §8.2: *"`data` uses the **same schemas** as this API. A `Booking` in a webhook
 * is the `Booking` schema, minus `manage_token` and `links`."* Building a second
 * representation here would drift from the first the day somebody adds a field
 * to one of them, and an integrator who has written code against `GET
 * /bookings/{uuid}` would find the webhook disagreeing with it about the same
 * booking.
 *
 * ## Two keys are removed, and each for its own reason
 *
 * `manage_token` is the **guest's** credential — the URL that lets whoever holds
 * it cancel the booking. `BookingResource` already defaults it to null and makes
 * including it an explicit opt-in, so this is belt and braces; it is unset
 * anyway, because a default that protects you is one refactor away from a
 * default that does not.
 *
 * `links` is removed because those URLs are the guest's too, and because a
 * webhook consumer following one would be fetching a page written for a person.
 *
 * ## No document numbers, ever, and the test asserts it from outside
 *
 * §8.2 and OPS-20. `guest_details.completed` reports *that* the manifest is
 * complete and how many rows — never the rows. The payload has no case for a
 * document number, so there is no mechanism here that could include one, which
 * is the same construction `ExportRows` uses and the same way it is tested: put
 * a real number in the database and search the serialised bytes for it.
 *
 * ## `is_test` is on every delivery
 *
 * SAA-12 keeps test bookings out of every figure and every export. Webhooks are
 * the one place they are *sent* rather than suppressed — an operator testing
 * their integration needs the event to arrive — so the flag rides along and the
 * contract tells consumers to branch on it.
 */
final class WebhookPayload
{
    /** §8.2's `api_version`. The envelope is additive-only; this moves for a break. */
    public const API_VERSION = '1';

    /**
     * The whole body, ready to be JSON-encoded.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function envelope(
        WebhookEvent $event,
        string $eventId,
        Tenant $tenant,
        array $data,
        bool $isTest,
        ?Carbon $now = null,
    ): array {
        return [
            'id' => $eventId,
            'event' => $event->value,
            'api_version' => self::API_VERSION,
            'created_at' => ($now ?? Carbon::now())->utc()->toIso8601ZuluString(),
            'tenant' => [
                'uuid' => $tenant->uuid,
                'slug' => $tenant->slug,
            ],
            'is_test' => $isTest,
            'data' => $data,
        ];
    }

    /**
     * `{"booking": …}`, the API's own shape minus the guest's two keys.
     *
     * @return array<string, mixed>
     */
    public static function booking(Booking $booking): array
    {
        $resource = (new BookingResource($booking))->toArray(self::request());

        unset($resource['manage_token'], $resource['links']);

        return ['booking' => $resource];
    }

    /**
     * `{"departure": …}` — the sailing, its trip and its boat.
     *
     * Hand-built rather than reusing a resource, because there is no public
     * `Departure` schema: a guest never fetches one directly, and inventing an
     * API shape here would publish a contract no endpoint honours.
     *
     * @return array<string, mixed>
     */
    public static function departure(Departure $departure): array
    {
        return [
            'departure' => [
                'uuid' => $departure->uuid,
                'status' => $departure->status->value,
                'local_date' => $departure->local_date instanceof Carbon
                    ? $departure->local_date->toDateString()
                    : (string) $departure->local_date,
                'local_time' => $departure->local_time,
                'starts_at' => $departure->starts_at_utc?->toIso8601ZuluString(),
                'ends_at' => $departure->ends_at_utc?->toIso8601ZuluString(),
                'cancel_reason' => $departure->cancel_reason?->value,
                'seats_sold' => $departure->seats_sold,
                'capacity' => $departure->capacity,
                'product' => $departure->product === null ? null : [
                    'uuid' => $departure->product->uuid,
                    'title' => $departure->product->title,
                ],
                'vessel' => $departure->vessel === null ? null : [
                    'uuid' => $departure->vessel->uuid,
                    'name' => $departure->vessel->name,
                ],
            ],
        ];
    }

    /**
     * `{"booking": …, "guest_details": {"complete": true, "count": n}}`.
     *
     * Counts, never rows. See the class docblock: the manifest itself is the
     * operator's own export, which is an audited action in the panel precisely
     * because it may carry document numbers.
     *
     * @return array<string, mixed>
     */
    public static function guestDetails(Booking $booking, int $count): array
    {
        return [
            ...self::booking($booking),
            'guest_details' => [
                'complete' => true,
                'count' => $count,
            ],
        ];
    }

    /**
     * A request for the resource to render against.
     *
     * `JsonResource::toArray()` takes one, and a queued context may have
     * nothing useful in the container. Resources here read the locale and
     * nothing else from it, so a synthetic request is honest rather than a
     * workaround.
     */
    private static function request(): Request
    {
        return Request::create('/', 'GET');
    }
}
