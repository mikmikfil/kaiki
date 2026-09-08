<?php

declare(strict_types=1);

use App\Domain\Operations\Support\FirstSteps;
use App\Exceptions\TenantContextMissingException;
use App\Filament\App\Pages\DepartureReconciliation;
use App\Filament\App\Widgets\NeedsAttention;
use App\Filament\App\Widgets\OperationsOverview;
use App\Filament\App\Widgets\TodayAtSea;

use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| TEN-4: a predicate with no tenant answers, it does not throw
|--------------------------------------------------------------------------
|
| `BelongsToTenant` **raises** `TenantContextMissingException` when no tenant is
| resolved, rather than quietly returning nothing. That is the right call —
| silently scoping to nobody is how cross-tenant leaks start — but it makes
| every predicate that touches a tenant-owned model a place where the whole
| panel can fall over.
|
| `shouldRegisterNavigation()` is the worst of them, because Filament calls it
| while building the menu, which happens on **every page in `/app`**. One
| unguarded query there answers a request about the dashboard with an exception
| naming `ScheduleRule` — a model the operator was nowhere near.
|
| These assertions are deliberately about *not throwing*. Their value is that
| they fail loudly the next time somebody adds a query to a navigation or
| `canView` predicate, which is a thing that reads as harmless every time.
|
*/

it('serves the login page to somebody who is not signed in', function (): void {
    // The failure this file exists for, in its plainest form. Filament builds
    // the navigation — badges and all — while rendering `/app/login`, where by
    // definition there is no tenant. One unguarded count there answers with a
    // 500 naming `Enquiry`, on the one page nobody can be signed in to fix.
    //
    // A page test rather than a predicate call, because each predicate looked
    // reasonable in isolation; what was wrong was *where Filament calls them*.
    get('/app/login')->assertOk();
});

it('answers navigation and widget predicates with no tenant resolved', function (): void {
    // No `tenancy()->initialize()`, no acting-as. This is the state a request
    // is in before `ResolveTenant` has run, or after it has declined.
    expect(DepartureReconciliation::shouldRegisterNavigation())->toBeFalse()
        ->and(FirstSteps::applies())->toBeFalse()
        ->and(TodayAtSea::canView())->toBeFalse()
        ->and(OperationsOverview::canView())->toBeFalse()
        ->and(NeedsAttention::canView())->toBeFalse();
});

it('does not raise the tenancy exception from any of them', function (): void {
    // The property under test is the absence of a throw. Asserting it directly
    // says so, where a bare call would pass for the wrong reason if somebody
    // later wrapped these in a try/catch.
    foreach ([
        fn (): bool => DepartureReconciliation::shouldRegisterNavigation(),
        fn (): bool => FirstSteps::applies(),
        fn (): bool => TodayAtSea::canView(),
        fn (): bool => NeedsAttention::canView(),
        fn (): bool => OperationsOverview::canView(),
    ] as $predicate) {
        expect($predicate)->not->toThrow(TenantContextMissingException::class);
    }
});
