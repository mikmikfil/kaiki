<?php

declare(strict_types=1);

use App\Domain\Integrations\Actions\SaveIntegrationCredential;
use App\Domain\Integrations\Data\IntegrationCredentialData;
use App\Domain\Payments\Gateways\FakeGateway;
use App\Domain\Payments\Gateways\VivaSmartCheckoutGateway;
use App\Domain\Payments\Support\GatewayResolver;
use App\Enums\CredentialEnvironment;
use App\Enums\IntegrationProvider;
use App\Models\Booking;
use App\Models\IntegrationCredential;
use App\Models\Tenant;
use App\Support\Tenancy;
use Tests\Support\Secrets\CredentialLeakScanner;

/*
|--------------------------------------------------------------------------
| PAY-11 and SAA-9: sandbox must be impossible to enable by accident
|--------------------------------------------------------------------------
|
| The requirement is unusually blunt: switching modes *"MUST be impossible to
| enable accidentally on a live tenant"*, and it names three mechanisms —
| re-entering credentials, flagging bookings `is_test`, and a persistent banner.
|
| The reason is worth stating, because "sandbox mode" sounds harmless. An
| operator running live in sandbox takes bookings all day that charge nobody:
| guests get confirmations, seats come off sale, the boat sails full, and no
| money arrives. The failure is silent from every angle except the bank
| statement.
|
| The mechanism that actually prevents it is structural rather than procedural:
| **live and test are separate rows**, so switching is not a toggle at all. It
| is entering the other environment's credentials, which nobody does by
| accident.
|
*/

it('keeps live and test credentials as separate rows, so switching is not a toggle', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        foreach ([CredentialEnvironment::Live, CredentialEnvironment::Test] as $environment) {
            app(SaveIntegrationCredential::class)(new IntegrationCredentialData(
                provider: IntegrationProvider::Viva,
                environment: $environment,
                credentials: ['client_id' => 'id-' . $environment->value, 'client_secret' => 'secret-' . $environment->value],
                // Required since the Stripe removal made Viva's verification key
                // a declared field: `verifyWebhook()` has always read it and
                // refused without it, so a saved credential that lacked one was
                // a gateway whose callbacks could never be trusted.
                webhookSecret: 'key-' . $environment->value,
                isDefault: true,
            ));
        }

        // Two rows, unique on (tenant, provider, environment). There is no
        // column that means "which mode am I in" — which is the whole design:
        // a flag can be flipped by a stray click, and a credential set cannot
        // be entered by one.
        expect(IntegrationCredential::query()->where('provider', IntegrationProvider::Viva->value)->count())->toBe(2);

        $live = IntegrationCredential::query()->where('environment', CredentialEnvironment::Live->value)->firstOrFail();
        $test = IntegrationCredential::query()->where('environment', CredentialEnvironment::Test->value)->firstOrFail();

        // And they hold genuinely different secrets. A "switch" that reused the
        // live keys against a demo host is the accident PAY-11 names.
        expect($live->credentials['client_secret'])->not->toBe($test->credentials['client_secret']);
    });
})->group('fast');

it('never reuses a live credential for a test booking', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        // Live configured and verified; test not configured at all.
        IntegrationCredential::factory()
            ->forProvider(IntegrationProvider::Viva)
            ->live()
            ->verified()
            ->default()
            ->create();

        $test = Booking::factory()->create(['is_test' => true]);

        // The fake, not the live Viva gateway. Silently falling back to the
        // live credentials would put a *test* booking through a real merchant
        // account, which is the accident running in the opposite direction and
        // is the one that costs a guest money.
        expect(app(GatewayResolver::class)->forBooking($test))->toBeInstanceOf(FakeGateway::class);
    });
})->group('fast');

it('never reuses a test credential for a live booking', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        IntegrationCredential::factory()
            ->forProvider(IntegrationProvider::Viva)
            ->verified()
            ->default()
            ->create(['environment' => CredentialEnvironment::Test]);

        $live = Booking::factory()->create(['is_test' => false]);

        // Null, not the fake and not the test credentials. A live booking that
        // silently ran in sandbox is the exact failure PAY-11 exists to
        // prevent: the guest is confirmed and nobody is charged.
        expect(app(GatewayResolver::class)->forBooking($live))->toBeNull();
    });
})->group('fast');

it('decides the environment from the booking, with no parameter to get wrong', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        IntegrationCredential::factory()->forProvider(IntegrationProvider::Viva)->live()->verified()->default()->create();

        $live = Booking::factory()->create(['is_test' => false]);

        expect(app(GatewayResolver::class)->environmentFor($live))->toBe(CredentialEnvironment::Live)
            ->and(app(GatewayResolver::class)->forBooking($live))->toBeInstanceOf(VivaSmartCheckoutGateway::class);

        // `bookings.is_test` is written at creation and never changes, so the
        // environment is a fact about the booking rather than something a
        // caller can pass. A caller that could ask for `test` could ask for it
        // on a live booking.
        $reflection = new ReflectionMethod(GatewayResolver::class, 'environmentFor');

        expect($reflection->getNumberOfParameters())->toBe(1);
    });
})->group('fast');

it('has a persistent banner and a re-entry explanation, in both languages', function (): void {
    // PAY-11's third mechanism. The banner is the only thing standing between
    // an operator and a day of unpaid bookings, so it is a string that must
    // exist rather than one somebody remembers to add to a page.
    foreach (['el', 'en'] as $locale) {
        expect((string) trans('payments.sandbox.banner', [], $locale))
            ->not->toBe('payments.sandbox.banner')
            ->and((string) trans('payments.sandbox.switch_requires_credentials', [], $locale))
            ->not->toBe('payments.sandbox.switch_requires_credentials');
    }
})->group('fast', 'i18n');

it('leaks no credential from the gateway classes', function (): void {
    // #79's scanner, now pointed at the classes that actually *use* the
    // credentials rather than only the ones that store them. This is where a
    // `Log::debug($credential->credentials)` would go while somebody chased a
    // failing checkout, which is the exact moment SEC-9 and MYD-15 are most
    // likely to be forgotten.
    $findings = CredentialLeakScanner::scan(['app/Domain/Payments']);

    $report = array_map(
        static fn (array $f): string => "{$f['file']}:{$f['line']} — {$f['snippet']} ({$f['why']})",
        $findings,
    );

    expect($report)->toBe([], "credentials reachable from a log line or a screen:\n" . implode("\n", $report));
})->group('fast');
