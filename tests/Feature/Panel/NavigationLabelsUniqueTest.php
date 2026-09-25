<?php

declare(strict_types=1);

use Filament\Pages\Page;
use Filament\Resources\Resource;
use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| No two menu entries in a group share a name
|--------------------------------------------------------------------------
|
| Filament keys a group's items by their label
| (`NavigationManager::get()`, `keyBy(getLabel())`), so a second entry with
| the same name does not show twice — it silently replaces the first. The
| discount codes were named «Κουπόνια» on 17/9 and the refund vouchers,
| «Κουπόνια» since OPS-16, vanished from the sidebar without an error
| (roadmap, 25/9). Checked in both languages, because the clash only has to
| happen in one.
|
*/

/** @return list<class-string<resource>|class-string<Page>> */
function appPanelNavigationClasses(): array
{
    $dir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Filament' . DIRECTORY_SEPARATOR . 'App';
    $found = [];

    foreach (Finder::create()->files()->in([$dir . DIRECTORY_SEPARATOR . 'Resources', $dir . DIRECTORY_SEPARATOR . 'Pages'])->depth(0)->name('*.php') as $file) {
        $folder = basename($file->getPath());
        $class = 'App\\Filament\\App\\' . $folder . '\\' . $file->getBasename('.php');

        if (! class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        if (is_subclass_of($class, Resource::class) || is_subclass_of($class, Page::class)) {
            $found[] = $class;
        }
    }

    return $found;
}

it('finds the menu entries to check', function (): void {
    expect(appPanelNavigationClasses())->not->toBeEmpty();
})->group('fast');

it('gives every entry in a menu group its own name', function (string $locale): void {
    app()->setLocale($locale);

    $seen = [];

    foreach (appPanelNavigationClasses() as $class) {
        $key = $class::getNavigationGroup() . ' / ' . $class::getNavigationLabel();
        $seen[$key][] = class_basename($class);
    }

    $clashes = array_filter($seen, fn (array $classes): bool => count($classes) > 1);

    expect($clashes)->toBe([]);
})->with(['el', 'en'])->group('fast');
