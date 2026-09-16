<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\WeatherProvider;
use App\Domain\Integrations\Support\CredentialRepository;
use App\Domain\Integrations\Support\VerifierRegistry;
use App\Domain\Integrations\Verifiers\VivaCredentialVerifier;
use App\Domain\Operations\Weather\OpenMeteoProvider;
use App\Domain\Payments\Gateways\FakeGateway;
use App\Domain\Payments\Gateways\VivaSmartCheckoutGateway;
use App\Domain\Payments\Support\GatewayResolver;
use App\Enums\IntegrationProvider;
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
 * ## The registry has Viva in it, and nothing else yet
 *
 * #79 stored credentials, and the clients that can *check* them arrive with the
 * issues that introduce them — Postmark and the SMS vendors with the
 * notification issue, myDATA in M6. Each of those adds one `register()` line
 * here.
 *
 * Viva's arrived on 2026-09-16, later than it should have: `verified_at` gates
 * `usableForRealCall()`, so while nothing could set it, `GatewayResolver` found
 * no usable gateway and a sandbox tenant fell through to the fake checkout
 * rather than reaching Viva at all.
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

        $this->app->singleton(VerifierRegistry::class, function (): VerifierRegistry {
            $registry = new VerifierRegistry;

            // Viva, the one gateway (ADR-0028). Registered here rather than in
            // `boot()` because the registry is a singleton whose registrations
            // have to survive, and resolved lazily so that constructing the
            // registry does not construct an HTTP client.
            $registry->register(
                IntegrationProvider::Viva,
                $this->app->make(VivaCredentialVerifier::class),
            );

            return $registry;
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

        // The wind forecast (ADR-0027).
        //
        // Bound to the **interface**, which is the whole point: ADR-0027 says
        // outright that Open-Meteo's free tier is non-commercial and will have
        // to be swapped for their paid endpoint or another provider before this
        // reaches a paying operator. That swap is this one line.
        //
        // A singleton because the provider caches per place for hours, and a
        // second instance would be a second cache lookup for a dashboard that
        // asks once per vessel.
        $this->app->singleton(WeatherProvider::class, OpenMeteoProvider::class);
    }
}
