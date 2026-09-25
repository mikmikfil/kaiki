<?php

declare(strict_types=1);

use App\Domain\Availability\Support\DepartureCalendar;
use App\Domain\Hosted\Support\CalendarPage;
use App\Enums\BookingMode;
use App\Enums\DepartureCancelReason;
use App\Support\Format\MoneyFormatter;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\get;

use Tests\Support\Hosted\CalendarScenario;
use Tests\Support\Hosted\HostedRequest;
use Tests\Support\Hosted\OperatorPage;

/*
|--------------------------------------------------------------------------
| «Ημερολόγιο» — every departure, day by day (2026-09-25)
|--------------------------------------------------------------------------
|
| Direction Β of docs/mockups/departures-calendar.html. What is asserted is
| what a screenshot cannot show: the word each sailing gets for *this* party,
| that the filters are honoured from the address, that another operator's
| sailings and a draft trip never appear, that «Κράτηση» carries the sailing,
| and that the page's queries do not grow with the catalogue.
|
*/

function calendarPage(string $operator, string $query = ''): string
{
    $response = get(CalendarScenario::url($operator, $query));
    $response->assertOk();

    return (string) $response->getContent();
}

it('lists every sailing of the fortnight with its time, trip, word, price and a way to book', function (): void {
    $tenant = OperatorPage::operator('cal-basic');
    $sunset = CalendarScenario::trip($tenant, 'sunset', 6500);
    CalendarScenario::sailing($tenant, $sunset, CalendarScenario::day(), '18:30', 12, 10);

    $body = calendarPage('cal-basic');

    $date = Carbon::parse(CalendarScenario::day())->locale('el')->isoFormat('dddd D MMMM');

    expect($body)
        ->toContain($date)
        ->toContain('18:30')
        ->toContain('Εκδρομή sunset')
        ->toContain('Boat sunset')
        ->toContain(__('hosted.calendar.status.available', [], 'el'))
        ->toContain(MoneyFormatter::format(6500, 'el', MoneyFormatter::currency()))
        ->toContain(__('hosted.calendar.per_person', [], 'el'))
        ->toContain(trans_choice('hosted.calendar.count', 1, ['count' => 1], 'el'))
        // HOS-4: a page of links, rendered by the server. The one script is
        // the same-origin file that makes the links faster, and nothing inline.
        ->and(substr_count($body, '<script'))->toBe(1)
        ->and($body)->toContain('<script src="' . HostedRequest::url('/hosted/calendar.js') . '" defer></script>');
})->group('fast');

it('says «last seats» from the threshold down, and «only N» with no button when the party does not fit', function (): void {
    config()->set('kaiki.hosted.calendar.few_seats', 5);

    $tenant = OperatorPage::operator('cal-seats');
    $trip = CalendarScenario::trip($tenant, 'sailing');
    CalendarScenario::sailing($tenant, $trip, CalendarScenario::day(), '09:30', 10, 3);
    $one = CalendarScenario::sailing($tenant, $trip, CalendarScenario::day(1), '09:30', 10, 1);
    CalendarScenario::sailing($tenant, $trip, CalendarScenario::day(2), '09:30', 10, 0);

    $body = calendarPage('cal-seats', 'pax=2');

    expect($body)
        ->toContain(trans_choice('hosted.calendar.status.few', 3, ['count' => 3], 'el'))
        ->toContain(trans_choice('hosted.calendar.status.no_fit', 1, ['count' => 1], 'el'))
        ->toContain(__('hosted.calendar.status.full', [], 'el'))
        // The one-seat sailing has no «Κράτηση» for a party of two…
        ->and($body)->not->toContain('departure=' . $one->uuid);

    // …and has one for a party of one, where the word is «Τελευταία θέση».
    $alone = calendarPage('cal-seats', 'pax=1');

    expect($alone)
        ->toContain('departure=' . $one->uuid)
        ->toContain(trans_choice('hosted.calendar.status.few', 1, ['count' => 1], 'el'));
})->group('fast');

it('narrows by part of the day, by trip, and to sailings with room', function (): void {
    $tenant = OperatorPage::operator('cal-filters');
    $morning = CalendarScenario::trip($tenant, 'swim');
    $evening = CalendarScenario::trip($tenant, 'sunset');
    CalendarScenario::sailing($tenant, $morning, CalendarScenario::day(), '09:00', 12, 12);
    CalendarScenario::sailing($tenant, $evening, CalendarScenario::day(), '18:30', 12, 0);

    $morning = calendarPage('cal-filters', 'part=morning');
    expect($morning)->toContain('Εκδρομή swim')->and($morning)->not->toContain('18:30');

    $evening = calendarPage('cal-filters', 'part=evening');
    expect($evening)->toContain('18:30')->and($evening)->not->toContain('09:00');

    $sunset = calendarPage('cal-filters', 'trip[]=sunset');
    expect($sunset)->toContain('18:30')->and($sunset)->not->toContain('>09:00<');

    // The sunset is full: «only with free seats» leaves the swim alone.
    $free = calendarPage('cal-filters', 'free=1');
    expect($free)->toContain('09:00')->and($free)->not->toContain('>18:30<');
})->group('fast');

it('strikes through a weather cancellation under a red banner, and says so for the day', function (): void {
    $tenant = OperatorPage::operator('cal-weather');
    $trip = CalendarScenario::trip($tenant, 'caves');
    CalendarScenario::sailing($tenant, $trip, CalendarScenario::day(), '11:30', 12, 12, DepartureCancelReason::Weather);

    $body = calendarPage('cal-weather');

    expect($body)
        ->toContain('class="cal-wx"')
        ->toContain(__('hosted.calendar.weather.all', [], 'el'))
        ->toContain(__('hosted.calendar.status.weather', [], 'el'))
        ->toContain('class="strike"');
})->group('fast');

it('calls any other cancellation just «cancelled», with no weather banner', function (): void {
    $tenant = OperatorPage::operator('cal-cancel');
    $trip = CalendarScenario::trip($tenant, 'caves');
    CalendarScenario::sailing($tenant, $trip, CalendarScenario::day(), '11:30', 12, 12, DepartureCancelReason::Operator);

    $body = calendarPage('cal-cancel');

    expect($body)->toContain(__('hosted.calendar.status.cancelled', [], 'el'))
        ->and($body)->not->toContain('class="cal-wx"');
})->group('fast');

it('gives a day with nothing on it a line rather than a gap', function (): void {
    $tenant = OperatorPage::operator('cal-empty-day');
    $trip = CalendarScenario::trip($tenant, 'swim');
    CalendarScenario::sailing($tenant, $trip, CalendarScenario::day(), '09:00');

    expect(calendarPage('cal-empty-day'))->toContain(__('hosted.calendar.empty_day', [], 'el'));
})->group('fast');

it('says when the next sailing is when the fortnight is empty', function (): void {
    $tenant = OperatorPage::operator('cal-season');
    $trip = CalendarScenario::trip($tenant, 'swim');
    CalendarScenario::sailing($tenant, $trip, CalendarScenario::day(30), '09:00');

    $next = Carbon::parse(CalendarScenario::day(30))->locale('el')->isoFormat('dddd D MMMM');

    expect(calendarPage('cal-season'))
        ->toContain(__('hosted.calendar.closed.next_button', ['date' => $next], 'el'))
        ->toContain('from=' . CalendarScenario::day(30));
})->group('fast');

it('never shows another operator\'s sailings, nor a trip that is not published', function (): void {
    $mine = OperatorPage::operator('cal-mine');
    $theirs = OperatorPage::operator('cal-theirs');

    $own = CalendarScenario::trip($mine, 'swim');
    CalendarScenario::sailing($mine, $own, CalendarScenario::day(), '09:00');

    $foreign = CalendarScenario::trip($theirs, 'foreign');
    CalendarScenario::sailing($theirs, $foreign, CalendarScenario::day(), '10:00');

    $draft = CalendarScenario::draft($mine, 'hidden');
    CalendarScenario::sailing($mine, $draft, CalendarScenario::day(), '11:00');

    $body = calendarPage('cal-mine');

    expect($body)->toContain('Εκδρομή swim');

    foreach (['Εκδρομή foreign', 'Εκδρομή hidden', '>10:00<', '>11:00<'] as $absent) {
        expect($body)->not->toContain($absent);
    }
})->group('fast');

it('sends «Κράτηση» to the trip page with the day and the sailing', function (): void {
    $tenant = OperatorPage::operator('cal-book');
    $trip = CalendarScenario::trip($tenant, 'sailing');
    $departure = CalendarScenario::sailing($tenant, $trip, CalendarScenario::day(), '09:30');

    $body = calendarPage('cal-book');

    expect($body)->toContain(e(HostedRequest::url('/cal-book/sailing?lang=el&date=' . CalendarScenario::day() . '&departure=' . $departure->uuid)));

    // …and the trip page hands both to the widget.
    $page = (string) get(HostedRequest::url('/cal-book/sailing?date=' . CalendarScenario::day() . '&departure=' . $departure->uuid))->getContent();

    expect($page)
        ->toContain('data-date="' . CalendarScenario::day() . '"')
        ->toContain('data-departure="' . $departure->uuid . '"');

    // A departure without a day, or one that is not a uuid, is not passed on.
    expect((string) get(HostedRequest::url('/cal-book/sailing?departure=' . $departure->uuid))->getContent())
        ->not->toContain('data-departure=');
    expect((string) get(HostedRequest::url('/cal-book/sailing?date=' . CalendarScenario::day() . '&departure=nope'))->getContent())
        ->not->toContain('data-departure=');
})->group('fast');

it('shows a whole-boat charter as one line for the day, free or taken', function (): void {
    $tenant = OperatorPage::operator('cal-charter');
    $charter = CalendarScenario::charter($tenant, 'private');

    // A shared sailing with seats sold on the same boat takes it for the day.
    $shared = CalendarScenario::trip($tenant, 'shared');
    Tenancy::forTenant($tenant, static fn () => $shared->forceFill(['vessel_id' => $charter->vessel_id])->save());
    CalendarScenario::sailing($tenant, $shared->refresh(), CalendarScenario::day(1), '11:00', 12, 8);

    $body = calendarPage('cal-charter');

    expect($body)
        ->toContain(__('hosted.calendar.all_day', [], 'el'))
        ->toContain(__('hosted.calendar.status.free_boat', [], 'el'))
        ->toContain(__('hosted.calendar.status.booked', [], 'el'))
        ->toContain(__('hosted.calendar.per_boat', [], 'el'));
})->group('fast');

it('sends a trip sold by quote to its plain page rather than to a booking or its form', function (): void {
    $tenant = OperatorPage::operator('cal-quote');
    $trip = CalendarScenario::trip($tenant, 'quote', 6500, ['mode' => BookingMode::Quote]);
    CalendarScenario::sailing($tenant, $trip, CalendarScenario::day(), '10:00');

    $body = calendarPage('cal-quote');
    $plain = e(HostedRequest::url('/cal-quote/quote?lang=el'));

    expect($body)
        ->toContain(__('hosted.calendar.status.on_request', [], 'el'))
        ->toContain(__('hosted.calendar.see_trip', [], 'el'))
        // The trip page at the top: no day that pre-opens anything, no anchor
        // into the enquiry form (Mike, 25/9).
        ->toContain('class="cal-book is-ghost" href="' . $plain . '"')
        ->and($body)->not->toContain('/cal-quote/quote?lang=el&amp;date=')
        ->and($body)->not->toContain('/cal-quote/quote?lang=el#book')
        ->and($body)->not->toContain('>' . __('hosted.calendar.book', [], 'el') . '<');
})->group('fast');

it('puts «Ημερολόγιο» in the menu and the footer, unless the operator took it out', function (): void {
    $tenant = OperatorPage::operator('cal-menu');
    $calendarUrl = e(HostedRequest::url('/cal-menu/calendar?lang=el'));

    $search = (string) get(HostedRequest::url('/cal-menu/search'))->getContent();

    // Header row, phone menu, footer.
    expect(substr_count($search, 'href="' . $calendarUrl . '"'))->toBe(3);

    // An operator who switched it off on «Η σελίδα αναζήτησής σας».
    $off = OperatorPage::operator('cal-menu-off');
    $off->forceFill(['settings' => [...(array) $off->settings, CalendarPage::SETTINGS_KEY => ['in_menu' => false]]])->save();
    $offUrl = e(HostedRequest::url('/cal-menu-off/calendar?lang=el'));

    $after = (string) get(HostedRequest::url('/cal-menu-off/search'))->getContent();

    expect(CalendarPage::inMenu($off->refresh()))->toBeFalse()
        ->and($after)->not->toContain('href="' . $offUrl . '"');
    // The page itself still answers a link somebody already has.
    get(HostedRequest::url('/cal-menu-off/calendar'))->assertOk();
})->group('fast');

it('asks the database the same number of times for ten trips as for two', function (): void {
    $build = static function (string $slug, int $trips): void {
        $tenant = OperatorPage::operator($slug);

        foreach (range(1, $trips) as $n) {
            $trip = CalendarScenario::trip($tenant, "t{$n}");
            CalendarScenario::sailing($tenant, $trip, CalendarScenario::day(), '09:00', 12, 4);
            CalendarScenario::sailing($tenant, $trip, CalendarScenario::day(3), '18:00');
            CalendarScenario::sailing($tenant, $trip, CalendarScenario::day(5), '11:00', 12, 12, DepartureCancelReason::Weather);
            // A whole-boat trip each, so the charter path is counted too.
            CalendarScenario::charter($tenant, "c{$n}");
        }
    };

    $build('cal-q-small', 2);
    $build('cal-q-large', 10);

    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    get(CalendarScenario::url('cal-q-small'))->assertOk();
    $two = $queries;

    $queries = [];
    get(CalendarScenario::url('cal-q-large'))->assertOk();
    $ten = $queries;

    // A loop over the catalogue calling the engine per trip would make the
    // second number dozens higher; loading each ingredient once keeps it flat.
    expect(count($ten))->toBe(count($two), implode('
', $ten));
})->group('fast');

it('keeps the few-seats threshold in configuration', function (): void {
    config()->set('kaiki.hosted.calendar.few_seats', 3);

    expect(DepartureCalendar::fewSeats())->toBe(3);
})->group('fast');

/*
|--------------------------------------------------------------------------
| Without a reload (calendar.js)
|--------------------------------------------------------------------------
*/

it('answers calendar.js with only the block it swaps, and says the URL has two bodies', function (): void {
    $tenant = OperatorPage::operator('cal-ajax');
    $trip = CalendarScenario::trip($tenant, 'swim');
    CalendarScenario::sailing($tenant, $trip, CalendarScenario::day(), '09:00');

    $partial = get(CalendarScenario::url('cal-ajax', 'pax=3'), ['X-Requested-With' => 'XMLHttpRequest', 'X-Kaiki-Calendar' => 'body']);

    $partial->assertOk()->assertHeader('Vary', 'X-Requested-With');

    $content = (string) $partial->getContent();

    expect($content)
        ->toStartWith('<div class="cal" data-cal')
        ->toContain('09:00')
        ->toContain(trans_choice('hosted.calendar.pax_value', 3, ['count' => 3], 'el'))
        ->and($content)->not->toContain('<html');

    // The page itself is the whole page, and varies on the same header.
    get(CalendarScenario::url('cal-ajax'))->assertOk()->assertHeader('Vary', 'X-Requested-With');

    // An ordinary XHR without the calendar's own header still gets the page.
    expect((string) get(CalendarScenario::url('cal-ajax'), ['X-Requested-With' => 'XMLHttpRequest'])->getContent())
        ->toContain('<html');
})->group('fast');

it('marks the first day of the strip as the chosen one', function (): void {
    $tenant = OperatorPage::operator('cal-chosen');
    $trip = CalendarScenario::trip($tenant, 'swim');
    CalendarScenario::sailing($tenant, $trip, CalendarScenario::day(), '09:00');

    $body = calendarPage('cal-chosen');

    expect($body)->toMatch('/data-day="' . CalendarScenario::day() . '"\s+class="cal-pill is-on[^"]*"\s+aria-current="date"/')
        ->and(substr_count($body, 'aria-current="date"'))->toBe(1);
})->group('fast');
