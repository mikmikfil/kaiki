<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

use App\Contracts\CredentialVerifier;
use App\Enums\IntegrationProvider;

/**
 * Which {@see CredentialVerifier} answers for which provider.
 *
 * ## Why a registry and not a `match` in the Action
 *
 * Because the providers arrive across four milestones. Viva
 * come with the gateway contract, Postmark and the SMS vendors with the
 * notification issue, myDATA in M6 — and a `match` in the Action would be a
 * file three separate issues have to remember to edit. A registry is a line in
 * a service provider next to the client that is being introduced.
 *
 * ## An unregistered provider is answered, not pretended
 *
 * The important behaviour. Returning "verified" for a provider with no client
 * yet would write `verified_at` on credentials nothing has ever tried, and
 * PAY-11's promise that sandbox mode *"MUST be impossible to enable
 * accidentally"* leans directly on that timestamp meaning something. So an
 * unregistered provider fails with a plain Greek sentence saying verification
 * for it is not available yet, which is true, actionable, and cannot be
 * mistaken for success.
 */
final class VerifierRegistry
{
    /** @var array<string, CredentialVerifier> */
    private array $verifiers = [];

    public function register(IntegrationProvider $provider, CredentialVerifier $verifier): void
    {
        $this->verifiers[$provider->value] = $verifier;
    }

    public function for(IntegrationProvider $provider): ?CredentialVerifier
    {
        return $this->verifiers[$provider->value] ?? null;
    }

    public function has(IntegrationProvider $provider): bool
    {
        return isset($this->verifiers[$provider->value]);
    }

    /**
     * The providers that can currently be verified.
     *
     * Read by a test that records which are live, so the gap between the seven
     * providers and their clients stays visible in the suite rather than being
     * rediscovered by an operator pressing a button that never works.
     *
     * @return list<IntegrationProvider>
     */
    public function registered(): array
    {
        return array_values(array_filter(
            IntegrationProvider::cases(),
            fn (IntegrationProvider $provider): bool => $this->has($provider),
        ));
    }
}
