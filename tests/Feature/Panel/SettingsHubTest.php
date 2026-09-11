<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Exceptions\TenantContextMissingException;
use App\Filament\App\Pages\Branding;
use App\Filament\App\Pages\Integrations;
use App\Filament\App\Pages\Settings;
use App\Filament\App\Resources\StaffResource;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| «Ρυθμίσεις» as a page of cards (product owner, 2026-09-11)
|--------------------------------------------------------------------------
|
| Fifteen screens left the sidebar for one page. Three things could go wrong,
| and each is asserted here rather than assumed:
|
| - **A card for a screen that refuses you.** The hub must ask each screen, and
|   never keep a role matrix of its own that can drift from TEN-8. A link that
|   403s is a support ticket, and a card that reveals an owner-only screen's
|   name to crew is a small leak.
| - **A screen that is now unreachable.** Leaving the sidebar must not mean
|   leaving the application: the manual and the testing walkthrough link to the
|   old addresses, and those must still open.
| - **A screen still in the sidebar.** Then it would appear twice, which is how
|   people stop trusting a menu.
|
*/

function hubTenantOf(User $user): Tenant
{
    return Tenant::query()->findOrFail($user->tenant_id);
}

/** @return list<string> the card keys this user is shown, in order */
function hubCardsFor(User $user): array
{
    return Tenancy::forTenant(hubTenantOf($user), function () use ($user): array {
        actingAs($user);

        $keys = [];

        foreach ((new Settings)->sections() as $section) {
            foreach ($section['cards'] as $card) {
                $keys[] = $card['key'];
            }
        }

        return $keys;
    });
}

it('opens for an owner, with a card for every settings screen', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    $response = actingAs($owner)->get('/app/settings?lang=el')->assertSuccessful();

    foreach (Settings::SECTIONS as $cards) {
        foreach ($cards as $key => $class) {
            $response->assertSee('data-card="' . $key . '"', escape: false);
        }
    }

    // The description comes from the hub's own lang file, the title from the
    // screen's navigation label, so the card and the page agree on its name.
    app()->setLocale('el');
    $response->assertSee(__('settings_hub.cards.branding'))
        ->assertSee(Branding::getNavigationLabel());
})->group('fast');

it('shows each role exactly the cards whose screens would open for it', function (Role $role): void {
    $user = OperatorUser::withRole($role);

    $expected = Tenancy::forTenant(hubTenantOf($user), function () use ($user): array {
        actingAs($user);

        $keys = [];

        foreach (Settings::SECTIONS as $cards) {
            foreach ($cards as $key => $class) {
                if ($class::canAccess()) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    });

    // Asked of each screen, not decided by the hub. This is the assertion that
    // fails the day somebody adds a role check to the hub instead of the page.
    expect(hubCardsFor($user))->toBe($expected);
})->with([Role::Owner, Role::Manager, Role::Crew])->group('fast');

it('gives a manager no card for the screens only an owner holds', function (): void {
    // The concrete half of the test above. `IntegrationsPageTest` proves a
    // manager gets a 403 at `/app/integrations`, and the manager's hub must not
    // offer the way in.
    $cards = hubCardsFor(OperatorUser::withRole(Role::Manager));

    expect($cards)->not->toContain('integrations')
        ->and($cards)->not->toContain('payments');
})->group('fast');

it('refuses the hub to crew, who hold none of its screens', function (): void {
    $crew = OperatorUser::withRole(Role::Crew);

    // TEN-8 gives crew the passenger list and check-in, and none of the fifteen
    // screens behind these cards. If crew are ever given one, the first
    // expectation fails, and the right fix is to delete it: the hub then opens
    // with just that card, which the per-role test above already holds exactly.
    expect(hubCardsFor($crew))->toBe([]);

    actingAs($crew)->get('/app/settings')->assertForbidden();

    Tenancy::forTenant(hubTenantOf($crew), function () use ($crew): void {
        actingAs($crew);

        // `canAccess`, not `shouldRegisterNavigation`: the latter is only the
        // static flag, and Filament drops an item from the menu when
        // `canAccess` says no (`Page::registerNavigationItems`).
        expect(Settings::canAccess())->toBeFalse();
    });
})->group('fast');

it('keeps every screen at its old address', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    actingAs($owner);

    foreach (Settings::destinations() as $class) {
        // The manual and the testing walkthrough link to these addresses, and
        // an operator may have bookmarked one. Leaving the sidebar is not
        // leaving the application.
        $url = Settings::urlOf($class);

        expect(parse_url($url, PHP_URL_PATH))->not->toStartWith('/app/settings/');

        actingAs($owner)->get($url)->assertSuccessful();
    }
});

it('takes every screen it lists out of the sidebar', function (): void {
    // Otherwise each would appear twice, once in the menu and once as a card.
    foreach (Settings::destinations() as $class) {
        expect($class::shouldRegisterNavigation())->toBeFalse("{$class} is still in the sidebar");
    }
})->group('fast');

it('puts the hub itself in an owner\'s sidebar', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    Tenancy::forTenant(hubTenantOf($owner), function () use ($owner): void {
        actingAs($owner);

        expect(Settings::canAccess())->toBeTrue();
    });

    // The rendered menu, not a predicate: this is what an operator sees.
    actingAs($owner)->get('/app')->assertSuccessful()->assertSee(Settings::getUrl(), escape: false);
})->group('fast');

it('offers the way back to the hub from a screen it leads to', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    app()->setLocale('el');
    $back = __('settings_hub.back');

    // One page and one resource, because they reach the render hook through
    // different scopes: a page by its own class, a resource's list page by the
    // resource's.
    actingAs($owner)->get('/app/branding?lang=el')->assertSuccessful()->assertSee($back);
    actingAs($owner)->get(StaffResource::getUrl('index') . '?lang=el')->assertSuccessful()->assertSee($back);

    // And nowhere it does not belong.
    actingAs($owner)->get('/app/bookings?lang=el')->assertSuccessful()->assertDontSee($back);
})->group('fast');

it('answers with no tenant resolved, rather than throwing', function (): void {
    // Filament builds the menu on the login page. See NavigationWithoutTenantTest.
    expect(Settings::canAccess())->toBeFalse()
        ->and(Settings::getNavigationBadge())->toBeNull()
        ->and(fn (): bool => Settings::shouldRegisterNavigation())->not->toThrow(TenantContextMissingException::class)
        ->and(Integrations::canAccess())->toBeFalse();
})->group('fast');
