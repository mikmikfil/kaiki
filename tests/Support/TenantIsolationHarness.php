<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\BrandProfile;
use App\Models\Concerns\BelongsToTenant;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Observers\TenantObserver;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Finder\Finder;

/**
 * Discovers tenant-owned models and builds rows for them.
 *
 * Discovery is by **reflection, never a hand-maintained list** (spec TST-6).
 * A list would be correct on the day it was written and quietly wrong the first
 * time somebody added a model and forgot to add a line — which is precisely the
 * failure this suite exists to catch, so a list would be the one shape that
 * cannot be trusted.
 *
 * Where the generic case cannot build a model — a required foreign key, say —
 * the answer is a documented **override**, not an exclusion. An exclusion
 * removes a model from the gate; an override keeps it in and explains what it
 * needs.
 */
final class TenantIsolationHarness
{
    /**
     * Extra attributes some models need before they can be created at all.
     *
     * Each entry is a required relation the generic factory cannot invent — not
     * a way to opt out of the isolation check. The row is still created, still
     * queried across tenants, and still asserted invisible.
     *
     * @return array<class-string<Model>, callable(Tenant): array<string, mixed>>
     */
    public static function overrides(): array
    {
        return [
            // A role assignment is meaningless without the person it is about,
            // and that person must belong to the same tenant.
            RoleAssignment::class => static fn (Tenant $tenant): array => [
                'user_id' => User::factory()->forTenant($tenant)->create()->getKey(),
            ],
        ];
    }

    /**
     * Models with **exactly one row per tenant**, already created by the
     * platform before any test asks for one.
     *
     * `brand_profiles` has a unique index on `tenant_id` and a row written by
     * {@see TenantObserver} the moment the tenant exists
     * (BRD-3), so `factory()->create()` for that tenant is a constraint
     * violation rather than a fixture. The answer is to hand back the row that
     * is already there.
     *
     * This is **not** an exclusion, and the difference matters: the row is
     * still built, still queried from the other tenant, and still asserted
     * invisible. What changes is only where it came from — which is, if
     * anything, the stronger test, because it is the row production actually
     * has rather than one a factory invented.
     *
     * @return array<class-string<Model>, callable(Tenant): ?Model>
     */
    public static function singletons(): array
    {
        return [
            BrandProfile::class => static fn (Tenant $tenant): ?Model => BrandProfile::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->getKey())
                ->first(),
        ];
    }

    /**
     * Every model under `app/Models` that uses `BelongsToTenant`.
     *
     * @return list<class-string<Model>>
     */
    public static function tenantOwnedModels(): array
    {
        $models = [];

        foreach (self::allModels() as $class) {
            if (in_array(BelongsToTenant::class, class_uses_recursive($class), strict: true)) {
                $models[] = $class;
            }
        }

        sort($models);

        return $models;
    }

    /**
     * Every concrete Eloquent model under `app/Models`.
     *
     * @return list<class-string<Model>>
     */
    public static function allModels(): array
    {
        $models = [];

        foreach (Finder::create()->files()->in(self::modelsPath())->name('*.php') as $file) {
            $class = 'App\\Models\\' . str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $models[] = $class;
        }

        sort($models);

        return $models;
    }

    /**
     * Create one row of `$class` owned by `$tenant`.
     *
     * @param  class-string<Model>  $class
     */
    public static function makeFor(Tenant $tenant, string $class): Model
    {
        $existing = (self::singletons()[$class] ?? static fn (): ?Model => null)($tenant);

        if ($existing instanceof Model) {
            return $existing;
        }

        if (! method_exists($class, 'factory')) {
            throw new RuntimeException(self::missingFactoryMessage($class));
        }

        /** @var Factory<Model> $factory */
        $factory = $class::factory();

        $attributes = (self::overrides()[$class] ?? static fn (): array => [])($tenant);

        return $tenant->run(static fn (): Model => $factory->create($attributes));
    }

    /**
     * Absolute path to `app/Models`, derived from this file rather than from
     * `app_path()`.
     *
     * Pest evaluates a dataset closure at **collection** time, before the
     * Laravel application is booted, so the container helpers do not exist yet.
     * Using them here fails with a confusing `Container::path()` error that
     * looks nothing like the actual problem.
     */
    private static function modelsPath(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Models';
    }

    /** Models carrying a public `uuid`, which the API addresses them by. */
    public static function hasUuid(string $class): bool
    {
        /** @var Model $model */
        $model = new $class;

        return Schema::hasColumn($model->getTable(), 'uuid');
    }

    /** @param  class-string<Model>  $class */
    public static function missingFactoryMessage(string $class): string
    {
        return "[{$class}] is tenant-owned but has no factory, so the isolation suite cannot build a row for it. "
            . 'Add a factory. Do not remove the model from the suite: a tenant-owned model without an isolation '
            . 'test is exactly the gap this gate exists to close (spec TST-6, ADR-0001).';
    }
}
