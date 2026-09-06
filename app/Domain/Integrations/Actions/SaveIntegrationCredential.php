<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Actions;

use App\Domain\Integrations\Data\IntegrationCredentialData;
use App\Domain\Integrations\Support\CredentialRepository;
use App\Exceptions\IntegrationCredentialIncomplete;
use App\Models\IntegrationCredential;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * Create or replace one operator's credentials for one provider (spec PAY-4).
 *
 * ## Why this is an upsert and not a create
 *
 * The unique index is (`tenant_id`, `provider`, `environment`) — one credential
 * set per provider per environment is the shape ADR-0004 chose. An operator
 * re-pasting a rotated Stripe key is editing the same row, and a create that
 * hits the index would show them a database error for doing the obviously
 * correct thing.
 *
 * ## Verification is cleared on every write
 *
 * `verified_at` says *these exact keys made a real call succeed*. New keys have
 * not, so the timestamp is nulled and the operator must press verify again.
 * Carrying it over would let a typo inherit the credibility of the keys it
 * replaced, and PAY-11 leans on that timestamp to keep sandbox mode from being
 * enabled by accident.
 *
 * ## The default flag is settled inside the transaction
 *
 * §2.7: exactly one `is_default = true` payment provider per tenant per
 * environment, application-enforced. Two operators saving at once, or one
 * double-clicking, would otherwise both read "no default yet" and both write
 * one. The clearing update and the insert are one transaction with the other
 * rows locked, so the second save waits and sees the first.
 *
 * A partial unique index would be the database enforcing it, and partial
 * indexes are not portable to MySQL 8 — the same constraint that shapes every
 * other decision in `docs/data-model.md` §6.
 */
final class SaveIntegrationCredential
{
    public function __construct(private readonly CredentialRepository $repository) {}

    /**
     * @throws IntegrationCredentialIncomplete when the provider's required fields are not all present
     */
    public function __invoke(IntegrationCredentialData $data): IntegrationCredential
    {
        $missing = $data->missingFields();

        if ($missing !== []) {
            throw IntegrationCredentialIncomplete::missing($data->provider, $missing);
        }

        $tenantId = Tenancy::current()?->getKey();

        $credential = DB::transaction(function () use ($data, $tenantId): IntegrationCredential {
            // Only payment gateways compete for a default; myDATA and Postmark
            // are not alternatives to each other, so the flag has no meaning
            // for them and is forced off rather than quietly stored.
            $isDefault = $data->isDefault && $data->provider->isPaymentGateway();

            if ($isDefault) {
                $this->clearOtherDefaults($data, $tenantId);
            }

            $credential = IntegrationCredential::query()->firstOrNew([
                'provider' => $data->provider,
                'environment' => $data->environment,
            ]);

            $credential->forceFill([
                'credentials' => $data->knownCredentials(),
                'public_config' => $data->knownPublicConfig(),
                'external_account_id' => $data->externalAccountId(),
                'webhook_secret' => $data->webhookSecret,
                'is_default' => $isDefault,
                'is_active' => $data->isActive,
                // New keys have proved nothing.
                'verified_at' => null,
                'last_error' => null,
            ])->save();

            return $credential;
        });

        if ($tenantId !== null) {
            $this->repository->forgetTenant((int) $tenantId);
        }

        return $credential;
    }

    /**
     * Take the flag off every other payment gateway in this environment.
     *
     * `lockForUpdate` because this is the read that the write depends on, and
     * the two-parallel-saves case is the one the criterion names. It is a no-op
     * on SQLite, exactly as ADR-0006 says of the overselling lock — so the
     * concurrency half of `DefaultProviderTest` is tagged `mysql` and proves
     * itself in CI rather than passing vacuously here.
     */
    private function clearOtherDefaults(IntegrationCredentialData $data, ?int $tenantId): void
    {
        $others = IntegrationCredential::query()
            ->where('environment', $data->environment->value)
            ->where('provider', '!=', $data->provider->value)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->lockForUpdate()
            ->get();

        foreach ($others as $other) {
            if ($other->is_default) {
                $other->forceFill(['is_default' => false])->save();
            }
        }
    }
}
