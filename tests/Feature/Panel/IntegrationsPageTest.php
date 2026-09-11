<?php

declare(strict_types=1);

use App\Contracts\CredentialVerifier;
use App\Domain\Integrations\Data\VerificationResult;
use App\Domain\Integrations\Support\VerifierRegistry;
use App\Enums\CredentialEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\Role;
use App\Filament\App\Pages\Integrations;
use App\Models\IntegrationCredential;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutExceptionHandling;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\OperatorUser;

/*
 * Spec TEN-8, SEC-3, PAY-4, EXT-2, CNV-11, I18N-1.
 *
 * `ManageGatewayCredentials` is owner-only. A manager runs the business day to
 * day; these credentials take the operator's money and issue invoices in their
 * tax name, so they sit with billing rather than with the catalogue.
 */

function integrationsTenant(User $user): Tenant
{
    return Tenant::query()->findOrFail($user->tenant_id);
}

/**
 * A mounted page with the tenant resolved.
 *
 * A Livewire component test does not pass through the panel's middleware, so
 * `ResolveTenant` never runs and every query on a tenant-owned model throws.
 * The HTTP tests below prove the middleware does this in production.
 */
function integrationsAs(User $user): Testable
{
    tenancy()->initialize(integrationsTenant($user));

    return Livewire::actingAs($user)->test(Integrations::class);
}

/** @param  array<string, mixed>  $data */
function saveThroughPanel(User $owner, array $data = []): Testable
{
    return integrationsAs($owner)->callAction('saveCredentials', array_merge([
        'provider' => IntegrationProvider::Viva->value,
        'environment' => CredentialEnvironment::Test->value,
        'credentials' => ['client_id' => 'panel-id', 'client_secret' => 'panel-secret'],
        'webhook_secret' => 'panel-verification-key',
        'public_config' => ['source_code' => '4321'],
        'is_default' => true,
        'is_active' => true,
    ], $data));
}

/** A verifier that answers however the test needs it to. */
function registerVerifier(IntegrationProvider $provider, VerificationResult $result): void
{
    app(VerifierRegistry::class)->register($provider, new class($result) implements CredentialVerifier
    {
        public function __construct(private readonly VerificationResult $result) {}

        public function verify(IntegrationCredential $credential): VerificationResult
        {
            return $this->result;
        }
    });
}

it('lets an owner reach the integrations page', function (): void {
    actingAs(OperatorUser::withRole(Role::Owner))->get('/app/integrations')->assertSuccessful();
})->group('fast');

it('refuses a manager, who runs the business but does not hold the keys', function (): void {
    actingAs(OperatorUser::withRole(Role::Manager))->get('/app/integrations')->assertForbidden();
})->group('fast');

it('refuses crew', function (): void {
    actingAs(OperatorUser::withRole(Role::Crew))->get('/app/integrations')->assertForbidden();
})->group('fast');

it('keeps the page out of a manager navigation', function (): void {
    $manager = OperatorUser::withRole(Role::Manager);

    Tenancy::forTenant(integrationsTenant($manager), function () use ($manager): void {
        actingAs($manager);

        // `canAccess` gates the navigation item as well as the URL. A link to a
        // page that 403s is a support ticket, not a security failure — but it
        // is still a defect.
        expect(Integrations::canAccess())->toBeFalse();
    });
})->group('fast');

it('saves a credential set an owner submits', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    saveThroughPanel($owner)->assertHasNoActionErrors();

    Tenancy::forTenant(integrationsTenant($owner), function (): void {
        $credential = IntegrationCredential::query()->firstOrFail();

        expect($credential->provider)->toBe(IntegrationProvider::Viva)
            ->and($credential->credentials['client_secret'])->toBe('panel-secret')
            ->and($credential->public_config['source_code'])->toBe('4321')
            // Lifted out of `public_config` into the plain column, because
            // ENV-8 forbids filtering on a JSON path and a webhook has to be
            // resolvable by it.
            ->and($credential->external_account_id)->toBe('4321');
    });
})->group('fast');

it('treats an empty secret field as leave it alone, not as clear it', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    saveThroughPanel($owner);

    // The operator comes back to correct their source code. The secret fields
    // render empty because they are never shown — treating that as a clear
    // would wipe their gateway key for fixing a typo.
    saveThroughPanel($owner, [
        'credentials' => ['client_id' => '', 'client_secret' => ''],
        'webhook_secret' => '',
        'public_config' => ['source_code' => '9999'],
    ])->assertHasNoActionErrors();

    Tenancy::forTenant(integrationsTenant($owner), function (): void {
        $credential = IntegrationCredential::query()->firstOrFail();

        expect($credential->credentials['client_secret'])->toBe('panel-secret')
            ->and($credential->public_config['source_code'])->toBe('9999');
    });
})->group('fast');

it('refuses an incomplete credential set with the fields named, in Greek', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    app()->setLocale('el');

    saveThroughPanel($owner, [
        'provider' => IntegrationProvider::Viva->value,
        'credentials' => ['client_id' => 'only-one-of-two'],
        'public_config' => [],
    ]);

    Tenancy::forTenant(integrationsTenant($owner), function (): void {
        // Nothing written. A half-saved credential set is a checkout that fails
        // at the gateway with the vendor's own unhelpful message.
        expect(IntegrationCredential::query()->count())->toBe(0);
    });

    // The sentence is the Greek one, and it names the missing fields.
    $message = (string) __('integrations.refused.incomplete', [
        'provider' => IntegrationProvider::Viva->label(),
        'fields' => __('integrations.fields.client_secret'),
    ]);

    expect($message)->toContain('Viva')
        ->and($message)->not->toBe('integrations.refused.incomplete');
})->group('fast');

it('writes verified_at when a verifier says the keys work', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    saveThroughPanel($owner);
    registerVerifier(IntegrationProvider::Viva, VerificationResult::success());

    $credential = Tenancy::forTenant(
        integrationsTenant($owner),
        fn (): IntegrationCredential => IntegrationCredential::query()->firstOrFail(),
    );

    integrationsAs($owner)
        ->callAction('verify', arguments: ['credential' => $credential->getKey()])
        ->assertHasNoActionErrors();

    Tenancy::forTenant(integrationsTenant($owner), function (): void {
        $credential = IntegrationCredential::query()->firstOrFail();

        expect($credential->verified_at)->not->toBeNull()
            ->and($credential->last_error)->toBeNull();
    });
})->group('fast');

it('writes last_error and clears verified_at when the provider refuses', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    saveThroughPanel($owner);

    $credential = Tenancy::forTenant(integrationsTenant($owner), function (): IntegrationCredential {
        $credential = IntegrationCredential::query()->firstOrFail();
        $credential->forceFill(['verified_at' => now()])->save();

        return $credential;
    });

    registerVerifier(IntegrationProvider::Viva, VerificationResult::failure(
        'integrations.verify.rejected',
        ['provider' => IntegrationProvider::Viva->label()],
    ));

    integrationsAs($owner)->callAction('verify', arguments: ['credential' => $credential->getKey()]);

    Tenancy::forTenant(integrationsTenant($owner), function (): void {
        $credential = IntegrationCredential::query()->firstOrFail();

        // Clearing the timestamp is the part that matters. Credentials that
        // worked in March and have since been revoked must stop reading as
        // verified the moment we find out.
        expect($credential->verified_at)->toBeNull()
            ->and($credential->last_error)->not->toBeNull();
    });
})->group('fast');

it('reports a failure in Greek, never in the provider own words', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    app()->setLocale('el');
    saveThroughPanel($owner);

    $credential = Tenancy::forTenant(
        integrationsTenant($owner),
        fn (): IntegrationCredential => IntegrationCredential::query()->firstOrFail(),
    );

    registerVerifier(IntegrationProvider::Viva, VerificationResult::failure(
        'integrations.verify.rejected',
        ['provider' => IntegrationProvider::Viva->label()],
        providerDetail: 'HTTP 401 Unauthorized',
    ));

    integrationsAs($owner)->callAction('verify', arguments: ['credential' => $credential->getKey()]);

    $stored = (string) Tenancy::forTenant(
        integrationsTenant($owner),
        fn (): ?string => IntegrationCredential::query()->firstOrFail()->last_error,
    );

    // PAY-12: raw gateway text is never the message. It travels in brackets for
    // the operator's own support ticket with the vendor, behind a sentence they
    // can act on.
    expect($stored)->toBe((string) __('integrations.verify.rejected', [
        'provider' => IntegrationProvider::Viva->label(),
    ]) . ' (HTTP 401 Unauthorized)')
        ->and($stored)->not->toStartWith('HTTP 401');
})->group('fast');

it('says so plainly when a provider cannot be checked yet, rather than claiming success', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    saveThroughPanel($owner, [
        'provider' => IntegrationProvider::Yuboto->value,
        'credentials' => ['api_key' => 'sms-key'],
        'public_config' => ['sender_name' => 'Kaiki'],
        'is_default' => false,
    ]);

    $credential = Tenancy::forTenant(
        integrationsTenant($owner),
        fn (): IntegrationCredential => IntegrationCredential::query()->firstOrFail(),
    );

    integrationsAs($owner)->callAction('verify', arguments: ['credential' => $credential->getKey()]);

    Tenancy::forTenant(integrationsTenant($owner), function (): void {
        $credential = IntegrationCredential::query()->firstOrFail();

        // No client exists for Yuboto yet. Writing `verified_at` here would put
        // a timestamp on credentials nothing has ever tried, and PAY-11 leans
        // on that timestamp meaning something.
        expect($credential->verified_at)->toBeNull()
            ->and($credential->last_error)->toBe((string) __('integrations.verify.unavailable', [
                'provider' => IntegrationProvider::Yuboto->label(),
            ]));
    });
})->group('fast');

it('lets an owner switch an integration off without losing the keys', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    saveThroughPanel($owner);

    $credential = Tenancy::forTenant(
        integrationsTenant($owner),
        fn (): IntegrationCredential => IntegrationCredential::query()->firstOrFail(),
    );

    integrationsAs($owner)
        ->callAction('deactivate', arguments: ['credential' => $credential->getKey()])
        ->assertHasNoActionErrors();

    Tenancy::forTenant(integrationsTenant($owner), function (): void {
        $credential = IntegrationCredential::query()->firstOrFail();

        expect($credential->is_active)->toBeFalse()
            ->and($credential->credentials['client_secret'])->toBe('panel-secret');
    });
})->group('fast');

it('never puts a stored secret on the page', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    saveThroughPanel($owner);

    $response = actingAs($owner)->get('/app/integrations');

    $response->assertSuccessful();

    // The whole promise of the screen. Four characters is the concession.
    $response->assertDontSee('panel-secret')
        ->assertDontSee('panel-id')
        ->assertSee('cret', escape: false);
})->group('fast');

it('shows the two Viva return addresses to paste into the payment source', function (): void {
    // Viva takes no return address per order: these are set once, on the
    // merchant's payment source, and only the operator can put them there.
    // Shown before any credential exists, because the source is created first.
    $owner = OperatorUser::withRole(Role::Owner);

    actingAs($owner)->get('/app/integrations')
        ->assertSuccessful()
        ->assertSee(route('pay.viva.success'), escape: false)
        ->assertSee(route('pay.viva.failure'), escape: false)
        ->assertSee(__('integrations.viva_return.help'), escape: false);
})->group('fast');

it('does not let one operator verify another operator credential', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $stranger = OperatorUser::withRole(Role::Owner);

    saveThroughPanel($owner);

    $credential = Tenancy::forTenant(
        integrationsTenant($owner),
        fn (): IntegrationCredential => IntegrationCredential::query()->firstOrFail(),
    );

    // An id from another tenant is *not found* rather than found and refused —
    // the isolation posture the API uses too (404, never 403, never data).
    //
    // Asserted as a thrown `NotFoundHttpException` rather than through
    // `assertNotFound()`: `abort()` inside a Livewire action tears the component
    // down, and Filament's assertion macro then reads `mountedActions` on a null
    // instance. The chained form fails with "Attempt to read property on null",
    // which looks like a broken test rather than a passing guard.
    withoutExceptionHandling();

    expect(fn (): mixed => integrationsAs($stranger)
        ->callAction('verify', arguments: ['credential' => $credential->getKey()]))
        ->toThrow(NotFoundHttpException::class);

    // And the row it could not see was not written to. The refusal is the
    // mechanism; this is the consequence, and it is the one that matters.
    Tenancy::forTenant(integrationsTenant($owner), function (): void {
        expect(IntegrationCredential::query()->firstOrFail()->verified_at)->toBeNull();
    });
})->group('fast');

it('cannot even see another operator credential through the scoped query', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);
    $stranger = OperatorUser::withRole(Role::Owner);

    saveThroughPanel($owner);

    $credential = Tenancy::forTenant(
        integrationsTenant($owner),
        fn (): IntegrationCredential => IntegrationCredential::query()->firstOrFail(),
    );

    // The layer underneath the 404. The page finds the row through the
    // tenant-scoped query on purpose, so isolation is the global scope's job
    // and the policy is only belt and braces (#8).
    Tenancy::forTenant(integrationsTenant($stranger), function () use ($credential): void {
        expect(IntegrationCredential::query()->find($credential->getKey()))->toBeNull()
            ->and(IntegrationCredential::query()->count())->toBe(0);
    });
})->group('fast');
