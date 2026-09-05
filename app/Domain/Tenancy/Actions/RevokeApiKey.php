<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Events\ApiKeyRevoked;
use App\Models\ApiKey;

/**
 * Revocation is a timestamp, never a delete (data-model §2.1).
 *
 * A deleted row would free its prefix for reissue and erase the evidence of
 * what a leaked key could reach. Revoking twice is a no-op rather than an
 * error — an operator clicking twice should not see a failure.
 *
 * ## The audit event fires here, not in the panel
 *
 * #10 satisfied SEC-16 with a `Log::info` inside the Filament action's closure,
 * which audited exactly one caller. ADR-0025 replaced it with a row, and the
 * event belongs on the Action so that the panel, a future API revocation and an
 * importer are all audited by doing the thing rather than by remembering to.
 *
 * **Inside the `isRevoked()` guard**, so a double click is one row. An audit
 * trail that logs the click rather than the change is a trail that cannot be
 * counted, and "exactly one row per action" is the acceptance criterion.
 */
final class RevokeApiKey
{
    public function __invoke(ApiKey $apiKey, ?string $reason = null): ApiKey
    {
        if ($apiKey->isRevoked()) {
            return $apiKey;
        }

        $apiKey->forceFill(['revoked_at' => now()])->save();

        ApiKeyRevoked::dispatch($apiKey, $reason);

        return $apiKey;
    }
}
