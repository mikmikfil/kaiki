<?php

declare(strict_types=1);

use App\Domain\Operations\Support\AttentionItems;
use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Enums\Role;
use App\Filament\App\Pages\Calendar;
use App\Filament\App\Pages\CheckIn;
use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Resources\DepartureResource;
use App\Filament\App\Widgets\DayByBoat;
use App\Filament\App\Widgets\NeedsAttention;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| The home page, the phone menu and the decisions (2026-09-17)
|--------------------------------------------------------------------------
|
| «Version 3», the day by boat; Menu 1 as boxes on a phone; and «Χρειάζονται
| προσοχή», where every row now leads to the screen that fixes it.
|
*/

afterEach(function (): void {
    Carbon::setTestNow();
});

function homeOwner(): User
{
    $owner = OperatorUser::withRole(Role::Owner, Tenant::factory()->create(['timezone' => 'Europe/Athens']));

    Tenancy::forTenant($owner->tenant, function (): void {
        $vessel = Vessel::factory()->create(['name' => 'Αύρα']);
        $departure = Departure::factory()->for($vessel)->at('2026-09-08', '18:00')->withSeats(4)->create();

        Booking::factory()->create([
            'departure_id' => $departure->getKey(),
            'vessel_id' => $vessel->getKey(),
            'status' => BookingStatus::Confirmed,
        ]);
    });

    return $owner;
}

it('picks the greeting by the hour on the operator\'s clock', function (string $utc, string $period): void {
    $owner = homeOwner();
    tenancy()->initialize($owner->tenant);

    // Athens is UTC+3 in September: 02:00 UTC is 05:00 on the quay.
    expect(Dashboard::greetingPeriod(Carbon::parse($utc, 'UTC')))->toBe($period);
})->with([
    '04:59 still hello' => ['2026-09-08 01:59:00', 'hello'],
    '05:00 good morning' => ['2026-09-08 02:00:00', 'morning'],
    '11:59 good morning' => ['2026-09-08 08:59:00', 'morning'],
    '12:00 hello' => ['2026-09-08 09:00:00', 'hello'],
    // Five in the afternoon was too early for «καλησπέρα» — at that hour in
    // summer a skipper is still bringing a boat in (product owner, 2026-09-21).
    '17:00 still hello' => ['2026-09-08 14:00:00', 'hello'],
    '20:59 still hello' => ['2026-09-08 17:59:00', 'hello'],
    '21:00 good evening' => ['2026-09-08 18:00:00', 'evening'],
    '00:59 good evening' => ['2026-09-08 21:59:00', 'evening'],
    '01:00 hello' => ['2026-09-08 22:00:00', 'hello'],
])->group('fast');

it('greets the operator with a sun or a moon', function (): void {
    // 21:00 in Athens: the moon rises with «καλησπέρα», by the product owner's
    // decision — an icon that disagrees with the sentence beside it reads as a
    // bug rather than as meteorology.
    Carbon::setTestNow('2026-09-08 18:00:00');
    $owner = homeOwner();

    actingAs($owner)->get('/app')
        ->assertSuccessful()
        ->assertSee(__('dashboard.home.greeting.evening'))
        ->assertSee('is-moon', escape: false);
})->group('fast');

it('keeps the sun up at five in the afternoon, where the greeting is still «Γεια σου»', function (): void {
    Carbon::setTestNow('2026-09-08 14:00:00');
    $owner = homeOwner();

    actingAs($owner)->get('/app')
        ->assertSuccessful()
        ->assertSee(__('dashboard.home.greeting.hello'))
        ->assertSee('is-sun', escape: false);
})->group('fast');

it('shows a moon in the small hours, where the greeting falls back to «Γεια σου»', function (): void {
    // The exception the icon rule needs: a sun at three in the morning would be
    // the same mistake from the other end.
    Carbon::setTestNow('2026-09-09 00:00:00');
    $owner = homeOwner();

    actingAs($owner)->get('/app')
        ->assertSuccessful()
        ->assertSee('is-moon', escape: false);
})->group('fast');

it('puts the day and the time under the greeting, on the operator\'s own clock', function (): void {
    // Not the browser's zone: a skipper checking from a phone still on UK time
    // must not be told it is a different hour in the harbour.
    Carbon::setTestNow('2026-09-08 09:00:00');
    $owner = homeOwner();

    actingAs($owner)->get('/app')
        ->assertSuccessful()
        ->assertSee('data-ka-clock', escape: false)
        ->assertSee('data-tz="' . $owner->tenant->timezone . '"', escape: false)
        // 09:00 UTC is 12:00 on the quay in September.
        ->assertSee('12:00');
})->group('fast');

it('greets by the salutation when there is one, and by the first name alone when not', function (): void {
    $owner = homeOwner();
    actingAs($owner);

    $owner->forceFill(['name' => 'Μαρία Παπαδοπούλου', 'salutation' => null])->save();
    // Never the surname (product owner, 2026-09-17).
    expect(Dashboard::nameToGreet())->toBe('Μαρία');

    $owner->forceFill(['salutation' => 'Κυρία Μαρία'])->save();
    expect(Dashboard::nameToGreet())->toBe('Κυρία Μαρία');
})->group('fast');

it('puts the next departure, the four boxes and the boats on the home page', function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');
    $owner = homeOwner();
    tenancy()->initialize($owner->tenant);

    Livewire::actingAs($owner)
        ->test(DayByBoat::class)
        ->assertOk()
        ->assertSee(__('dashboard.home.next.label'))
        ->assertSee('18:00')
        ->assertSee(__('dashboard.home.boxes.bookings'))
        ->assertSee(__('dashboard.home.boats.heading'))
        ->assertSee('Αύρα');
})->group('fast');

it('offers the phone menu as boxes, with a close button', function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');

    actingAs(homeOwner())->get('/app')
        ->assertSuccessful()
        ->assertSee('ka-mobile-menu-btn', escape: false)
        ->assertSee(__('panel.mobile_menu.close'))
        ->assertSee('ka-menu-box', escape: false);
})->group('fast');

it('sends a sailing short of its minimum to that departure', function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');
    $owner = homeOwner();

    Tenancy::forTenant($owner->tenant, function (): void {
        Departure::query()->firstOrFail()->forceFill([
            'status' => DepartureStatus::Scheduled,
            'min_pax' => 8,
            'seats_sold' => 2,
        ])->save();
    });

    tenancy()->initialize($owner->tenant);
    actingAs($owner);

    $item = (new AttentionItems('Europe/Athens'))->all()[0];
    $row = NeedsAttention::actionFor($item);

    expect($row)->not->toBeNull()
        ->and($row['url'])->toBe(DepartureResource::getUrl('edit', ['record' => $item->subject]))
        ->and($row['action'])->toBe(__('attention.actions.departure'));

    Livewire::actingAs($owner)
        ->test(NeedsAttention::class)
        ->assertOk()
        ->assertSee(__('attention.decide.sail'))
        ->assertSee(__('attention.decide.cancel'));
})->group('fast');

it('takes scanning, boarding and aboard counts off the home page when boarding is switched off', function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');
    $owner = homeOwner();
    $owner->tenant->forceFill(['check_in_enabled' => false])->save();
    tenancy()->initialize($owner->tenant);

    Livewire::actingAs($owner)
        ->test(DayByBoat::class)
        ->assertOk()
        ->assertDontSee(__('dashboard.home.next.scan'))
        ->assertDontSee(__('dashboard.home.next.board'))
        ->assertDontSeeHtml('role="progressbar"')
        ->assertSee(__('dashboard.home.next.booked'));

    // Nor in the phone's box menu, which reads the same navigation.
    actingAs($owner)->get('/app')
        ->assertSuccessful()
        ->assertDontSee(CheckIn::getUrl(), escape: false);
})->group('fast');

/**
 * The home page's two actions as the page draws them (Mike, 2026-09-25,
 * direction Β of docs/mockups/dashboard-quick-actions.html): the buttons in
 * the greeting's row for a tablet or computer, and the phone's bottom bar.
 *
 * @return array{header: list<string>, bar: list<string>, bar_one: bool, has_bar: bool, labels: list<string>, short: list<string>, hrefs: list<string>, titles: list<string>}
 */
function homeActions(User $user): array
{
    $html = (string) actingAs($user)->get('/app')->assertSuccessful()->getContent();

    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $xpath = new DOMXPath($dom);

    $read = function (string $query, callable $pick) use ($xpath): array {
        $out = [];

        foreach ($xpath->query($query) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $out[] = (string) $pick($node);
            }
        }

        return $out;
    };

    $header = '//header[contains(@class, "ka-dash-header")]//div[@class="ka-hbtns"]/a';
    $bar = '//header[contains(@class, "ka-dash-header")]//nav[contains(@class, "ka-bar")]';

    return [
        'header' => $read($header, fn (DOMElement $a): string => $a->getAttribute('data-action')),
        'bar' => $read($bar . '/a', fn (DOMElement $a): string => $a->getAttribute('data-action')),
        'bar_one' => $read($bar . '[contains(@class, "is-one")]', fn (): string => '1') !== [],
        'has_bar' => $read($bar, fn (): string => '1') !== [],
        'labels' => $read($header, fn (DOMElement $a): string => trim((string) preg_replace('/\s+/u', ' ', $a->textContent))),
        'short' => $read($bar . '/a', fn (DOMElement $a): string => trim($a->textContent)),
        'hrefs' => $read($header, fn (DOMElement $a): string => $a->getAttribute('href')),
        'titles' => $read($header, fn (DOMElement $a): string => $a->getAttribute('title')),
    ];
}

it('keeps the boarding list but never «Σάρωση» when only the QR scanner is switched off', function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');
    $owner = homeOwner();
    $owner->tenant->forceFill(['check_in_enabled' => true, 'qr_check_in_enabled' => false])->save();

    $actions = homeActions($owner);

    // Top right on a computer, and whole on the phone's bar too.
    expect($actions['header'])->toBe(['sell', 'scan'])
        ->and($actions['labels'][1])->toBe(__('dashboard.home.next.board'))
        ->and($actions['short'][1])->toBe(__('dashboard.home.next.board'))
        ->and($actions['titles'][1])->toBe(__('dashboard.home.actions.board_hint'))
        // The list, not a camera: there is nothing on these tickets to scan.
        ->and($actions['hrefs'][1])->toBe(CheckIn::getUrl())
        ->and($actions['hrefs'])->not->toContain(route('filament.app.boarding', ['camera' => 1]));
})->group('fast');

it('sends «Σάρωση εισιτηρίων» to the boarding page with the camera opening', function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');
    $owner = homeOwner();
    $owner->tenant->forceFill(['check_in_enabled' => true, 'qr_check_in_enabled' => true])->save();

    // Mike, 2026-09-23: one press, and the camera is up and reading ticket
    // after ticket — on the page that keeps working with no signal.
    $actions = homeActions($owner);

    expect($actions['labels'][1])->toBe(__('dashboard.home.next.scan'))
        ->and($actions['hrefs'][1])->toBe(route('filament.app.boarding', ['camera' => 1]));
})->group('fast');

it('puts «Πώληση τώρα» and «Σάρωση» in the header and in a phone bar, never on the card', function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');
    $owner = homeOwner();
    $owner->tenant->forceFill(['check_in_enabled' => true, 'qr_check_in_enabled' => true])->save();
    tenancy()->initialize($owner->tenant);

    $free = Departure::query()->firstOrFail()->seatsAvailable();

    // Mike, 2026-09-25, direction Β: white sale then navy scan, top right; on
    // a phone the same two side by side, the scan under the right thumb.
    expect(homeActions($owner))->toMatchArray([
        'header' => ['sell', 'scan'],
        'bar' => ['sell', 'scan'],
        'bar_one' => false,
        // The sale says which sailing it is for: the time on the button, the
        // free seats as its hint.
        'labels' => [__('calendar.sell.title') . ' · 18:00', __('dashboard.home.next.scan')],
        'short' => [__('calendar.sell.title'), __('dashboard.home.actions.scan_short')],
        'titles' => [
            trans_choice('dashboard.home.actions.sell_hint', $free, ['time' => '18:00', 'count' => $free]),
            __('dashboard.home.actions.scan_hint'),
        ],
    ]);

    // The card is information only: no action anywhere in the widget.
    Livewire::actingAs($owner)
        ->test(DayByBoat::class)
        ->assertOk()
        ->assertDontSeeHtml('data-action=')
        ->assertDontSeeHtml('kd-acts')
        ->assertDontSeeHtml('kd-sell');
})->group('fast');

it('leaves the sale alone, across the bar, when boarding is switched off', function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');
    $owner = homeOwner();
    $owner->tenant->forceFill(['check_in_enabled' => false])->save();

    expect(homeActions($owner))->toMatchArray(['header' => ['sell'], 'bar' => ['sell'], 'bar_one' => true]);
})->group('fast');

it('drops the sale and keeps the scan across the bar with no departure this week', function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');
    $owner = OperatorUser::withRole(Role::Owner, Tenant::factory()->create(['timezone' => 'Europe/Athens']));
    $owner->tenant->forceFill(['check_in_enabled' => true, 'qr_check_in_enabled' => true])->save();

    // A booking three weeks out: past the card's seven days, and enough that
    // the home page is past its first steps.
    Tenancy::forTenant($owner->tenant, function (): void {
        $departure = Departure::factory()->at('2026-09-30', '10:00')->withSeats(4)->create();
        Booking::factory()->create(['departure_id' => $departure->getKey(), 'vessel_id' => $departure->vessel_id, 'status' => BookingStatus::Confirmed]);
    });

    tenancy()->initialize($owner->tenant);

    Livewire::actingAs($owner)
        ->test(DayByBoat::class)
        ->assertOk()
        ->assertSee(__('dashboard.home.next.none'));

    expect(homeActions($owner))->toMatchArray(['header' => ['scan'], 'bar' => ['scan'], 'bar_one' => true]);
})->group('fast');

it('draws no bar and no buttons when there is nothing to do', function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');
    $owner = OperatorUser::withRole(Role::Owner, Tenant::factory()->create(['timezone' => 'Europe/Athens']));
    $owner->tenant->forceFill(['check_in_enabled' => false])->save();

    Tenancy::forTenant($owner->tenant, function (): void {
        $departure = Departure::factory()->at('2026-09-30', '10:00')->withSeats(4)->create();
        Booking::factory()->create(['departure_id' => $departure->getKey(), 'vessel_id' => $departure->vessel_id, 'status' => BookingStatus::Confirmed]);
    });

    expect(homeActions($owner))->toMatchArray(['header' => [], 'bar' => [], 'has_bar' => false]);
})->group('fast');

it('keeps the bar on the home page only', function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');
    $owner = homeOwner();

    expect(homeActions($owner)['has_bar'])->toBeTrue();

    actingAs($owner)->get(Calendar::getUrl())
        ->assertSuccessful()
        ->assertDontSee('ka-bar', escape: false)
        ->assertDontSee('ka-hbtns', escape: false);
})->group('fast');

it('greets with the name as given, or without one when it is blank', function (?string $name, string $morning, string $hello): void {
    app()->setLocale('el');

    expect(Dashboard::greeting('morning', $name))->toBe($morning)
        ->and(Dashboard::greeting('hello', $name))->toBe($hello);
})->with([
    'name' => ['  Μαρία Παπαδοπούλου ', 'Καλημέρα, Μαρία Παπαδοπούλου', 'Γεια σου, Μαρία Παπαδοπούλου'],
    'blank' => ['   ', 'Καλημέρα', 'Γεια σου'],
    'none' => [null, 'Καλημέρα', 'Γεια σου'],
])->group('fast');

it('puts «Αρχική» in the first group of the phone menu, never alone on its own row', function (): void {
    Carbon::setTestNow('2026-09-08 09:00:00');

    $html = (string) actingAs(homeOwner())->get('/app')->assertSuccessful()->getContent();

    $menu = substr($html, (int) strpos($html, 'class="ka-menu-body"'));
    $firstGroup = (int) strpos($menu, 'ka-menu-group');
    $firstBoxes = (int) strpos($menu, 'ka-menu-boxes');

    // The first thing in the menu is a group heading, not a row of boxes, so
    // there is no heading-less row with «Αρχική» by itself.
    expect($firstGroup)->toBeGreaterThan(0)
        ->and($firstGroup)->toBeLessThan($firstBoxes);
})->group('fast');
