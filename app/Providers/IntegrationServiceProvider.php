<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Integrations\Support\CredentialRepository;
use App\Domain\Integrations\Support\VerifierRegistry;
use App\Domain\Payments\Gateways\FakeGateway;
use App\Domain\Payments\Gateways\VivaSmartCheckoutGateway;
use App\Domain\Payments\Support\GatewayResolver;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for the per-operator credential store (`docs/data-model.md` §2.7).
 *
 * ## Both are singletons, for different reasons
 *
 * {@see CredentialRepository} **must** be one, or its per-request memo is a
 * memo per resolution and the "decrypt once" requirement in §2.7 is satisfied
 * by nothing. A second container binding is not a performance regression here;
 * it is a silently failing requirement, which is why the repository exposes
 * `hasResolved()` for a test to assert against rather than leaving it to trust.
 *
 * {@see VerifierRegistry} is one so that registrations survive: a provider
 * registered in a service provider's `boot()` would be lost the next time
 * anything resolved a fresh instance.
 *
 * ## The registry is deliberately empty today
 *
 * The same shape as `GuardVesselCapacity::TAG` and `SaveProduct::TAG` in
 * {@see AppServiceProvider}, and for the same reason: #79 stores credentials,
 * and the clients that can *check* them arrive with the issues that introduce
 * them — Viva with the `PaymentGateway` contract, Postmark and the
 * SMS vendors with the notification issue, myDATA in M6. Each of those adds one
 * `register()` line here.
 *
 * Until then `VerifyIntegrationCredential` says so in Greek rather than
 * reporting success, and `VerifierCoverageTest` records which providers are
 * live so the gap stays visible in the suite.
 */
class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CredentialRepository::class);

        $this->app->singleton(VerifierRegistry::class, static function (): VerifierRegistry {
            return new VerifierRegistry;
        });

        // The three gateways and the resolver that picks between them (#82).
        //
        // Singletons because the Viva one caches an OAuth2 token per tenant for
        // the life of the request — a second instance would mint a second token
        // and double the latency of the slowest step in a booking. The fake is
        // one for a different reason: `failNext()` is state a test sets on the
        // instance the code under test will resolve.
        $this->app->singleton(FakeGateway::class);
        $this->app->singleton(VivaSmartCheckoutGateway::class);
        $this->app->singleton(GatewayResolver::class);
    }
}
