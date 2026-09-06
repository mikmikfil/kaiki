<?php

declare(strict_types=1);

namespace App\Domain\Api\Support;

/**
 * One remembered API response (`docs/api.md` §3.4).
 *
 * The contract requires a replay to return *"the recorded response **verbatim**,
 * including its original HTTP status (a replayed create still returns `201`,
 * not `200`)"*. So the status is stored, not inferred — a store that kept only
 * the body would replay a creation as a `200` and quietly break every client
 * that branches on it.
 *
 * `headers` holds only the ones §3.4 names — `Location` and the cache header —
 * rather than the whole response. Replaying `Date`, `Set-Cookie` or a request
 * id from yesterday would be worse than not replaying them.
 */
final readonly class IdempotencyRecord
{
    /**
     * @param  string  $bodyHash  SHA-256 of the canonicalised request body
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $bodyHash,
        public int $status,
        public string $body,
        public array $headers = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'body_hash' => $this->bodyHash,
            'status' => $this->status,
            'body' => $this->body,
            'headers' => $this->headers,
        ];
    }

    /** @param array<string, mixed> $stored */
    public static function fromArray(array $stored): self
    {
        /** @var array<string, string> $headers */
        $headers = is_array($stored['headers'] ?? null) ? $stored['headers'] : [];

        return new self(
            bodyHash: (string) ($stored['body_hash'] ?? ''),
            status: (int) ($stored['status'] ?? 0),
            body: (string) ($stored['body'] ?? ''),
            headers: $headers,
        );
    }
}
