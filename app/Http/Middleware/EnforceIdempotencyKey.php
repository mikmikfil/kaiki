<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Api\Support\IdempotencyRecord;
use App\Domain\Api\Support\IdempotencyStore;
use App\Http\Responses\ApiErrorResponse;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * `Idempotency-Key`, once, for every write that will ever need it
 * (`docs/api.md` §3.4).
 *
 * ## One middleware, because four hand-rolled guards would be four dialects
 *
 * `POST /bookings` requires a key, `POST /checkout` and `POST /cancel` require
 * one, `POST /enquiries` accepts one, and M6's invoice issuance will want the
 * same semantics. Written per-endpoint, the fourth copy comes out subtly
 * different — a missing in-flight case, a hash over the raw body instead of the
 * canonicalised one — and every difference is a client bug that reproduces on
 * one endpoint only.
 *
 * `idempotency:required` and `idempotency:optional` are the whole surface.
 *
 * ## The four cases §3.4 names, and the fifth it names last
 *
 * 1. **First request** — claimed, executed, and the response recorded *before*
 *    it is returned.
 * 2. **Same key, same body** — the recorded response verbatim, original status
 *    included, plus `Idempotency-Replayed: true`.
 * 3. **Same key, different body** — `409 idempotency_key_reuse`. The client has
 *    a bug, and failing loudly is the only safe answer when money is involved.
 * 4. **Still in flight** — `409 idempotency_in_progress` with `Retry-After: 1`.
 * 5. **A 5xx** is *not* recorded and the key is released, so a retry
 *    re-executes. A **4xx** *is* recorded — a validation error is a
 *    deterministic answer, and replaying it is cheaper than recomputing it.
 *
 * ## Why the claim happens before validation
 *
 * A request rejected by a `FormRequest` never reaches a controller, and §3.4's
 * fifth case says a 4xx is recorded. Laravel throws out of validation, so this
 * middleware records what the exception handler eventually rendered rather than
 * what the controller returned — which is the only place both are visible.
 *
 * ## The key is required to be a UUID, and that is checked here
 *
 * §3.4: *"a client-generated **UUIDv4**"*. Checked at the door rather than in
 * three form requests, and refused as `422 validation_failed` with
 * `details.idempotency_key` — the exact shape the contract names for a missing
 * one.
 */
class EnforceIdempotencyKey
{
    public const HEADER = 'Idempotency-Key';

    public const REPLAYED_HEADER = 'Idempotency-Replayed';

    /**
     * Headers worth replaying.
     *
     * §3.4 names `Location` and `Idempotency-Replayed`; `Cache-Control` joins
     * them because every one of these responses is `no-store` and a replay that
     * dropped it would be cacheable where the original was not. Everything
     * else — `Date`, a request id, a rate-limit counter — describes *this*
     * request and replaying yesterday's value would be worse than omitting it.
     *
     * @var list<string>
     */
    private const REPLAYED = ['Location', 'Cache-Control', 'Content-Language'];

    public function __construct(private readonly IdempotencyStore $store) {}

    public function handle(Request $request, Closure $next, string $mode = 'optional'): Response
    {
        $key = trim((string) $request->header(self::HEADER, ''));

        if ($key === '') {
            return $mode === 'required' ? $this->missingKey() : $next($request);
        }

        if (! $this->isUuid($key)) {
            return $this->invalidKey();
        }

        $endpoint = $this->endpointFor($request);
        $tenantKey = $this->tenantKey();
        $hash = IdempotencyStore::hash($this->bodyOf($request));

        $existing = $this->store->find($tenantKey, $endpoint, $key);

        if ($existing instanceof IdempotencyRecord) {
            return $existing->bodyHash === $hash
                ? $this->replay($existing)
                : $this->keyReuse();
        }

        if (! $this->store->claim($tenantKey, $endpoint, $key)) {
            // Somebody claimed it between `find()` and here, or it is still
            // running. Either way the honest answer is "try again in a second".
            return $this->inProgress();
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            // An unhandled throw is a 5xx by the time the handler is done with
            // it, and §3.4 does not record those.
            $this->store->release($tenantKey, $endpoint, $key);

            throw $exception;
        }

        if ($response->getStatusCode() >= SymfonyResponse::HTTP_INTERNAL_SERVER_ERROR) {
            $this->store->release($tenantKey, $endpoint, $key);

            return $response;
        }

        $this->store->remember($tenantKey, $endpoint, $key, new IdempotencyRecord(
            bodyHash: $hash,
            status: $response->getStatusCode(),
            body: (string) $response->getContent(),
            headers: $this->headersToKeep($response),
        ));

        return $response;
    }

    /**
     * The response as it was, plus the one header that says it is not new.
     */
    private function replay(IdempotencyRecord $record): Response
    {
        $response = response(
            $record->body,
            $record->status,
            $record->headers,
        );

        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set(self::REPLAYED_HEADER, 'true');

        return $response;
    }

    /** @return array<string, string> */
    private function headersToKeep(Response $response): array
    {
        $kept = [];

        foreach (self::REPLAYED as $name) {
            $value = $response->headers->get($name);

            if (is_string($value) && $value !== '') {
                $kept[$name] = $value;
            }
        }

        return $kept;
    }

    /**
     * The endpoint half of §3.4's `(tenant, endpoint, key)` triple.
     *
     * The **route name**, not the URI: `/bookings/{uuid}/checkout` and
     * `/bookings/{other}/checkout` are the same endpoint, and scoping by URI
     * would let one client's key replay across two bookings — which is either
     * meaningless or dangerous depending on how the client generates keys.
     * The body hash is what distinguishes them, and it already contains the
     * difference.
     */
    private function endpointFor(Request $request): string
    {
        $name = $request->route()?->getName();

        return is_string($name) && $name !== ''
            ? $name
            : $request->method() . ' ' . $request->path();
    }

    private function tenantKey(): string
    {
        $tenant = Tenancy::current();

        // `_none` rather than an exception: this middleware must be safe to
        // place before tenant resolution on an endpoint that has none, and a
        // key with no tenant is still better scoped than a global one.
        return $tenant === null ? '_none' : (string) $tenant->getKey();
    }

    /** @return array<array-key, mixed> */
    private function bodyOf(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function isUuid(string $key): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key) === 1;
    }

    private function missingKey(): Response
    {
        return ApiErrorResponse::fromKey(
            key: 'api.errors.idempotency_key_required',
            code: 'validation_failed',
            status: SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY,
            details: ['idempotency_key' => ['required']],
        );
    }

    private function invalidKey(): Response
    {
        return ApiErrorResponse::fromKey(
            key: 'api.errors.idempotency_key_invalid',
            code: 'validation_failed',
            status: SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY,
            details: ['idempotency_key' => ['uuid']],
        );
    }

    private function keyReuse(): Response
    {
        return ApiErrorResponse::fromKey(
            key: 'api.errors.idempotency_key_reuse',
            code: 'idempotency_key_reuse',
            status: SymfonyResponse::HTTP_CONFLICT,
            details: ['idempotency_key' => ['reused_with_different_body']],
        );
    }

    private function inProgress(): Response
    {
        return ApiErrorResponse::fromKey(
            key: 'api.errors.idempotency_in_progress',
            code: 'idempotency_in_progress',
            status: SymfonyResponse::HTTP_CONFLICT,
            details: ['retry_after_seconds' => 1],
        )->header('Retry-After', '1');
    }
}
