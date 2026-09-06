<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Actions;

use App\Domain\Integrations\Support\CredentialRepository;
use App\Models\IntegrationCredential;
use Illuminate\Support\Facades\DB;

/**
 * Switch an integration off without throwing the keys away (spec PAY-4).
 *
 * ## A flag, not a delete
 *
 * Same reasoning as `api_keys.revoked_at`. An operator switching gateways mid
 * season wants the old row to still be there when the new one turns out to need
 * a merchant-account approval that takes a week. Deleting it would also break
 * the `gateway_webhook_events` lookup that resolves a tenant from a webhook —
 * and payments settle for days after a gateway is switched off, so those
 * webhooks keep arriving.
 *
 * ## Deactivating the default hands the flag over
 *
 * A tenant with Viva as default who switches Viva off would otherwise be left
 * with a Stripe row that works and a default flag on a row that does not, so
 * `defaultPaymentGateway()` returns the disabled one and every checkout fails.
 * Moving the flag to the remaining usable gateway is the behaviour an operator
 * expects from "turn this one off"; leaving them with nothing would be a
 * checkout outage caused by a checkbox.
 */
final class DeactivateIntegrationCredential
{
    public function __construct(private readonly CredentialRepository $repository) {}

    public function __invoke(IntegrationCredential $credential): IntegrationCredential
    {
        if (! $credential->is_active) {
            return $credential;
        }

        DB::transaction(function () use ($credential): void {
            $credential->forceFill(['is_active' => false, 'is_default' => false])->save();

            if (! $credential->provider->isPaymentGateway()) {
                return;
            }

            $successor = IntegrationCredential::query()
                ->where('environment', $credential->environment->value)
                ->where('provider', '!=', $credential->provider->value)
                ->where('is_active', true)
                ->lockForUpdate()
                ->get()
                ->first(fn (IntegrationCredential $other): bool => $other->provider->isPaymentGateway() && $other->isUsable());

            $successor?->forceFill(['is_default' => true])->save();
        });

        $this->repository->forgetTenant($credential->tenant_id);

        return $credential;
    }
}
