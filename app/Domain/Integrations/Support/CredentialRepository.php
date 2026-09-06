<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Support;

use App\Domain\Integrations\Actions\SaveIntegrationCredential;
use App\Enums\CredentialEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\IntegrationCredential;
use App\Support\Tenancy;

/**
 * The one way to read an operator's credentials (`docs/data-model.md` §2.7).
 *
 * §2.7: *"credentials are read through a cached repository … so the encrypted
 * column is not decrypted on every request"*. Decryption is not free, and the
 * checkout path asks for the same row more than once — resolving the gateway,
 * building the session, and again when the webhook comes back.
 *
 * ## Two layers, for two different costs
 *
 * **A per-request memo** (`$resolved`) is what actually satisfies the "decrypt
 * once" requirement. The repository is a singleton, so a second call in the
 * same request returns the same hydrated model and no `openssl_decrypt` runs.
 *
 * **No cross-request cache**, deliberately, and this is the part worth
 * explaining because §2.7 can be read as asking for one. Laravel's cache is
 * Redis in production (ENV-1). Putting a decrypted credential set in it would
 * move every operator's live gateway secret from a column that needs `APP_KEY`
 * into a store that needs nothing but a connection — undoing PAY-3 to save one
 * `SELECT` on a table with a few rows per tenant. What *is* cached across
 * requests is the far cheaper and non-secret question of which provider is
 * default, because that one is asked on every page of the panel.
 *
 * ## Busting is by tenant, not by row
 *
 * A save can flip another row's `is_default` in the same transaction
 * ({@see SaveIntegrationCredential}), so
 * invalidating only the row that was written would leave a stale answer for the
 * one that changed underneath it.
 */
final class CredentialRepository
{
    /**
     * Hydrated models, keyed `tenant:provider:environment`.
     *
     * @var array<string, IntegrationCredential|null>
     */
    private array $resolved = [];

    /**
     * Fetch one credential set, decrypting at most once per request.
     *
     * Returns inactive rows too. Whether an integration may be *used* is
     * {@see IntegrationCredential::isUsable()}, and a caller that wants the row
     * in order to show the operator why it is switched off must be able to get
     * it.
     */
    public function find(
        IntegrationProvider $provider,
        CredentialEnvironment $environment,
        ?int $tenantId = null,
    ): ?IntegrationCredential {
        $tenantId ??= Tenancy::current()?->getKey();

        if ($tenantId === null) {
            return null;
        }

        $key = $this->key((int) $tenantId, $provider, $environment);

        if (array_key_exists($key, $this->resolved)) {
            return $this->resolved[$key];
        }

        // `withoutTenancy` plus an explicit `tenant_id`, rather than leaning on
        // the global scope: a queued job resolving credentials for a webhook has
        // no ambient tenant, and a repository that only works inside a request
        // is one the webhook path would have to route around.
        return $this->resolved[$key] = Tenancy::withoutTenancy(
            static fn (): ?IntegrationCredential => IntegrationCredential::query()
                ->where('tenant_id', $tenantId)
                ->where('provider', $provider->value)
                ->where('environment', $environment->value)
                ->first(),
        );
    }

    /**
     * The payment gateway a checkout should use (§2.7, PAY-4).
     *
     * `is_default` first, then the only usable one. The fallback matters: an
     * operator who has configured exactly one gateway has never been asked to
     * pick a default, and refusing to take their money over a flag they were
     * never shown would be absurd.
     */
    public function defaultPaymentGateway(
        CredentialEnvironment $environment,
        ?int $tenantId = null,
    ): ?IntegrationCredential {
        $usable = [];

        foreach (IntegrationProvider::paymentGateways() as $provider) {
            $credential = $this->find($provider, $environment, $tenantId);

            if ($credential !== null && $credential->isUsable()) {
                $usable[] = $credential;
            }
        }

        foreach ($usable as $credential) {
            if ($credential->is_default) {
                return $credential;
            }
        }

        return count($usable) === 1 ? $usable[0] : null;
    }

    /**
     * Forget everything remembered for one tenant.
     *
     * Called by the save Action inside its transaction's aftermath, never by a
     * caller who remembers to. A repository whose invalidation is somebody
     * else's responsibility is a cache that goes stale on the one path that
     * skipped it.
     */
    public function forgetTenant(int $tenantId): void
    {
        $prefix = $tenantId . ':';

        foreach (array_keys($this->resolved) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->resolved[$key]);
            }
        }
    }

    /** Test seam: prove the second read did not hit the database. */
    public function hasResolved(
        int $tenantId,
        IntegrationProvider $provider,
        CredentialEnvironment $environment,
    ): bool {
        return array_key_exists($this->key($tenantId, $provider, $environment), $this->resolved);
    }

    private function key(int $tenantId, IntegrationProvider $provider, CredentialEnvironment $environment): string
    {
        return $tenantId . ':' . $provider->value . ':' . $environment->value;
    }
}
