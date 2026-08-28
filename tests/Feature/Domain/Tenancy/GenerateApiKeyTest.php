<?php

declare(strict_types=1);

use App\Domain\Tenancy\Actions\GenerateApiKey;
use App\Enums\ApiKeyEnvironment;
use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Models\ApiKey;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;

function tenantFixture(): Tenant
{
    return Tenant::factory()->create();
}

it('returns the plaintext key exactly once and stores only its hash', function (): void {
    // CNV-13 / TEN-3. If the raw key survives anywhere in the database, a dump
    // is replayable against the live API — which is the whole reason keys are
    // hashed rather than encrypted.
    $generated = Tenancy::forTenant(tenantFixture(), fn () => (new GenerateApiKey)(
        name: 'Website',
        type: ApiKeyType::Publishable,
        scopes: ApiScope::readScopes(),
    ));

    $raw = $generated->plainTextKey;

    expect($raw)->toStartWith('pk_live_');

    $row = Tenancy::withoutTenancy(
        static fn (): array => (array) DB::table('api_keys')->first(),
    );

    foreach ($row as $column => $value) {
        if (is_string($value)) {
            expect($value)->not->toBe($raw, "raw key found in column [{$column}]")
                ->and(str_contains($value, substr($raw, 14)))->toBeFalse("raw secret found in column [{$column}]");
        }
    }

    expect($row['secret_hash'])->toBe(hash('sha256', $raw))
        ->and($row['secret_hash'])->toHaveLength(64)
        ->and($row['last_four'])->toBe(substr($raw, -4));
})->group('fast');

it('keeps the plaintext out of the DTO array representation', function (): void {
    // The DTO is handed to the reveal-once UI (#10) and could otherwise be
    // serialised into a log line, a queued job payload or a Sentry breadcrumb
    // without anyone deciding to do so.
    $generated = Tenancy::forTenant(tenantFixture(), fn () => (new GenerateApiKey)(
        name: 'Website',
        type: ApiKeyType::Publishable,
        scopes: ApiScope::readScopes(),
    ));

    $array = $generated->toArray();
    $encoded = json_encode($array, JSON_THROW_ON_ERROR);

    expect($array)->not->toHaveKey('plainTextKey')
        ->and($encoded)->not->toContain($generated->plainTextKey)
        ->and((string) $generated)->toBe('[redacted api key]');
})->group('fast');

it('embeds the type and environment in the key so it is readable on sight', function (): void {
    $live = Tenancy::forTenant(tenantFixture(), fn () => (new GenerateApiKey)(
        name: 'Live secret',
        type: ApiKeyType::Secret,
        scopes: [ApiScope::QuotesWrite],
    ));

    $test = Tenancy::forTenant(tenantFixture(), fn () => (new GenerateApiKey)(
        name: 'Test publishable',
        type: ApiKeyType::Publishable,
        scopes: ApiScope::readScopes(),
        environment: ApiKeyEnvironment::Test,
    ));

    // SEC-9 greps built assets for `sk_`; that only works because the marker is
    // in the string itself rather than in a column somewhere.
    expect($live->plainTextKey)->toStartWith('sk_live_')
        ->and($test->plainTextKey)->toStartWith('pk_test_')
        ->and($live->apiKey->prefix)->toStartWith('sk_live_')
        ->and(strlen($live->apiKey->prefix))->toBeLessThanOrEqual(20);
})->group('fast');

it('refuses to give a publishable key a secret-only scope', function (): void {
    // SEC-5: the type is a ceiling. Rejected at creation as well as at the
    // middleware, so a key that could never be used cannot even be stored.
    Tenancy::forTenant(tenantFixture(), fn () => (new GenerateApiKey)(
        name: 'Overreaching',
        type: ApiKeyType::Publishable,
        scopes: [ApiScope::QuotesWrite],
    ));
})->throws(InvalidArgumentException::class, 'quotes.write')->group('fast');

it('allows a publishable key the one write it is meant to have', function (): void {
    // Creating a draft booking is the single write a guest browser must make.
    $generated = Tenancy::forTenant(tenantFixture(), fn () => (new GenerateApiKey)(
        name: 'Widget',
        type: ApiKeyType::Publishable,
        scopes: [ApiScope::ProductsRead, ApiScope::BookingsWrite],
    ));

    expect($generated->apiKey->can(ApiScope::BookingsWrite))->toBeTrue()
        ->and($generated->apiKey->can(ApiScope::QuotesWrite))->toBeFalse();
})->group('fast');

it('does not honour a scope the type disallows even if the row carries it', function (): void {
    // Defence in depth against a bad migration or a hand-edited row: the type
    // check runs at read time, so a widened row still cannot be used.
    $generated = Tenancy::forTenant(tenantFixture(), fn () => (new GenerateApiKey)(
        name: 'Widget',
        type: ApiKeyType::Publishable,
        scopes: ApiScope::readScopes(),
    ));

    Tenancy::withoutTenancy(static function () use ($generated): void {
        DB::table('api_keys')->where('id', $generated->apiKey->getKey())
            ->update(['scopes' => json_encode(['quotes.write'], JSON_THROW_ON_ERROR)]);
    });

    // Reloaded through the key's own tenant. Reloading through a different one
    // would find nothing and the assertion below would pass for the wrong reason.
    $owner = Tenancy::withoutTenancy(static fn (): Tenant => Tenant::query()->findOrFail($generated->apiKey->tenant_id));
    $reloaded = Tenancy::forTenant($owner, fn (): ApiKey => ApiKey::query()->findOrFail($generated->apiKey->getKey()));

    expect($reloaded->scopes)->toBe(['quotes.write'])
        ->and($reloaded->can(ApiScope::QuotesWrite))->toBeFalse();
})->group('fast');

it('assigns the key to the resolved tenant', function (): void {
    $tenant = tenantFixture();
    $other = tenantFixture();

    $generated = Tenancy::forTenant($tenant, fn () => (new GenerateApiKey)(
        name: 'Website',
        type: ApiKeyType::Publishable,
        scopes: ApiScope::readScopes(),
    ));

    expect($generated->apiKey->tenant_id)->toBe($tenant->getKey())
        ->and($generated->apiKey->tenant_id)->not->toBe($other->getKey());
})->group('fast');
