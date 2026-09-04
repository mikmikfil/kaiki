<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Branding\Actions\GetBrandPayload;
use App\Enums\ApiKeyEnvironment;
use App\Models\ApiKey;
use App\Support\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * `GET /api/v1/branding` — the widget's first call on every page load
 * (spec BRD-6, BRD-8, WGT-9, `docs/api.md` §5).
 *
 * Thin (CNV-5): {@see GetBrandPayload} builds and caches the payload, because
 * the hosted page will need the same thing in M3 with `custom_css` added.
 *
 * ## The ETag is strong, and computed from the payload
 *
 * BRD-6. Hashing what is about to be sent means the ETag cannot disagree with
 * the body — a version stamp taken from `updated_at` would look right and drift
 * the moment anything else fed the payload, such as the locale or the test
 * flag, both of which change the bytes without touching the row.
 *
 * A 304 carries **no body** and still costs rate-limit quota, which is what
 * makes conditional polling the cheapest correct thing a client can do rather
 * than a way around the limit.
 */
final class BrandingController
{
    public function __invoke(Request $request, GetBrandPayload $payload): SymfonyResponse
    {
        $tenant = Tenancy::current();

        if ($tenant === null) {
            // `tenant` middleware guarantees this, so reaching it means the
            // route was wired without it — worth failing loudly rather than
            // serving somebody else's brand.
            abort(SymfonyResponse::HTTP_NOT_FOUND);
        }

        $key = $request->attributes->get('api_key');

        $data = $payload(
            $tenant,
            app()->getLocale(),
            $key instanceof ApiKey && $key->environment === ApiKeyEnvironment::Test,
        );

        $etag = '"' . hash('sha256', (string) json_encode($data)) . '"';

        if ($this->matches($request, $etag)) {
            return response()->noContent(SymfonyResponse::HTTP_NOT_MODIFIED)
                ->withHeaders($this->cacheHeaders($etag));
        }

        return (new JsonResponse(['data' => $data], Response::HTTP_OK))
            ->withHeaders($this->cacheHeaders($etag));
    }

    /**
     * @return array<string, string>
     */
    private function cacheHeaders(string $etag): array
    {
        return [
            'ETag' => $etag,
            // 60 seconds, from `docs/api.md` §3.6. The contract is the
            // authority (§10.5), and it says 60 where the issue said 300.
            'Cache-Control' => 'public, max-age=' . (int) config('kaiki.branding.cache_ttl_seconds', 60),
        ];
    }

    /**
     * Does the client already have this exact payload?
     *
     * `If-None-Match` may carry a list, and a weak validator arrives prefixed
     * `W/`. Comparing the raw header against our own tag would miss both and
     * turn every conditional request into a full response — which is the
     * failure mode nobody notices, because everything still works.
     */
    private function matches(Request $request, string $etag): bool
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
