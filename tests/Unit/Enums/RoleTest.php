<?php

declare(strict_types=1);

use App\Enums\Role;

it('has exactly the three roles the brief defines', function (): void {
    expect(array_map(fn (Role $r): string => $r->value, Role::cases()))
        ->toBe(['owner', 'manager', 'crew']);
})->group('fast');

it('resolves labels from lang keys, not literals', function (): void {
    // Every user-facing string exists in Greek and English from the first
    // commit. A hardcoded label here would be invisible until an operator
    // opened the panel in Greek and saw English.
    app()->setLocale('en');
    expect(Role::Owner->label())->toBe('Owner');

    app()->setLocale('el');
    expect(Role::Owner->label())->toBe('Ιδιοκτήτης')
        ->and(Role::Manager->label())->toBe('Υπεύθυνος')
        ->and(Role::Crew->label())->toBe('Πλήρωμα');
})->group('fast');

it('has a description for every role in both locales', function (): void {
    foreach (['el', 'en'] as $locale) {
        app()->setLocale($locale);

        foreach (Role::cases() as $role) {
            expect($role->description())
                ->not->toBe("roles.{$role->value}.description", "missing {$locale} description for {$role->value}");
        }
    }
})->group('fast');

it('lets only the owner manage roles', function (): void {
    expect(Role::Owner->canManageRoles())->toBeTrue()
        ->and(Role::Manager->canManageRoles())->toBeFalse()
        ->and(Role::Crew->canManageRoles())->toBeFalse();
})->group('fast');

it('offers translated options for a form select', function (): void {
    app()->setLocale('el');

    expect(Role::options())->toBe([
        'owner' => 'Ιδιοκτήτης',
        'manager' => 'Υπεύθυνος',
        'crew' => 'Πλήρωμα',
    ]);
})->group('fast');
