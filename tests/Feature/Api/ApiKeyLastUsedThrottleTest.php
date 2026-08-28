<?php

declare(strict_types=1);

use App\Domain\Tenancy\Actions\GenerateApiKey;
use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Models\ApiKey;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

/**
 * `last_used_at` is written at most once per key per minute (data-model §2.1).
 *
 * Without the throttle, authentication turns every read into a write. The
 * availability endpoint is the busiest thing in the product and has a 150 ms
 * p95 budget (NFR-1); a write per request is how that budget disappears
 * quietly, months before anyone measures it.
 */
afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array{0: Tenant, 1: ApiKey} */
function throttleFixture(string $name = 'Throttle test'): array
{
    $tenant = Tenant::factory()->create();

    $generated = Tenancy::forTenant($tenant, fn () => (new GenerateApiKey)(
        name: $name,
        type: ApiKeyType::Publishable,
        scopes: ApiScope::readScopes(),
    ));

    return [$tenant, $generated->apiKey];
}

function freshKey(Tenant $tenant, int $id): ApiKey
{
    return Tenancy::forTenant($tenant, fn (): ApiKey => ApiKey::query()->findOrFail($id));
}

it('writes last_used_at on first use', function (): void {
    Carbon::setTestNow('2026-08-28 12:00:00');
    [$tenant, $key] = throttleFixture();

    $key->touchLastUsed();

    expect(freshKey($tenant, $key->getKey())->last_used_at?->toDateTimeString())
        ->toBe('2026-08-28 12:00:00');
})->group('fast');

it('does not write again within the same minute', function (): void {
    Carbon::setTestNow('2026-08-28 12:00:00');
    [$tenant, $key] = throttleFixture();
    $key->touchLastUsed();

    // Thirty seconds later and twenty requests in, the row must not move.
    Carbon::setTestNow('2026-08-28 12:00:30');
    for ($i = 0; $i < 20; $i++) {
        $key->touchLastUsed();
    }

    expect(freshKey($tenant, $key->getKey())->last_used_at?->toDateTimeString())
        ->toBe('2026-08-28 12:00:00');
})->group('fast');

it('writes again once the window has passed', function (): void {
    Carbon::setTestNow('2026-08-28 12:00:00');
    [$tenant, $key] = throttleFixture();
    $key->touchLastUsed();

    Carbon::setTestNow('2026-08-28 12:01:01');
    $key->touchLastUsed();

    expect(freshKey($tenant, $key->getKey())->last_used_at?->toDateTimeString())
        ->toBe('2026-08-28 12:01:01');
})->group('fast');

it('throttles per key, so a busy key does not mute a quiet one', function (): void {
    Carbon::setTestNow('2026-08-28 12:00:00');
    [$tenant, $first] = throttleFixture('First key');

    $second = Tenancy::forTenant($tenant, fn () => (new GenerateApiKey)(
        name: 'Second key',
        type: ApiKeyType::Publishable,
        scopes: ApiScope::readScopes(),
    ))->apiKey;

    $first->touchLastUsed();
    $second->touchLastUsed();

    expect(freshKey($tenant, $first->getKey())->last_used_at)->not->toBeNull()
        ->and(freshKey($tenant, $second->getKey())->last_used_at)->not->toBeNull();
})->group('fast');

it('uses the cache abstraction rather than a direct Redis call', function (): void {
    // ENV-7: there is no Redis locally. A `Redis::` call here would work in CI
    // and production and blow up on a developer machine — the worst split to
    // discover, because it only appears once someone else runs the code.
    // Comments are stripped first. The docblock in ApiKey explains *why* there
    // is no direct Redis call, so a naive grep matches the explanation and the
    // guard passes or fails on prose rather than on code.
    $code = '';
    foreach (token_get_all(file_get_contents(app_path('Models/ApiKey.php'))) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], strict: true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    expect($code)->toContain('Cache::get')
        ->and($code)->not->toContain('Redis::')
        ->and($code)->not->toContain('Facades\Redis');
})->group('fast');
