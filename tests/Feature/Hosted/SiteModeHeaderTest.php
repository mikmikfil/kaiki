<?php

declare(strict_types=1);

use App\Enums\HostedSiteMode;
use App\Models\Product;
use App\Models\Tenant;

use function Pest\Laravel\get;

use Tests\Support\Hosted\HostedRequest;
use Tests\Support\Hosted\OperatorPage;
use Tests\Support\Hosted\TripPage;

/*
|--------------------------------------------------------------------------
| The header follows the site mode, in both directions
|--------------------------------------------------------------------------
|
| Product owner, 2026-09-22: *«αν ο merchant έχει μόνο booking pages … το logo
| να μην προσπαθεί να βρει την αρχική … ελέγξε αν ο merchant ως πρώτη φορά
| γίνει full website και στη συνέχεια ως admin τον πάω σε booking pages only.
| να προσαρμόζεται δηλαδή αυτόματα»* — *«και φυσικά και το αντίστροφο»*.
|
| `/{operator}` 404s for a bookings-only operator (ADR-0029), and the logo in
| the header linked there on every page of every site. These tests pin the
| link to the mode, and the mode to the tenant row — nothing is cached per
| mode, so a switch on /admin shows up on the next page view.
|
*/

/**
 * The trip page of an operator in one mode or the other.
 *
 * @return array{0: Tenant, 1: Product}
 */
function siteModePage(HostedSiteMode $mode): array
{
    $tenant = OperatorPage::operator('mode-header');
    $tenant->forceFill(['hosted_site_mode' => $mode])->save();

    $product = TripPage::create($tenant);

    return [$tenant, $product];
}

it('sends the logo to the home page when the operator has one', function (): void {
    [$tenant, $product] = siteModePage(HostedSiteMode::Full);

    $body = (string) get(TripPage::url($tenant, $product, 'el'))->getContent();

    expect($body)->toContain('class="brand" href="' . e(HostedRequest::url('/' . $tenant->slug . '?lang=el')))
        // And the footer keeps «Αρχική», which is a link to the same page.
        ->and($body)->toContain(e(__('hosted.footer.home', [], 'el')));
})->group('fast');

it('sends the logo to the trips instead when there is no home page', function (): void {
    [$tenant, $product] = siteModePage(HostedSiteMode::BookingsOnly);

    $body = (string) get(TripPage::url($tenant, $product, 'el'))->getContent();

    // The search page: the list of everything they sell, which is what somebody
    // pressing a logo is after. Never `/{operator}`, which 404s for them.
    expect($body)->toContain('class="brand" href="' . e(HostedRequest::url('/' . $tenant->slug . '/search?lang=el')))
        ->and($body)->not->toContain('class="brand" href="' . e(HostedRequest::url('/' . $tenant->slug . '?lang=el')))
        // Nor «Αρχική» in the footer, for the same reason.
        ->and($body)->not->toContain(e(__('hosted.footer.home', [], 'el')));
})->group('fast');

it('follows the platform switching the mode, both ways, with nothing to clear', function (): void {
    [$tenant, $product] = siteModePage(HostedSiteMode::Full);

    $url = TripPage::url($tenant, $product, 'el');
    $home = 'class="brand" href="' . e(HostedRequest::url('/' . $tenant->slug . '?lang=el'));
    $search = 'class="brand" href="' . e(HostedRequest::url('/' . $tenant->slug . '/search?lang=el'));

    expect((string) get($url)->getContent())->toContain($home);

    // What `EditTenant` does on /admin.
    //
    // `tenancy()->end()` after each one because a test process keeps the
    // resolved tenant in the container between requests, where a web server
    // resolves it again from the row. Without this the second request would
    // render from the model instance the first one initialised and the test
    // would pass or fail for a reason that has nothing to do with the page.
    $tenant->forceFill(['hosted_site_mode' => HostedSiteMode::BookingsOnly])->save();
    tenancy()->end();

    $afterSwitch = (string) get($url)->getContent();

    expect($afterSwitch)->toContain($search)
        ->and($afterSwitch)->not->toContain($home);

    // And back again.
    $tenant->forceFill(['hosted_site_mode' => HostedSiteMode::Full])->save();
    tenancy()->end();

    expect((string) get($url)->getContent())->toContain($home);
})->group('fast');

it('names a URL that exists as the organisation behind the trip', function (): void {
    [$tenant, $product] = siteModePage(HostedSiteMode::BookingsOnly);

    $body = (string) get(TripPage::url($tenant, $product, 'el'))->getContent();

    // `Organization.url` in the structured data. A crawler follows it, and a
    // 404 there is a claim about this business it can disprove.
    expect($body)->toContain(json_encode(HostedRequest::url('/' . $tenant->slug . '/search?lang=el'), JSON_UNESCAPED_SLASHES))
        ->and($body)->not->toContain(json_encode(HostedRequest::url('/' . $tenant->slug . '?lang=el'), JSON_UNESCAPED_SLASHES));
})->group('fast');
