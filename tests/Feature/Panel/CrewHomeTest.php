<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Resources\BookingResource;
use App\Filament\App\Widgets\DayByBoat;
use App\Filament\App\Widgets\NeedsAttention;
use App\Models\Departure;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| The crew's panel: scan first, then today — Mike, 2026-09-24
|--------------------------------------------------------------------------
|
| «Σάρωση εισιτηρίων» alone at the top of the menu, and first on the crew's
| home. Their home is the scan button, the next boat and today by boat; the
| owner's list of decisions, the weather and the bookings list are not theirs.
| Owners and managers keep everything they had, plus the scan item.
|
*/

it('gives crew a home of the scan button, the next boat and today by boat', function (): void {
    $crew = OperatorUser::withRole(Role::Crew);

    tenancy()->initialize($crew->tenant);
    actingAs($crew);

    expect((new Dashboard)->getWidgets())->toBe([DayByBoat::class])
        ->and(BookingResource::shouldRegisterNavigation())->toBeFalse();

    actingAs($crew)->get('/app')
        ->assertSuccessful()
        ->assertSee(__('panel.nav.scan'));

    // The widget loads after the page, so it is asked on its own. The scan is
    // a tile above the card (2026-09-25), still the first thing they see.
    $html = Livewire::actingAs($crew)->test(DayByBoat::class)
        ->assertSeeHtml('data-action="scan"')
        ->assertDontSeeHtml('class="kd-box"')
        ->html();

    expect(strpos($html, 'data-action="scan"'))->toBeLessThan(strpos($html, 'class="kd-next"'));
})->group('fast');

it('leaves the owner their home, and adds the scan to their menu too', function (): void {
    $owner = OperatorUser::withRole(Role::Owner);

    tenancy()->initialize($owner->tenant);
    actingAs($owner);

    expect((new Dashboard)->getWidgets())->toContain(NeedsAttention::class)
        ->and(BookingResource::shouldRegisterNavigation())->toBeTrue();

    actingAs($owner)->get('/app')
        ->assertSuccessful()
        ->assertSee(__('panel.nav.scan'));

    // The owner's tiles come after the card, not before it.
    $html = Livewire::actingAs($owner)->test(DayByBoat::class)
        ->assertSeeHtml('data-action="scan"')
        ->assertSeeHtml('class="kd-box"')
        ->html();

    expect(strpos($html, 'data-action="scan"'))->toBeGreaterThan(strpos($html, 'class="kd-next"'));
})->group('fast');

it('shows crew the next departure they sail, with their role, ahead of an earlier one', function (): void {
    $crew = OperatorUser::withRole(Role::Crew);

    tenancy()->initialize($crew->tenant);
    actingAs($crew);

    // Two tomorrow, so the clock never pushes either out of the week.
    $day = Carbon::now('Europe/Athens')->addDay()->toDateString();
    Departure::factory()->at($day, '09:00')->create();
    Departure::factory()->at($day, '14:00')->create(['crew_user_ids' => [(int) $crew->getKey()]]);

    $next = Livewire::actingAs($crew)->test(DayByBoat::class)->instance()->getNext();

    expect($next['mine'])->toBeTrue()
        ->and($next['role'])->toBe(__('dashboard.home.next.role_crew'))
        ->and($next['time'])->toBe('14:00');
})->group('fast');
