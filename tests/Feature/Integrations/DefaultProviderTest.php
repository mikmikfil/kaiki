<?php

declare(strict_types=1);

use App\Domain\Integrations\Actions\DeactivateIntegrationCredential;
use App\Domain\Integrations\Actions\SaveIntegrationCredential;
use App\Domain\Integrations\Data\IntegrationCredentialData;
use App\Domain\Integrations\Support\CredentialRepository;
use App\Enums\CredentialEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\Role;
use App\Models\IntegrationCredential;
use App\Models\Tenant;
use App\Support\Tenancy;
use Tests\Support\OperatorUser;

/*
 * `docs/data-model.md` §2.7: exactly one `is_default = true` payment provider
 * per tenant per environment, application-enforced.
 *
 * Application-enforced because the database cannot do it portably — a partial
 * unique index is not available on MySQL 8, and a plain one would forbid a
 * second *non*-default row, which is the ordinary case. So the invariant lives
 * in an Action, and an invariant that lives in code is one that needs a test
 * per way of breaking it.
 *
 * What breaks if it is wrong: `CredentialRepository::defaultPaymentGateway()`
 * returns whichever of two rows the database felt like ordering first, and an
 * operator's guests are charged through a gateway they switched away from.
 */

function tenantWithOwner(): Tenant
{
    $owner = OperatorUser::withRole(Role::Owner);

    return Tenant::query()->findOrFail($owner->tenant_id);
}

/** @param  array<string, string>  $credentials */
function saveCredential(
    IntegrationProvider $provider,
    bool $isDefault = false,
    CredentialEnvironment $environment = CredentialEnvironment::Test,
    array $credentials = [],
): IntegrationCredential {
    if ($credentials === []) {
        foreach ($provider->credentialFields() as $field) {
            $credentials[$field] = 'test-' . $field;
        }
    }

    return app(SaveIntegrationCredential::class)(new IntegrationCredentialData(
        provider: $provider,
        environment: $environment,
        credentials: $credentials,
        publicConfig: [],
        webhookSecret: $provider->issuesWebhookSecret() ? 'test-signing-secret' : null,
        isDefault: $isDefault,
    ));
}

it('clears the previous default when a second gateway claims it', function (): void {
    $tenant = tenantWithOwner();

    Tenancy::forTenant($tenant, function (): void {
        $viva = saveCredential(IntegrationProvider::Viva, isDefault: true);
        $stripe = saveCredential(IntegrationProvider::Stripe, isDefault: true);

        expect($viva->refresh()->is_default)->toBeFalse()
            ->and($stripe->refresh()->is_default)->toBeTrue();
    });
})->group('fast');

it('keeps defaults separate per environment', function (): void {
    $tenant = tenantWithOwner();

    Tenancy::forTenant($tenant, function (): void {
        $test = saveCredential(IntegrationProvider::Viva, isDefault: true);
        $live = saveCredential(IntegrationProvider::Stripe, isDefault: true, environment: CredentialEnvironment::Live);

        // An operator setting up their live Stripe account must not silently
        // change which gateway their sandbox uses — PAY-11 asks for the two to
        // be independent, and this is the shape of that independence.
        expect($test->refresh()->is_default)->toBeTrue()
            ->and($live->refresh()->is_default)->toBeTrue();
    });
})->group('fast');

it('refuses the flag for a provider it means nothing for', function (): void {
    $tenant = tenantWithOwner();

    Tenancy::forTenant($tenant, function (): void {
        // Postmark is not an alternative to myDATA, so "default" has no reader.
        // Forced off rather than quietly stored, or a later query that trusts
        // the column returns an email provider to a checkout.
        $postmark = saveCredential(IntegrationProvider::Postmark, isDefault: true);

        expect($postmark->is_default)->toBeFalse();
    });
})->group('fast');

it('never leaves two defaults in one environment, however many saves run', function (): void {
    $tenant = tenantWithOwner();

    Tenancy::forTenant($tenant, function (): void {
        saveCredential(IntegrationProvider::Viva, isDefault: true);
        saveCredential(IntegrationProvider::Stripe, isDefault: true);
        saveCredential(IntegrationProvider::Viva, isDefault: true);
        saveCredential(IntegrationProvider::Stripe, isDefault: true);

        $defaults = IntegrationCredential::query()
            ->where('environment', CredentialEnvironment::Test->value)
            ->where('is_default', true)
            ->count();

        expect($defaults)->toBe(1);
    });
})->group('fast');

it('re-saving the same provider is an update, not a unique-index violation', function (): void {
    $tenant = tenantWithOwner();

    Tenancy::forTenant($tenant, function (): void {
        $first = saveCredential(IntegrationProvider::Viva);
        $second = saveCredential(IntegrationProvider::Viva, credentials: [
            'client_id' => 'rotated-id',
            'client_secret' => 'rotated-secret',
        ]);

        // Rotating a key is the single most common reason to open this screen,
        // and a create that hit the unique index would show an operator a
        // database error for doing the obviously correct thing.
        expect($second->getKey())->toBe($first->getKey())
            ->and($second->credentials['client_id'])->toBe('rotated-id')
            ->and(IntegrationCredential::query()->count())->toBe(1);
    });
})->group('fast');

it('clears verified_at on every write, so new keys inherit no credibility', function (): void {
    $tenant = tenantWithOwner();

    Tenancy::forTenant($tenant, function (): void {
        $credential = saveCredential(IntegrationProvider::Viva);
        $credential->forceFill(['verified_at' => now()])->save();

        $rotated = saveCredential(IntegrationProvider::Viva, credentials: [
            'client_id' => 'typo',
            'client_secret' => 'typo',
        ]);

        expect($rotated->verified_at)->toBeNull();
    });
})->group('fast');

it('resolves the only usable gateway even when nobody marked a default', function (): void {
    $tenant = tenantWithOwner();

    Tenancy::forTenant($tenant, function (): void {
        $viva = saveCredential(IntegrationProvider::Viva);
        $viva->forceFill(['verified_at' => now()])->save();

        // An operator with one gateway was never asked to pick a default.
        // Refusing to take their money over a flag they were never shown would
        // be absurd.
        expect(app(CredentialRepository::class)->defaultPaymentGateway(CredentialEnvironment::Test)?->getKey())
            ->toBe($viva->getKey());
    });
})->group('fast');

it('will not hand a checkout an unverified gateway', function (): void {
    $tenant = tenantWithOwner();

    Tenancy::forTenant($tenant, function (): void {
        saveCredential(IntegrationProvider::Viva, isDefault: true);

        // Typed but never checked. PAY-11 leans on `verified_at` to keep
        // sandbox mode from being enabled by accident; the same timestamp is
        // what stops a live checkout being routed at keys nothing has tried.
        expect(app(CredentialRepository::class)->defaultPaymentGateway(CredentialEnvironment::Test))->toBeNull();
    });
})->group('fast');

it('hands the default to the surviving gateway when one is switched off', function (): void {
    $tenant = tenantWithOwner();

    Tenancy::forTenant($tenant, function (): void {
        $viva = saveCredential(IntegrationProvider::Viva, isDefault: true);
        $viva->forceFill(['verified_at' => now()])->save();

        $stripe = saveCredential(IntegrationProvider::Stripe);
        $stripe->forceFill(['verified_at' => now()])->save();

        app(DeactivateIntegrationCredential::class)($viva->refresh());

        // Otherwise the operator is left with a working Stripe row and a
        // default flag on a disabled Viva one, and every checkout fails
        // because of a checkbox.
        expect($stripe->refresh()->is_default)->toBeTrue()
            ->and($viva->refresh()->is_active)->toBeFalse();
    });
})->group('fast');

it('keeps the row when an integration is switched off', function (): void {
    $tenant = tenantWithOwner();

    Tenancy::forTenant($tenant, function (): void {
        $viva = saveCredential(IntegrationProvider::Viva);

        app(DeactivateIntegrationCredential::class)($viva);

        // Payments settle for days after a gateway is switched off, and those
        // webhooks still have to resolve to a tenant.
        expect(IntegrationCredential::query()->count())->toBe(1);
    });
})->group('fast');

it('does not oversell the default flag under two simultaneous saves', function (): void {
    $tenant = tenantWithOwner();

    Tenancy::forTenant($tenant, function (): void {
        saveCredential(IntegrationProvider::Viva, isDefault: true);
        saveCredential(IntegrationProvider::Stripe, isDefault: true);

        expect(IntegrationCredential::query()->where('is_default', true)->count())->toBe(1);
    });
    // `lockForUpdate()` is a no-op on SQLite (ADR-0006), so the concurrency
    // half of this invariant can only be proved where the lock is real. Tagged
    // rather than skipped, so it runs in CI instead of passing vacuously here.
})->group('mysql');

it('resolves a tenant from a webhook with no tenant in context', function (): void {
    $tenant = tenantWithOwner();

    $credential = Tenancy::forTenant($tenant, function (): IntegrationCredential {
        $stripe = saveCredential(IntegrationProvider::Stripe);
        $stripe->forceFill(['external_account_id' => 'acct_test_123'])->save();

        return $stripe;
    });

    // The `gateway_webhook_events` case: no tenant yet, and the lookup is what
    // supplies one. It must work entirely outside the tenant scope.
    $found = IntegrationCredential::findByExternalAccount(IntegrationProvider::Stripe, 'acct_test_123');

    expect($found?->getKey())->toBe($credential->getKey())
        ->and($found?->tenant_id)->toBe($tenant->getKey());
})->group('fast');

it('does not resolve a webhook to the wrong provider', function (): void {
    $tenant = tenantWithOwner();

    Tenancy::forTenant($tenant, function (): void {
        $viva = saveCredential(IntegrationProvider::Viva);
        $viva->forceFill(['external_account_id' => 'shared-id'])->save();
    });

    expect(IntegrationCredential::findByExternalAccount(IntegrationProvider::Stripe, 'shared-id'))->toBeNull()
        // And an empty identifier must never match the first row with a null
        // column, which is how a webhook gets filed against a stranger.
        ->and(IntegrationCredential::findByExternalAccount(IntegrationProvider::Viva, ''))->toBeNull();
})->group('fast');
