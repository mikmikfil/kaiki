<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * A JSON body with the conditional-request machinery §3.7 promises.
 *
 * Four endpoints return an `ETag` now — branding, the product list, the product
 * detail and the catalogue sync — and the first three grew their own copy of
 * this before the fourth arrived. Three copies of a validator comparison is
 * three chances to write the naive one, and the naive one is not obviously
 * wrong: it works for every client that sends back exactly what it was given,
 * which is every client anybody tests with.
 *
 * ## The ETag is strong and computed from the payload
 *
 * Hashing what is about to be sent means the tag cannot disagree with the body.
 * A stamp taken from `max(updated_at)` would look right and drift the moment
 * anything else fed the payload — the negotiated locale, a rate plan going
 * inactive, an extra being renamed — none of which touch the row whose
 * timestamp was borrowed.
 *
 * ## A 304 is the point, not an optimisation
 *
 * It carries no body, still costs rate-limit quota, and is the cheapest correct
 * way for the WordPress plugin to poll. `GET /sync/products` is polled by
 * WP-Cron on a schedule the operator chooses, so most of those calls should
 * cost a hash and a header.
 */
final class ConditionalJson
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers  Cache-Control and anything else the endpoint adds.
     */
    public static function respond(Request $request, array $payload, array $headers = []): SymfonyResponse
    {
        $etag = '"' . hash('sha256', (string) json_encode($payload)) . '"';

        $headers = ['ETag' => $etag] + $headers;

        if (self::matches($request, $etag)) {
            return response()->noContent(SymfonyResponse::HTTP_NOT_MODIFIED)->withHeaders($headers);
        }

        return (new JsonResponse($payload, SymfonyResponse::HTTP_OK))->withHeaders($headers);
    }

    /**
     * Does the client already hold this exact payload?
     *
     * `If-None-Match` may carry a list, and a weak validator arrives prefixed
     * `W/`. Comparing the raw header against our own tag would miss both and
     * turn every conditional request into a full response — the failure nobody
     * notices, because everything still works.
     */
    private static function matches(Request $request, string $etag): bool
    {
        $header = $request->headers->get('If-None-Match');

        if ($header === null) {
            return false;
        }

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '*' || ltrim($candidate, 'W/') === $etag) {
                return true;
            }
        }

        return false;
    }
}
