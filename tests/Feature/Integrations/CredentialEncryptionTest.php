<?php

declare(strict_types=1);

use App\Domain\Integrations\Support\CredentialRepository;
use App\Enums\CredentialEnvironment;
use App\Enums\IntegrationProvider;
use App\Enums\Role;
use App\Models\IntegrationCredential;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\OperatorUser;

/*
 * Spec PAY-3, PAY-4, ADR-0004 Option A.
 *
 * The `encrypted` cast is the entire security story for operator credentials —
 * there is no external secret store and no per-tenant key separation, by
 * decision. That makes "is the cast actually applied" a security assertion
 * rather than a unit-test formality, and it is one that cannot be made through
 * the model: a cast that was silently dropped returns exactly the same string
 * as one that is working. So these read through the query builder.
 */

function credentialFor(IntegrationProvider $provider = IntegrationProvider::Viva): IntegrationCredential
{
    $owner = OperatorUser::withRole(Role::Owner);

    return Tenancy::forTenant(
        Tenant::query()->findOrFail($owner->tenant_id),
        fn (): IntegrationCredential => IntegrationCredential::factory()->forProvider($provider)->create(),
    );
}

/** The raw column, as MySQL and SQLite both hold it. */
function rawColumn(IntegrationCredential $credential, string $column): ?string
{
    /** @var object{value: string|null}|null $row */
    $row = DB::table('integration_credentials')
        ->select($column . ' as value')
        ->where('id', $credential->getKey())
        ->first();

    return $row?->value;
}

it('stores credentials as ciphertext, not as readable JSON', function (): void {
    $credential = credentialFor();
    $plaintext = $credential->credentials['client_secret'];

    $stored = rawColumn($credential, 'credentials');

    expect($stored)->not->toBeNull()
        ->and($stored)->not->toContain($plaintext)
        ->and($stored)->not->toContain('client_secret')
        // Laravel's encrypter emits base64 of a JSON envelope. Asserting the
        // shape as well as the absence catches the case where the column holds
        // something that merely *isn't* the plaintext — an empty string passes
        // a `not->toContain` and fails the requirement.
        ->and(json_decode((string) base64_decode((string) $stored, true), true))
        ->toHaveKeys(['iv', 'value', 'mac']);
})->group('fast');

it('stores the webhook secret as ciphertext too', function (): void {
    $credential = credentialFor(IntegrationProvider::Viva);
    $plaintext = $credential->webhook_secret;

    expect($plaintext)->not->toBeNull();

    $stored = rawColumn($credential, 'webhook_secret');

    expect($stored)->not->toBeNull()->and($stored)->not->toContain((string) $plaintext);
})->group('fast');

it('round-trips through the model unchanged', function (): void {
    $credential = credentialFor(IntegrationProvider::Viva);

    $reloaded = Tenancy::forTenant(
        Tenant::query()->findOrFail($credential->tenant_id),
        fn (): IntegrationCredential => IntegrationCredential::query()->findOrFail($credential->getKey()),
    );

    expect($reloaded->credentials)->toBe($credential->credentials)
        ->and($reloaded->webhook_secret)->toBe($credential->webhook_secret);
})->group('fast');

it('leaves public_config readable, because an operator has to check it', function (): void {
    $credential = credentialFor();

    // The other half of the requirement, and the half a blunter implementation
    // would break by encrypting everything. A Viva source code an operator
    // cannot read back is a support ticket nobody can answer.
    expect(rawColumn($credential, 'public_config'))->toContain('source_code');
})->group('fast');

it('fails loudly when APP_KEY has rotated, rather than returning garbage', function (): void {
    $credential = credentialFor();

    // A rotated key must not decrypt to a plausible-looking wrong value. The
    // encrypter's MAC is what guarantees that, and this is the assertion that
    // the cast is going through the encrypter at all rather than through
    // something that merely obscures.
    config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
    app()->forgetInstance('encrypter');
    // `forgetInstance` alone is not enough: the `Crypt` facade caches its
    // resolved instance, so the cast would keep using the old encrypter and the
    // test would pass for the wrong reason — it would prove nothing about MAC
    // verification at all.
    Crypt::clearResolvedInstances();

    $reloaded = Tenancy::forTenant(
        Tenant::query()->findOrFail($credential->tenant_id),
        fn (): IntegrationCredential => IntegrationCredential::query()->findOrFail($credential->getKey()),
    );

    expect(fn (): mixed => $reloaded->credentials)
        ->toThrow(DecryptException::class);
})->group('fast');

it('keeps both secrets out of toArray, which is what a log context is built from', function (): void {
    $credential = credentialFor(IntegrationProvider::Viva);

    $array = $credential->toArray();

    expect($array)->not->toHaveKey('credentials')
        ->and($array)->not->toHaveKey('webhook_secret')
        // And the readable half is still there, or the model would be useless
        // to every caller that legitimately needs it.
        ->and($array)->toHaveKey('public_config');
})->group('fast');

it('redacts secrets for dd and var_dump, which ignore $hidden entirely', function (): void {
    $credential = credentialFor(IntegrationProvider::Viva);

    $debug = $credential->__debugInfo();

    expect($debug['credentials'])->toBe(IntegrationCredential::REDACTED)
        ->and($debug['webhook_secret'])->toBe(IntegrationCredential::REDACTED);
})->group('fast');

it('decrypts once per request, however many times the repository is asked', function (): void {
    $credential = credentialFor();
    $tenant = Tenant::query()->findOrFail($credential->tenant_id);

    Tenancy::forTenant($tenant, function () use ($tenant): void {
        $repository = app(CredentialRepository::class);

        expect($repository->hasResolved($tenant->getKey(), IntegrationProvider::Viva, CredentialEnvironment::Test))
            ->toBeFalse();

        $first = $repository->find(IntegrationProvider::Viva, CredentialEnvironment::Test);

        // The assertion that matters is identity, not equality: the same
        // hydrated instance means no second `SELECT` and no second decrypt.
        DB::enableQueryLog();
        $second = $repository->find(IntegrationProvider::Viva, CredentialEnvironment::Test);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        expect($second)->toBe($first)->and($queries)->toBe([]);
    });
})->group('fast');

it('is a singleton, or the per-request memo is a memo per resolution', function (): void {
    // The requirement in data-model §2.7 is satisfied by the binding, not by
    // the class. A second `new` in a service provider would make every
    // assertion above still pass and the requirement silently false.
    expect(app(CredentialRepository::class))->toBe(app(CredentialRepository::class));
})->group('fast');
