<?php

declare(strict_types=1);

use App\Domain\Integrations\Support\VerifierRegistry;
use App\Enums\ApiKeyEnvironment;
use App\Enums\CredentialEnvironment;
use App\Enums\IntegrationProvider;

/*
 * Spec PAY-4, PAY-11, and the ADR-0004 reconciliation.
 *
 * ADR-0004 Option A wrote `mode: live | sandbox`; `api_keys.environment` was
 * already `live | test`. #79 reconciled the two to `test` and recorded the
 * reason in `CHANGELOG.md` per `docs/api.md` §10 item 5. These tests are what
 * keeps the reconciliation true — a vocabulary settled once in a document
 * drifts the first time somebody adds a case to one enum and not the other.
 */

it('speaks exactly the same vocabulary as api_keys.environment', function (): void {
    $credential = array_map(static fn (CredentialEnvironment $c): string => $c->value, CredentialEnvironment::cases());
    $apiKey = array_map(static fn (ApiKeyEnvironment $c): string => $c->value, ApiKeyEnvironment::cases());

    // PAY-11 pairs them directly: sandbox mode means a `pk_test_` key reaching
    // test gateway credentials. Two words for one concept across two tables is
    // how a query eventually asks the wrong one.
    expect($credential)->toBe($apiKey);
})->group('fast');

it('has no case called sandbox, which is the word the ADR used', function (): void {
    expect(CredentialEnvironment::tryFrom('sandbox'))->toBeNull();
})->group('fast');

it('maps an api key environment to the credentials it must reach', function (): void {
    expect(CredentialEnvironment::forApiKey(ApiKeyEnvironment::Test))->toBe(CredentialEnvironment::Test)
        ->and(CredentialEnvironment::forApiKey(ApiKeyEnvironment::Live))->toBe(CredentialEnvironment::Live);
})->group('fast');

it('gives every provider the fields it actually needs', function (): void {
    foreach (IntegrationProvider::cases() as $provider) {
        expect($provider->credentialFields())->not->toBeEmpty(
            "{$provider->value} has no credential fields, so nothing can be stored for it",
        );

        // A field name that is also a public field would be stored in two
        // columns, one encrypted and one not — the shape that puts a secret in
        // the readable half.
        expect(array_intersect($provider->credentialFields(), $provider->publicFields()))->toBe([]);
    }
})->group('fast');

it('only treats the two gateways as competing for a default', function (): void {
    expect(IntegrationProvider::paymentGateways())
        ->toBe([IntegrationProvider::Viva, IntegrationProvider::Stripe]);
})->group('fast');

it('records which providers can be verified, so the gap stays visible', function (): void {
    // Deliberately an assertion about the *current* state rather than about
    // completeness. Verification clients arrive with the issues that introduce
    // them — Viva and Stripe with the `PaymentGateway` contract, Postmark and
    // the SMS vendors with the notification issue, myDATA in M6 — and each will
    // change this number by one. A test that merely allowed an empty registry
    // would never notice if the wiring broke.
    expect(app(VerifierRegistry::class)->registered())->toBe([]);
})->group('fast');

it('names an external account field only where the provider puts one in a webhook', function (): void {
    expect(IntegrationProvider::Stripe->externalAccountField())->toBe('account_id')
        ->and(IntegrationProvider::Viva->externalAccountField())->toBe('source_code')
        // The SMS vendors and AADE do not call back at all, so there is nothing
        // to resolve and a column value would be noise.
        ->and(IntegrationProvider::Postmark->externalAccountField())->toBeNull();
})->group('fast');

it('keeps every external account field inside its provider public fields', function (): void {
    foreach (IntegrationProvider::cases() as $provider) {
        $field = $provider->externalAccountField();

        if ($field === null) {
            continue;
        }

        // Otherwise `IntegrationCredentialData::externalAccountId()` reads a key
        // the form never collects, the plain column stays null, and the webhook
        // resolver silently finds nothing.
        expect($provider->publicFields())->toContain($field);
    }
})->group('fast');
