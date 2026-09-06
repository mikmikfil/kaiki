<?php

declare(strict_types=1);

namespace App\Domain\Api\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Where a replayed request finds its first answer (`docs/api.md` §3.4).
 *
 * ## The cache, and not a table
 *
 * §3.4 fixes the semantics and the **24-hour retention** and says nothing about
 * where the record lives. A table would need a migration, a model, a policy, a
 * purge job and a nightly schedule entry — five moving parts to hold something
 * that is worthless after a day and that nobody ever queries by anything but
 * its exact key. The cache expires its own rows, which is the whole of the
 * retention requirement.
 *
 * The honest cost: a cache flush forgets in-flight keys, and a replay after one
 * re-executes. That is survivable **because the Actions behind these endpoints
 * are each idempotent in their own right** — `CreateBookingDraft` produces a
 * draft that expires in fifteen minutes, `StartCheckout` reuses an open payment
 * row, `CancelBooking` returns early on a booking already cancelled. This
 * middleware is the contract-level guarantee; it is not the only one, and a
 * design where it were the only one would be the wrong design.
 *
 * ## The key is `(tenant, endpoint, key)`, exactly as §3.4 says
 *
 * Not the key alone. Two endpoints are entitled to see the same client-generated
 * UUID — a client that generates one per user action and retries both — and a
 * global key would replay a checkout's response to a cancellation.
 *
 * ## `add()`, not `has()` then `put()`
 *
 * §3.4's fourth case is *"replay while the first request is still in flight"*,
 * and the check-then-write version of it has a race exactly the width of the
 * request it is protecting. `add()` is atomic on every store the platform uses,
 * so the second caller loses and gets `409 idempotency_in_progress`.
 */
final class IdempotencyStore
{
    /** The marker written while a request is running. */
    private const IN_FLIGHT = '__in_flight__';

    /**
     * Claim a key for this request.
     *
     * @return bool false when somebody else holds it — either in flight or
     *              already finished; the caller then reads {@see self::find()}
     */
    public function claim(string $tenantKey, string $endpoint, string $key): bool
    {
        return $this->store()->add(
            $this->cacheKey($tenantKey, $endpoint, $key),
            self::IN_FLIGHT,
            $this->ttlSeconds(),
        );
    }

    /**
     * The recorded response, or null when the key is free or still in flight.
     */
    public function find(string $tenantKey, string $endpoint, string $key): ?IdempotencyRecord
    {
        $stored = $this->store()->get($this->cacheKey($tenantKey, $endpoint, $key));

        if ($stored === self::IN_FLIGHT) {
            return null;
        }

        return is_array($stored) ? IdempotencyRecord::fromArray($stored) : null;
    }

    /** Is this key claimed but not yet answered? */
    public function isInFlight(string $tenantKey, string $endpoint, string $key): bool
    {
        return $this->store()->get($this->cacheKey($tenantKey, $endpoint, $key)) === self::IN_FLIGHT;
    }

    public function remember(string $tenantKey, string $endpoint, string $key, IdempotencyRecord $record): void
    {
        // `put`, not `add`: the claim is already there and this replaces it.
        // The TTL restarts from the answer rather than from the claim, which is
        // the twenty-four hours §3.4 promises a client *from the response*.
        $this->store()->put(
            $this->cacheKey($tenantKey, $endpoint, $key),
            $record->toArray(),
            $this->ttlSeconds(),
        );
    }

    /**
     * Give the key back.
     *
     * §3.4's fifth case: *"a request that failed with a **5xx** or a network
     * error is **not** recorded as a final response; retrying the same key
     * re-executes it."* Without this the claim would sit there for a day and a
     * client retrying a server error would be told its request is still in
     * flight — which is the one situation where a retry is exactly right.
     */
    public function release(string $tenantKey, string $endpoint, string $key): void
    {
        $this->store()->forget($this->cacheKey($tenantKey, $endpoint, $key));
    }

    /**
     * SHA-256 of the canonicalised body (§3.4).
     *
     * Canonicalised by **sorting keys recursively**, so a client that serialises
     * its JSON in a different order on the retry — which several HTTP libraries
     * do — is replaying the same request rather than being told it has a bug.
     * Values are untouched: `{"pax": 2}` and `{"pax": "2"}` are different
     * requests and a normaliser that made them equal would hide a real one.
     *
     * @param  array<array-key, mixed>  $body
     */
    public static function hash(array $body): string
    {
        return hash('sha256', (string) json_encode(
            self::sortRecursively($body),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function sortRecursively(array $value): array
    {
        // A *list* keeps its order: `pax: [adult, child]` and
        // `pax: [child, adult]` are the same booking, but `extras` with
        // quantities is not obviously so, and reordering a list to make two
        // requests match is a decision about the caller's data rather than
        // about its serialisation.
        $isList = array_is_list($value);

        foreach ($value as $index => $item) {
            if (is_array($item)) {
                $value[$index] = self::sortRecursively($item);
            }
        }

        if (! $isList) {
            ksort($value);
        }

        return $value;
    }

    private function cacheKey(string $tenantKey, string $endpoint, string $key): string
    {
        // The client's key is hashed rather than concatenated raw: it is opaque
        // client input landing in a cache key, and a Redis key namespace is not
        // the place to find out what a client considers a valid UUID.
        return sprintf('idem:%s:%s:%s', $tenantKey, $endpoint, hash('sha256', $key));
    }

    private function ttlSeconds(): int
    {
        return (int) config('kaiki.api.idempotency.retention_hours', 24) * 3600;
    }

    private function store(): Repository
    {
        $name = config('kaiki.api.idempotency.store');

        return is_string($name) && $name !== '' ? Cache::store($name) : Cache::store();
    }
}
