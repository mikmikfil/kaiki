<?php

declare(strict_types=1);

namespace App\Http\Controllers\Channels\GetYourGuide;

use App\Data\Availability\AvailabilityRequestData;
use App\Domain\Availability\Actions\CheckSeatAvailability;
use App\Domain\Channels\Support\ChannelProducts;
use App\Enums\ChannelKey;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * `GET /1/get-availabilities` — what GetYourGuide asks before showing a date.
 *
 * ## It computes nothing
 *
 * The rule ADR-0034 rests on, and the reason this controller is thin to the
 * point of looking unfinished: it resolves a product, hands the dates to
 * {@see CheckSeatAvailability}, and translates the answer into their field
 * names. It issues **no query against `departures`**.
 *
 * Everything that engine knows, GetYourGuide therefore inherits — holds taken
 * by a guest on the operator's own page thirty seconds ago, a vessel block for
 * maintenance, a whole-boat charter, another platform's iCal block, the
 * turnaround buffer, all seven AVL-22 conditions — including the parts added
 * after this file was written. A second opinion about the same boat is the
 * entire problem this integration exists to avoid, and the way to not have one
 * is to not write one.
 *
 * ## Their vocabulary, not ours, and only at the edge
 *
 * `dateTime`, `productId`, `vacancies` are GetYourGuide's names from their
 * OpenAPI schema. The translation lives here and nowhere else, so their next
 * version is this file rather than a hunt.
 *
 * **Unverified against certification:** the error body shape. Their spec names
 * `ErrorResponseAvailability` but the portal's published YAML does not spell
 * its properties out, so `errorCode`/`errorMessage` is a reasonable guess that
 * has to be confirmed in phase one of certification rather than trusted.
 */
final class AvailabilityController
{
    public function __construct(
        private readonly ChannelProducts $products,
        private readonly CheckSeatAvailability $availability,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $productId = (string) $request->query('productId', '');
        $from = $this->parse($request->query('fromDateTime'));
        $to = $this->parse($request->query('toDateTime'));

        if ($productId === '' || ! $from instanceof Carbon || ! $to instanceof Carbon) {
            return $this->error('INVALID_REQUEST', 'productId, fromDateTime and toDateTime are all required.', 400);
        }

        if ($to->lessThan($from)) {
            return $this->error('INVALID_REQUEST', 'toDateTime is before fromDateTime.', 400);
        }

        $product = $this->products->find(ChannelKey::GetYourGuide, $productId);

        if (! $product instanceof Product) {
            // Not "no availability". A product nobody mapped will never have
            // any, and answering with an empty list would have GetYourGuide ask
            // again every few minutes for as long as the integration lives.
            return $this->error('PRODUCT_NOT_FOUND', 'No product is mapped to that id.', 404);
        }

        $days = ($this->availability)($product, new AvailabilityRequestData(from: $from, to: $to));

        $availabilities = [];

        foreach ($days as $day) {
            foreach ($day->departures as $departure) {
                // Only what can actually be sold. A departure that exists but
                // is blocked, full or past its cutoff is absent rather than
                // present with zero: `vacancies` is `≥0` in their schema and a
                // zero reads as "sold out today", which is a different
                // statement from "not on sale" and is drawn differently on
                // their calendar.
                if (! $departure->available || $departure->seatsRemaining < 1) {
                    continue;
                }

                $availabilities[] = [
                    // Their `dateTime` is the departure's own local wall time.
                    // Sent with its offset rather than as UTC: a supplier that
                    // answers in UTC is read as a different time of day by a
                    // guest choosing "the 09:00 boat", and the offset is the
                    // only thing that makes the two agree across a DST change.
                    'dateTime' => $departure->startsAtUtc?->copy()
                        ->setTimezone($product->tenant->timezone)
                        ->toIso8601String(),
                    'productId' => $productId,
                    'vacancies' => $departure->seatsRemaining,
                ];
            }
        }

        return response()->json(['data' => ['availabilities' => $availabilities]]);
    }

    private function parse(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            // A malformed date is a client error, not a 500. Their retry ladder
            // treats a 5xx as ours to fix and keeps trying.
            return null;
        }
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['errorCode' => $code, 'errorMessage' => $message], $status);
    }
}
