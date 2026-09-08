<?php

declare(strict_types=1);

use App\Support\Tenancy;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Every navigation badge survives having no tenant
|--------------------------------------------------------------------------
|
| Filament builds the navigation on the **login page**, where nobody is signed
| in and no tenant is resolved. A badge that queries a tenant-owned model there
| does not come back empty: `BelongsToTenant` throws rather than scoping to
| nobody (TEN-4), so `/app/login` returns a 500 naming a model the visitor has
| never heard of, and the panel is unreachable for everybody — including the
| person trying to sign in and fix it.
|
| This has now happened three times: `EnquiryResource` found it,
| `NotificationLogResource` repeated it, and `InvoiceResource` repeated it again
| on the day it was written, with the guard and the explanation sitting in two
| sibling files. Two comments were not enough, so this is a test.
|
| Discovery is by reflection rather than a list, for the reason
| `PolicyCoverageTest` gives: a hand-kept list is correct until the first person
| forgets it, and forgetting is the failure being guarded against.
|
*/

/** @return list<class-string> */
function classesDeclaringANavigationBadge(): array
{
    $dir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Filament';

    if (! is_dir($dir)) {
        return [];
    }

    $found = [];

    foreach (Finder::create()->files()->in($dir)->name('*.php') as $file) {
        $relative = str_replace(['/', '\\', '.php'], ['\\', '\\', ''], $file->getRelativePathname());
        $class = 'App\\Filament\\' . $relative;

        if (! class_exists($class) || ! method_exists($class, 'getNavigationBadge')) {
            continue;
        }

        // Only the ones that say something themselves. Filament's base classes
        // declare the method and return null; inheriting that is not a badge.
        $declaring = (new ReflectionMethod($class, 'getNavigationBadge'))->getDeclaringClass()->getName();

        if ($declaring === $class) {
            $found[] = $class;
        }
    }

    sort($found);

    return $found;
}

it('finds the badges to check', function (): void {
    // A guard on the guard. If the reflection above ever stops matching —
    // Filament renames the method, the directory moves — this file would pass
    // by finding nothing, which is the one way a coverage test lies.
    expect(classesDeclaringANavigationBadge())->not->toBeEmpty();
})->group('fast');

it('returns null rather than throwing when no tenant is resolved', function (): void {
    expect(Tenancy::check())->toBeFalse();

    $broken = [];

    foreach (classesDeclaringANavigationBadge() as $class) {
        try {
            $badge = $class::getNavigationBadge();

            if ($badge !== null) {
                $broken[] = "{$class} returned {$badge} with no tenant resolved";
            }
        } catch (Throwable $e) {
            $broken[] = "{$class} threw " . $e::class . ': ' . $e->getMessage();
        }
    }

    expect($broken)->toBe([]);
})->group('fast');
