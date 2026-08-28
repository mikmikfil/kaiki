<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Data;

use App\Models\ApiKey;
use Spatie\LaravelData\Data;

/**
 * The one and only time the plaintext key exists (spec TEN-3, CNV-13).
 *
 * `plainTextKey` is deliberately not part of any array or JSON representation:
 * this object is handed straight to the reveal-once UI (#10) and must not be
 * serialisable into a log line, a queued job payload or a Sentry breadcrumb by
 * accident.
 */
final class GeneratedApiKeyData extends Data
{
    public function __construct(
        public readonly ApiKey $apiKey,
        public readonly string $plainTextKey,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->apiKey->getKey(),
            'name' => $this->apiKey->name,
            'type' => $this->apiKey->type->value,
            'environment' => $this->apiKey->environment->value,
            'prefix' => $this->apiKey->prefix,
            'last_four' => $this->apiKey->last_four,
            // plainTextKey is absent on purpose. Read it from the property.
        ];
    }

    public function __toString(): string
    {
        return '[redacted api key]';
    }
}
