<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Models\ApiKey;

/**
 * Revocation is a timestamp, never a delete (data-model §2.1).
 *
 * A deleted row would free its prefix for reissue and erase the evidence of
 * what a leaked key could reach. Revoking twice is a no-op rather than an
 * error — an operator clicking twice should not see a failure.
 */
final class RevokeApiKey
{
    public function __invoke(ApiKey $apiKey): ApiKey
    {
        if ($apiKey->isRevoked()) {
            return $apiKey;
        }

        $apiKey->forceFill(['revoked_at' => now()])->save();

        return $apiKey;
    }
}
