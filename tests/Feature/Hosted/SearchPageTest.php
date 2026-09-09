<?php

declare(strict_types=1);

use App\Domain\Catalog\Support\SearchFilters;
use App\Enums\BookingMode;
use App\Models\AgeBand;
use App\Models\Departure;
use App\Models\Port;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\RatePlanPrice;
use App\Models\Tenant;
use App\Models\Vessel;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;

use function Pest\Laravel\get;

use Tests\Support\Hosted\HostedRequest;
use Tests\Support\Hosted\OperatorPage;

/*
|--------------------------------------------------------------------------
| #105: the search page works as a plain form, with no JavaScript
|--------------------------------------------------------------------------
|
| HOS-4 again, and here it costs nothing: a search form is a `GET` whose
| submission is a URL, and the results are rendered by the server. The widget's
| job is the booking; browsing never needed a script.
|
| The two assertions that matter are the ones a screenshot review cannot make:
| **a disabled filter's control is absent AND its query parameter is ignored**,
| and the empty state **says what to change** rather than showing an empty grid.
|
*/

/**
 * A trip that sails on `$date`, priced per adult, inside one operator.
 */
function searchPageTrip(Tenant $tenant, string $slug, int $priceCents, string $date, ?Port $port = null): Product
{
    return Tenancy::forTenant($tenant, static function () use ($slug, $priceCents, $date, $port): Product {
        $vessel = Vessel::factory()->create(['capacity_max' => 30]);

        $product = Product::factory()->create([
            'slug' => $slug,
            'vessel_id' => $vessel->getKey(),
            'meeting_point_id' => $port?->getKey(),
            'max_pax' => 12,
            'min_pax' => 0,
            'title' => ['el' => "Εκδρομή {$slug}", 'en' => "Trip {$slug}"],
        ]);

        $band = AgeBand::factory()->create(['product_id' => $product->getKey()]);

        $plan = RatePlan::factory()->create([
            'product_id' => $product->getKey(),
            'min_lead_time_hours' => 0,
            'max_advance_days' => 365,
        ]);

        RatePlanPrice::factory()->create([
            'rate_plan_id' => $plan->getKey(),
            'age_band_id' => $band->getKey(),
            'price_cents' => $priceCents,
        ]);

        Departure::factory()->at($date, '10:00')->create([
            'product_id' => $product->getKey(),
            'vessel_id' => $vessel->getKey(),
            'capacity' => 12,
            'min_pax' => 0,
        ]);

        return $product->refresh();
    });
}

function searchPageDate(): string
{
    return Carbon::now()->addDays(21)->toDateString();
}

it('renders results from a plain GET, with no script on the page', function (): void {
    $tenant = OperatorPage::operator('search-page');
    searchPageTrip($tenant, 'sunset', 4500, searchPageDate());

    $response = get(HostedRequest::url('/search-page/search?lang=en&date=' . searchPageDate() . '&pax=4'));

    $body = (string) $response->getContent();

    $response->assertOk()
        ->assertSee('Trip sunset', escape: false)
        // Four adults at €45. The party price is the whole point of the
        // feature: a from-price grid is what makes a guest telephone.
        ->assertSee('180', escape: false)
        ->assertSee(__('hosted.product.price.vat_included', [], 'en'), escape: false);

    // HOS-4: the form is the interaction and the server rendered the answer.
    // This page has no structured data either, so it has no script at all.
    expect($body)->toContain('<form class="search-form" method="get"')
        ->and($body)->not->toContain('<script');
})->group('fast');

it('draws a result as the same card the rest of the site uses', function (): void {
    $tenant = OperatorPage::operator('search-card');
    searchPageTrip($tenant, 'sunset', 4500, searchPageDate());

    $body = (string) get(HostedRequest::url(
        '/search-card/search?lang=en&date=' . searchPageDate() . '&pax=4',
    ))->getContent();

    // This page drew its own `<li class="trip">` once — the same class name and
    // none of the structure, so the text began at the card's edge and the
    // photograph every other trip card leads with was simply missing. The
    // structure is asserted rather than the appearance, because the appearance
    // is what nobody noticed.
    expect($body)->toContain('class="trip-image')
        ->and($body)->toContain('class="trip-body"')
        ->and($body)->toContain('class="trip-foot is-party-price"')
        ->and($body)->toContain(__('hosted.index.view', [], 'en'))
        // …and the two things a catalogue card cannot say: what this party
        // pays, and when the boat leaves on the day they asked about.
        ->and($body)->toContain(trans_choice('hosted.search.for_party', 4, ['count' => 4], 'en'))
        ->and($body)->toContain(__('hosted.search.departs_at', ['time' => '10:00'], 'en'));
})->group('fast');

it('shows a quote trip as on-request, with no price and the same foot', function (): void {
    $tenant = OperatorPage::operator('search-quote');
    $product = searchPageTrip($tenant, 'charter', 4500, searchPageDate());

    Tenancy::forTenant($tenant, static function () use ($product): void {
        $product->forceFill(['mode' => BookingMode::Quote])->save();
    });

    $body = (string) get(HostedRequest::url(
        '/search-quote/search?lang=en&date=' . searchPageDate() . '&pax=4',
    ))->getContent();

    // BKG-24: a quote trip shows no price at all — not a hidden one, not a
    // dash. Its foot still reserves the height of a priced one, so its button
    // lands on the same line as its neighbours' (settled 4 September).
    expect($body)->toContain(__('hosted.search.on_request', [], 'en'))
        ->and($body)->toContain('class="trip-foot is-party-price"')
        ->and($body)->not->toContain(trans_choice('hosted.search.for_party', 4, ['count' => 4], 'en'));
})->group('fast');

it('shows the whole catalogue to somebody who asked nothing', function (): void {
    $tenant = OperatorPage::operator('search-browse');

    // Two trips, and only one of them sails on the date the form defaults to.
    searchPageTrip($tenant, 'sails-today', 4500, searchPageDate());
    searchPageTrip($tenant, 'sails-later', 5500, Carbon::now()->addDays(60)->toDateString());

    $body = (string) get(HostedRequest::url('/search-browse/search?lang=en'))->getContent();

    // «Δείτε όλες τις εκδρομές» links here with no query string. Answering it
    // with the form's defaults — today, two people — turned a catalogue of
    // twenty into two on the demo, which reads as a broken page rather than as
    // a search. Both trips are listed.
    expect($body)->toContain('Trip sails-today')
        ->and($body)->toContain('Trip sails-later')
        // Priced "from", not for a party nobody described.
        ->and($body)->toContain(__('hosted.index.from', [], 'en'))
        // The class name is also in the inlined stylesheet, so match the
        // attribute rather than the word.
        ->and($body)->not->toContain('class="trip-foot is-party-price"');
})->group('fast');

it('filters as soon as one thing is asked, even if it is only the party size', function (): void {
    $tenant = OperatorPage::operator('search-asked');

    searchPageTrip($tenant, 'sails-today', 4500, searchPageDate());
    searchPageTrip($tenant, 'sails-later', 5500, Carbon::now()->addDays(60)->toDateString());

    // `pax` alone. The date falls back to today, which is the documented
    // behaviour — what changed is that falling back is no longer the same as
    // being asked.
    $body = (string) get(HostedRequest::url('/search-asked/search?lang=en&pax=2'))->getContent();

    // Neither trip sails today, so the answer is the empty state — and that is
    // the point. The same URL without `pax` lists both trips; with it, the page
    // searches today and correctly finds nothing. What this asserts is the
    // *mode*, not the filtering: which trips survive which date is
    // `SearchCatalogue`'s business, tested against the Action.
    expect($body)->toContain(__('hosted.search.empty.heading', [], 'en'))
        ->and($body)->not->toContain('Trip sails-later');
})->group('fast');

it('says what to change when nothing matches', function (): void {
    $tenant = OperatorPage::operator('search-empty');
    searchPageTrip($tenant, 'sunset', 4500, searchPageDate());

    // A date the boat does not sail.
    $response = get(HostedRequest::url(
        '/search-empty/search?lang=en&date=' . Carbon::now()->addDays(22)->toDateString() . '&pax=4',
    ));

    // Not an empty grid: the two things that actually change an answer are the
    // date and the party size, and the page names both.
    $response->assertOk()
        ->assertSee(__('hosted.search.empty.heading', [], 'en'), escape: false)
        ->assertSee(__('hosted.search.empty.body', [], 'en'), escape: false)
        ->assertSee($tenant->email, escape: false);
})->group('fast');

it('draws only the filters the operator enabled', function (): void {
    $tenant = OperatorPage::operator('search-controls');
    searchPageTrip($tenant, 'sunset', 4500, searchPageDate());

    // The defaults: date, port, party and type drawn; duration, price and
    // vessel not.
    $body = (string) get(HostedRequest::url('/search-controls/search?lang=en'))->getContent();

    expect($body)->toContain('name="date"')
        ->and($body)->toContain('name="pax"')
        ->and($body)->toContain('name="type"')
        ->and($body)->not->toContain('name="duration_max"')
        ->and($body)->not->toContain('name="price_max"')
        ->and($body)->not->toContain('name="vessel"');

    // A second operator rather than a second request against the same one: the
    // tenancy package keeps the initialised tenant for the life of the process,
    // so a settings change made between two test requests is not seen by the
    // second — a property of the test harness, not of the page, and asserting
    // around it here would be asserting the harness.
    $enabled = OperatorPage::operator('search-price-on');

    $enabled->forceFill([
        'settings' => [SearchFilters::SETTINGS_KEY => ['filters' => [
            ...SearchFilters::defaults(),
            SearchFilters::PRICE => true,
        ]]],
    ])->save();

    searchPageTrip($enabled, 'sunset', 4500, searchPageDate());

    $withPrice = (string) get(HostedRequest::url('/search-price-on/search?lang=en'))->getContent();

    expect($withPrice)->toContain('name="price_max"');
})->group('fast');

it('ignores a disabled filter arriving in the query string', function (): void {
    $tenant = OperatorPage::operator('search-crafted');

    $piraeus = Tenancy::forTenant($tenant, static fn (): Port => Port::factory()->create([
        'name' => ['el' => 'Ζέα', 'en' => 'Zea'],
    ]));

    searchPageTrip($tenant, 'from-zea', 4500, searchPageDate(), $piraeus);
    searchPageTrip($tenant, 'from-elsewhere', 5500, searchPageDate());

    $tenant->forceFill([
        'settings' => [SearchFilters::SETTINGS_KEY => ['filters' => [
            ...SearchFilters::defaults(),
            SearchFilters::PORT => false,
        ]]],
    ])->save();

    $url = '/search-crafted/search?lang=en&date=' . searchPageDate() . '&pax=2';

    $crafted = (string) get(HostedRequest::url($url . '&port=' . $piraeus->uuid))->getContent();
    $plain = (string) get(HostedRequest::url($url))->getContent();

    // Both trips, in both responses. Hiding the control and honouring the
    // parameter is the version that passes a screenshot review and fails the
    // operator who switched it off.
    foreach ([$crafted, $plain] as $body) {
        expect($body)->toContain('Trip from-zea')
            ->and($body)->toContain('Trip from-elsewhere');
    }
})->group('fast');

it('keeps the visitor language across a submission', function (): void {
    $tenant = OperatorPage::operator('search-locale');
    searchPageTrip($tenant, 'sunset', 4500, searchPageDate());

    $body = (string) get(HostedRequest::url('/search-locale/search?lang=el'))->getContent();

    // The hidden field is what stops a Greek visitor landing back on the
    // operator's default language after every search.
    expect($body)->toContain('<input type="hidden" name="lang" value="el">')
        ->and($body)->toContain(__('hosted.search.submit', [], 'el'));
})->group('fast');

it('is reachable from the operator home page', function (): void {
    $tenant = OperatorPage::operator('search-link');

    get(HostedRequest::url('/search-link?lang=en'))
        ->assertOk()
        ->assertSee(__('hosted.search.nav', [], 'en'), escape: false)
        ->assertSee('/search-link/search', escape: false);
})->group('fast');
