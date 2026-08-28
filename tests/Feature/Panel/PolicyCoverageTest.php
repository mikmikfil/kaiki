<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Symfony\Component\Finder\Finder;
use Tests\Support\TenantIsolationHarness;

/*
 * Spec SEC-3: every Filament resource has a policy.
 *
 * Filament falls back to *allowing* an action when no policy is registered.
 * That default is convenient and, in a multi-tenant back office, dangerous: a
 * resource shipped without a policy is a resource every role can write to, and
 * nothing about the code says so. This test turns that silence into a failure.
 *
 * Discovery is by reflection for the same reason as #8 — a hand-kept list is
 * correct until the first person forgets it, which is the exact failure being
 * guarded against.
 */

/** @return list<class-string> */
function filamentResources(): array
{
    $dir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Filament';

    if (! is_dir($dir)) {
        return [];
    }

    $resources = [];

    foreach (Finder::create()->files()->in($dir)->name('*Resource.php') as $file) {
        $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
        $class = 'App\\Filament\\' . $relative;

        if (class_exists($class)) {
            $resources[] = $class;
        }
    }

    sort($resources);

    return $resources;
}

it('registers a policy for every model a Filament resource exposes', function (): void {
    $missing = [];

    foreach (filamentResources() as $resource) {
        if (! method_exists($resource, 'getModel')) {
            continue;
        }

        $model = $resource::getModel();

        if (Gate::getPolicyFor($model) === null) {
            $missing[] = "{$resource} (model {$model})";
        }
    }

    expect($missing)->toBe([], sprintf(
        "These Filament resources have no policy:\n  %s\n" .
        'Filament allows an action when no policy is registered, so a resource without one is writable by ' .
        'every role and nothing in the code says so (spec SEC-3).',
        implode("\n  ", $missing),
    ));
})->group('fast');

it('registers a policy for every tenant-owned model', function (): void {
    // Wider than the resource check on purpose: a model reachable through a
    // relation manager, a bulk action or a custom page is just as exposed as
    // one with its own resource, and would not appear above.
    $missing = [];

    foreach (TenantIsolationHarness::tenantOwnedModels() as $model) {
        if (Gate::getPolicyFor($model) === null) {
            $missing[] = $model;
        }
    }

    expect($missing)->toBe([], sprintf(
        "Tenant-owned models with no policy:\n  %s",
        implode("\n  ", $missing),
    ));
})->group('fast');
