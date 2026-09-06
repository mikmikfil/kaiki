<?php

declare(strict_types=1);

namespace Tests\Support\Secrets\Fixtures;

use App\Models\IntegrationCredential;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Support\Secrets\CredentialLeakScanner;

/**
 * A file that is permanently wrong, so the scanner fails the day it stops
 * working rather than the day somebody notices.
 *
 * Same device as `Tests\Support\Vat\Fixtures\HardcodedVatRates`. Every method
 * below is one of the shapes {@see CredentialLeakScanner}
 * claims to detect. Nothing here is ever executed and nothing imports it except
 * the test that points the scanner at it.
 *
 * @codeCoverageIgnore
 */
final class LeakedCredentials
{
    /** The one that actually happens: chasing a failing checkout with a log line. */
    public function logsThem(IntegrationCredential $credential): void
    {
        Log::debug('gateway', ['keys' => $credential->credentials]);
    }

    /** An exception message is the most-copied string in any codebase. */
    public function throwsWithThem(IntegrationCredential $credential): void
    {
        throw new RuntimeException($credential->webhook_secret ?? '');
    }

    /** A credential on screen is a credential in a screenshot in a ticket. */
    public function rendersThem(IntegrationCredential $credential): string
    {
        return $this->field()->default($credential->credentials['secret_key'] ?? '');
    }

    /**
     * One word, looks like a kindness.
     *
     * Written wrapped, because that is what Pint does to a chain this long and
     * the scanner has to find it across the three lines rather than on one.
     */
    public function revealsThem(): mixed
    {
        return $this->input('credentials.secret_key')
            ->password()
            ->revealable();
    }

    private function field(): mixed
    {
        return $this->input('credentials.secret_key');
    }

    private function input(string $path): mixed
    {
        return new class($path)
        {
            public function __construct(private readonly string $path) {}

            public function default(mixed $value): string
            {
                return $this->path . (string) $value;
            }

            public function password(): self
            {
                return $this;
            }

            public function revealable(): self
            {
                return $this;
            }
        };
    }
}
