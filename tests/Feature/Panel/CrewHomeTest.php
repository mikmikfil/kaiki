<?php

declare(strict_types=1);

use App\Enums\BookingStatus;
use App\Enums\Role;
use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Resources\BookingResource;
use App\Filament\App\Widgets\DayByBoat;
use App\Filament\App\Widgets\NeedsAttention;
use App\Models\Booking;
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
| «Σάρωση εισιτηρίων» alone at the top of the menu, and in the home page's
| header or phone bar. Their home is the next boat and today by boat; the
| owner's list of decisions, the weather and the bookings list are not theirs.
| Owners and managers keep everything they had, plus the scan item.
|
*/

it('gives crew a home of the next boat and today by boat, the scan in the header', function (): void {
    $crew = OperatorUser::withRole(Role::Crew);

    tenancy()->initialize($crew->tenant);
    actingAs($crew);

    expect((new Dashboard)->getWidgets())->toBe([DayByBoat::class])
        ->and(BookingResource::shouldRegisterNavigation())->toBeFalse();

    // Past the first steps, so the home page is the day and not the checklist.
    $departure = Departure::factory()->at(Carbon::now('Europe/Athens')->addDays(2)->toDateString(), '10:00')->withSeats(4)->create();
    Booking::factory()->create(['departure_id' => $departure->getKey(), 'vessel_id' => $departure->vessel_id, 'status' => BookingStatus::Confirmed]);

    // The scan is in the page header, and in the bar at the bottom of a phone
    // (2026-09-25, direction Β): the big button above the card is gone.
    actingAs($crew)->get('/app')
        ->assertSuccessful()
        ->assertSee(__('panel.nav.scan'))
        ->assertSeeHtml('class="ka-hbtns"')
        ->assertSeeHtml('data-action="scan"')
        ->assertSeeHtml('ka-bar');

    // The widget loads after the page, so it is asked on its own: the card and
    // the boats, no boxes, and no scan of its own.
    Livewire::actingAs($crew)->test(DayByBoat::class)
        ->assertSeeHtml('class="kd-next"')
        ->assertDontSeeHtml('data-action=')
        ->assertDontSeeHtml('kd-scan')
        ->assertDontSeeHtml('class="kd-box"');
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

    Livewire::actingAs($owner)->test(DayByBoat::class)
        ->assertDontSeeHtml('data-action=')
        ->assertSeeHtml('class="kd-box"');
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
