<?php

declare(strict_types=1);

use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\TenantIsolationHarness;

/*
|--------------------------------------------------------------------------
| The isolation gate
|--------------------------------------------------------------------------
|
| Single-database tenancy means **one missing global scope is a cross-tenant
| data leak** (SEC-1, TST-6). ADR-0001 accepted that risk on one condition: that
| this suite exists and is a required CI check. It is the price of the schema.
|
| Cases are generated from the models themselves. Adding a tenant-owned model
| without an isolation test is therefore not possible — the case appears whether
| or not anyone remembers to write it, which is the entire point. A
| hand-maintained list would be correct the day it was written and quietly wrong
| the first time somebody forgot a line.
|
| Every test here is in the `tenancy` group, which CI runs as its own required
| check and fails if it reports zero executed tests (ENV-11, TST-8) — a suite
| that silently stops running is worse than no suite, because it reports green.
|
*/

dataset('tenant owned models', fn (): array => array_combine(
    TenantIsolationHarness::tenantOwnedModels(),
    array_map(static fn (string $class): array => [$class], TenantIsolationHarness::tenantOwnedModels()),
));

it('finds tenant-owned models to test', function (): void {
    // If discovery ever returns nothing, every case below silently passes by
    // vacuum. This asserts the gate is actually pointed at something.
    expect(TenantIsolationHarness::tenantOwnedModels())->not->toBeEmpty();
})->group('tenancy', 'fast');

it('hides another tenant row from a query by id', function (string $class): void {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $rowOfB = TenantIsolationHarness::makeFor($b, $class);

    Tenancy::forTenant($a, function () use ($a, $class, $rowOfB): void {
        expect($class::query()->find($rowOfB->getKey()))->toBeNull()
            ->and($class::query()->whereKey($rowOfB->getKey())->exists())->toBeFalse();

        // **Not `count() === 0`.** That held only while every tenant-owned model
        // was one a test had to create; `brand_profiles` broke it, because BRD-3
        // gives *every* tenant exactly one the moment it exists — so tenant A
        // legitimately sees a row here, its own.
        //
        // The invariant was never "A sees nothing". It is "everything A sees
        // belongs to A", which is the same assertion for a model with no rows
        // and a stronger one for a model with some: a leak that returned B's row
        // *alongside* A's would have passed the old count check on any model
        // where A owned one already.
        $visible = $class::query()->get();

        foreach ($visible as $row) {
            expect((int) $row->getAttribute('tenant_id'))->toBe($a->getKey());
        }

        expect($visible->pluck($rowOfB->getKeyName())->all())->not->toContain($rowOfB->getKey());
    });
})->with('tenant owned models')->group('tenancy', 'fast');

it('throws rather than returning another tenant row from firstOrFail', function (string $class): void {
    // `findOrFail` and `firstOrFail` are the shapes a controller reaches for.
    // If the scope were missing they would succeed and hand over the row.
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $rowOfB = TenantIsolationHarness::makeFor($b, $class);

    Tenancy::forTenant($a, function () use ($class, $rowOfB): void {
        expect(fn () => $class::query()->findOrFail($rowOfB->getKey()))
            ->toThrow(ModelNotFoundException::class);

        expect(fn () => $class::query()->whereKey($rowOfB->getKey())->firstOrFail())
            ->toThrow(ModelNotFoundException::class);
    });
})->with('tenant owned models')->group('tenancy', 'fast');

it('hides another tenant row from a lookup by uuid', function (string $class): void {
    // The uuid is the public identifier, so this is the lookup an API endpoint
    // performs with a value an attacker can supply.
    if (! TenantIsolationHarness::hasUuid($class)) {
        expect(true)->toBeTrue();

        return;
    }

    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $rowOfB = TenantIsolationHarness::makeFor($b, $class);

    Tenancy::forTenant($a, function () use ($class, $rowOfB): void {
        // getAttribute() rather than ->uuid: the harness hands back a generic
        // Model, so the concrete class -- and therefore the property -- is not
        // known statically. This is the accurate call, not a workaround.
        expect($class::query()->where('uuid', $rowOfB->getAttribute('uuid'))->first())->toBeNull();
    });
})->with('tenant owned models')->group('tenancy', 'fast');

it('affects zero rows when updating another tenant row by key', function (string $class): void {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $rowOfB = TenantIsolationHarness::makeFor($b, $class);
    $before = $rowOfB->getAttributes();

    // `updated_at` where the model has one, and `created_at` where it does not.
    //
    // Not every tenant-owned model is updatable: `audit_logs` is append-only
    // (ADR-0025) and its migration deliberately omits `updated_at`, so the
    // obvious column produced *"no such column: updated_at"* — a failure that
    // reads as a broken gate rather than as this model being different. The
    // column being written is irrelevant to what is under test; what matters is
    // that a write scoped to A's tenancy cannot reach B's row.
    $column = $class::UPDATED_AT ?? $class::CREATED_AT;

    $affected = Tenancy::forTenant($a, static fn (): int => $class::query()
        ->whereKey($rowOfB->getKey())
        ->update([$column => now()->addYear()]));

    expect($affected)->toBe(0);

    // Read it back outside any tenant to prove the row itself is untouched,
    // not merely that the update reported nothing.
    $after = Tenancy::withoutTenancy(static fn (): array => $class::query()
        ->withoutGlobalScopes()
        ->findOrFail($rowOfB->getKey())
        ->getAttributes());

    expect($after[$column])->toBe($before[$column]);
})->with('tenant owned models')->group('tenancy', 'fast');

it('affects zero rows when deleting another tenant row by key', function (string $class): void {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $rowOfB = TenantIsolationHarness::makeFor($b, $class);

    $affected = Tenancy::forTenant($a, static fn (): int => $class::query()
        ->whereKey($rowOfB->getKey())
        ->delete());

    expect($affected)->toBe(0);

    $stillThere = Tenancy::withoutTenancy(static fn (): bool => $class::query()
        ->withoutGlobalScopes()
        ->whereKey($rowOfB->getKey())
        ->exists());

    expect($stillThere)->toBeTrue();
})->with('tenant owned models')->group('tenancy', 'fast');

it('stamps the resolved tenant on create, never null', function (string $class): void {
    $a = Tenant::factory()->create();

    $row = TenantIsolationHarness::makeFor($a, $class);

    $tenantId = $row->getAttribute('tenant_id');

    expect($tenantId)->not->toBeNull()
        ->and((int) $tenantId)->toBe($a->getKey());
})->with('tenant owned models')->group('tenancy', 'fast');

it('lets each tenant see only its own rows', function (string $class): void {
    // The end-to-end shape of the guarantee, stated once per model.
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $rowOfA = TenantIsolationHarness::makeFor($a, $class);
    $rowOfB = TenantIsolationHarness::makeFor($b, $class);

    $seenByA = Tenancy::forTenant($a, static fn (): array => $class::query()->pluck('id')->all());
    $seenByB = Tenancy::forTenant($b, static fn (): array => $class::query()->pluck('id')->all());

    expect($seenByA)->toBe([$rowOfA->getKey()])
        ->and($seenByB)->toBe([$rowOfB->getKey()]);
})->with('tenant owned models')->group('tenancy', 'fast');

it('leaves no model unscoped and unlisted', function (): void {
    // TEN-5: a model is either tenant-owned or explicitly platform-owned. There
    // is no third state, and no implicit exemption.
    $allowed = config('tenancy.platform_owned_models');
    $unaccounted = [];

    foreach (TenantIsolationHarness::allModels() as $class) {
        $scoped = in_array(BelongsToTenant::class, class_uses_recursive($class), strict: true);

        if (! $scoped && ! in_array($class, $allowed, strict: true)) {
            $unaccounted[] = $class;
        }
    }

    expect($unaccounted)->toBe([], sprintf(
        "Neither tenant-scoped nor listed as platform-owned:\n  %s\n" .
        'Add BelongsToTenant, or add the model to platform_owned_models in config/tenancy.php with a ' .
        'comment saying why it belongs to the platform rather than to one operator.',
        implode("\n  ", $unaccounted),
    ));
})->group('tenancy', 'fast');

it('can build a row for every tenant-owned model', function (string $class): void {
    // A model without a factory cannot be covered by the cases above, and the
    // suite would pass by skipping it. This turns that into a loud failure with
    // an actionable message instead.
    expect(method_exists($class, 'factory'))
        ->toBeTrue(TenantIsolationHarness::missingFactoryMessage($class));
})->with('tenant owned models')->group('tenancy', 'fast');
