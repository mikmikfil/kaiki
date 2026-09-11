<?php

declare(strict_types=1);

use App\Domain\Hosted\Support\HostedEmbedToken;
use App\Enums\ApiKeyEnvironment;
use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Enums\HostedSiteMode;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

use function Pest\Laravel\get;
use function Pest\Laravel\withHeaders;

use Tests\Support\Hosted\OperatorPage;
use Tests\Support\Hosted\TripPage;

/*
|--------------------------------------------------------------------------
| The widget on a hosted page, and the credential that lets it work
|--------------------------------------------------------------------------
|
| For two milestones a hosted product page rendered the widget's mount point,
| the no-JavaScript fallback beneath it, and **no script tag** — so every guest
| who ever reached one got "email us" and the pages could not sell anything.
| #111's end-to-end run did not catch it because it drives a fixture host page
| rather than this one, which is the lesson worth writing down: a test that
| builds its own host page proves the widget works and proves nothing about the
| pages the platform serves.
|
| The reason it was never wired is real rather than an oversight. The widget
| needs a publishable key in the markup and the platform stores only a hash of
| one, so there was nothing to render. `HostedEmbedToken` is a signed assertion
| minted per response instead — and these tests are about the two halves of
| that: the page carries one, and the API accepts it as exactly what a
| publishable key would be and nothing more.
|
*/

it('carries the widget bundle and a key for it', function (): void {
    $tenant = OperatorPage::operator('embed-page');
    $product = TripPage::create($tenant);

    $body = (string) get(TripPage::url($tenant, $product, 'en'))->getContent();

    expect($body)->toContain('kaiki-widget.js')
        ->and($body)->toContain('data-key="' . HostedEmbedToken::PREFIX)
        ->and($body)->toContain('data-mount="booking"')
        ->and($body)->toContain('data-product="' . $product->uuid . '"');
});

it('keeps the no-JavaScript answer in the markup beside it', function (): void {
    $tenant = OperatorPage::operator('embed-fallback');
    $product = TripPage::create($tenant);

    $body = (string) get(TripPage::url($tenant, $product, 'en'))->getContent();

    // The fallback is hidden by CSS once the widget draws, never removed from
    // the response: a crawler, a blocked script and a bad connection all still
    // get a way to reach the operator.
    expect($body)->toContain(__('hosted.product.booking.fallback', [], 'en'))
        ->and($body)->toContain('mailto:');
});

it('lets the API be read with the token the page was given', function (): void {
    $tenant = OperatorPage::operator('embed-api');
    TripPage::create($tenant);

    $token = HostedEmbedToken::issue($tenant);

    // The whole point. Before the resolver learnt this shape the request
    // authenticated and then died with a bare 404 from the bottom of the
    // tenant-resolution chain, which is a failure that looks like a missing
    // endpoint and is actually a missing tenant.
    withHeaders(['X-Kaiki-Key' => $token])
        ->get('/api/v1/products')
        ->assertOk();
});

it('is refused once it has expired', function (): void {
    $tenant = OperatorPage::operator('embed-expiry');

    $token = HostedEmbedToken::issue($tenant, Carbon::now());

    Carbon::setTestNow(Carbon::now()->addMinutes(HostedEmbedToken::TTL_MINUTES + 1));

    withHeaders(['X-Kaiki-Key' => $token])
        ->get('/api/v1/products')
        ->assertUnauthorized();
});

it('is refused when a byte of it has been changed', function (): void {
    $tenant = OperatorPage::operator('embed-tamper');

    $token = HostedEmbedToken::issue($tenant);

    // The signature is what stops somebody editing the tenant out of the
    // payload and reading a competitor's catalogue with it.
    expect(HostedEmbedToken::resolve(substr($token, 0, -1) . 'x'))->toBeNull();
});

it('keeps working when the platform turns the home page off', function (): void {
    $tenant = OperatorPage::operator('embed-bookings-only');

    $token = HostedEmbedToken::issue($tenant);

    $tenant->forceFill(['hosted_site_mode' => HostedSiteMode::BookingsOnly])->save();

    // The trip page that minted it is still served, so its widget must be too.
    expect(HostedEmbedToken::resolve($token))->not->toBeNull();
});

it('cannot do anything a publishable key could not', function (): void {
    $tenant = OperatorPage::operator('embed-ceiling');

    $key = HostedEmbedToken::resolve(HostedEmbedToken::issue($tenant));

    expect($key)->not->toBeNull()
        ->and($key?->type->isPublic())->toBeTrue()
        // Never saved: there is no row, so nothing to revoke, leak or stamp.
        ->and($key?->exists)->toBeFalse();

    // Read the catalogue, check availability, read branding, start a booking.
    // That is the entire list, and it is `ApiKeyType::Publishable`'s own — not
    // a set written out here, so a scope added to that type reaches a hosted
    // page and a scope removed from it leaves.
    expect($key?->scopes())->toBe(ApiKeyType::Publishable->allowedScopes());

    // The secret-only ones are refused by the type, which is the ceiling: even
    // a token that somehow carried the scope could not use it.
    expect($key?->can(ApiScope::QuotesWrite))->toBeFalse();
});

it('carries an environment, because a booking asks it whether this is a test', function (): void {
    // Every booking started from a hosted trip page answered `500`, because this
    // was null: `BookingCreateRequest::isTestKey()` reads
    // `$key->environment->isTest()` to set PAY-11's flag, and a transient key had
    // no environment to read.
    //
    // Nothing caught it. Every test of that endpoint authenticates with a real
    // `api_keys` row, which has the column — the one credential in the system
    // that is built in memory was the one nothing exercised end to end.
    $live = HostedEmbedToken::resolve(HostedEmbedToken::issue(OperatorPage::operator('embed-live')));

    expect($live?->environment)->toBe(ApiKeyEnvironment::Live);

    // And the value is the right one rather than merely non-null: §3.9 says a
    // booking made while testing is `is_test` and purged nightly, so an operator
    // still in sandbox must not produce live bookings from their own page.
    $sandbox = OperatorPage::operator('embed-sandbox');

    $sandbox->forceFill(['is_sandbox' => true])->save();

    expect(HostedEmbedToken::resolve(HostedEmbedToken::issue($sandbox))?->environment)
        ->toBe(ApiKeyEnvironment::Test);
});

it('shows one operator nothing of another\'s', function (): void {
    $mine = OperatorPage::operator('embed-mine');
    $theirs = OperatorPage::operator('embed-theirs');

    TripPage::create($mine);
    $hidden = TripPage::create($theirs);

    $body = withHeaders(['X-Kaiki-Key' => HostedEmbedToken::issue($mine)])
        ->get('/api/v1/products')
        ->assertOk()
        ->getContent();

    expect((string) $body)->not->toContain($hidden->uuid);
});

it('is not a shape the key parser could confuse for a stored key', function (): void {
    $tenant = Tenant::factory()->create();

    // `hpk_`, not `pk_`. A token an operator pasted into the WordPress plugin
    // must not work there — this credential belongs to the hosted page.
    expect(HostedEmbedToken::issue($tenant))->toStartWith('hpk_')
        ->and(HostedEmbedToken::looksLikeOne('pk_live_abcdef123456'))->toBeFalse();
});
