<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use App\Models\Tenant;

use function Pest\Laravel\get;

use Tests\Support\Hosted\HostedRequest;

/*
|--------------------------------------------------------------------------
| HOS-1, HOS-6, HOS-10: who gets served, who gets a 404, and who gets both
|--------------------------------------------------------------------------
|
| Three states a hosted page can be in, and two of them are easy to build
| wrong in the same direction — by being *helpful*.
|
|   switched off   HOS-6 says 404. A friendly "this operator is not available"
|                  page tells the internet the operator exists, which is
|                  exactly what somebody who turned their page off did not
|                  want. It is also indistinguishable from an unknown slug,
|                  deliberately.
|
|   read-only      HOS-10 says the opposite: serve it **in full**, and replace
|                  only the booking with a message. This is the one place in
|                  the product where a lapsed subscription changes nothing for
|                  the guest — SAA-7 closes the API's writes and leaves this
|                  page alone, because the pressure belongs on the operator who
|                  owes money, not on the tourist reading about a boat.
|
*/

it('serves an active operator page', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'aegean-blue-test', 'hosted_page_enabled' => true]);

    get(HostedRequest::url('/aegean-blue-test'))
        ->assertOk()
        ->assertSee(e($tenant->name), escape: false);
})->group('fast');

it('404s an operator whose page is switched off', function (): void {
    Tenant::factory()->create(['slug' => 'switched-off', 'hosted_page_enabled' => false]);

    // Not a redirect and not an empty shell. HOS-6, read literally.
    get(HostedRequest::url('/switched-off'))->assertNotFound();
})->group('fast');

it('404s a slug that belongs to nobody', function (): void {
    get(HostedRequest::url('/no-such-operator'))->assertNotFound();
})->group('fast');

it('answers identically for a disabled page and an unknown slug', function (): void {
    Tenant::factory()->create(['slug' => 'switched-off', 'hosted_page_enabled' => false]);

    $disabled = get(HostedRequest::url('/switched-off'));
    $unknown = get(HostedRequest::url('/no-such-operator'));

    // The two must be indistinguishable from outside, or the 404 leaks the one
    // fact it exists to hide: that this operator has an account.
    expect($disabled->getStatusCode())->toBe($unknown->getStatusCode())
        ->and($disabled->getContent())->toBe($unknown->getContent());
})->group('fast');

it('serves a read-only operator in full, with the booking replaced', function (): void {
    $tenant = Tenant::factory()->create([
        'slug' => 'lapsed',
        'hosted_page_enabled' => true,
        'status' => TenantStatus::ReadOnly,
    ]);

    $response = get(HostedRequest::url('/lapsed'));

    // HOS-10: the page is complete. Only the booking is missing, and the
    // sentence says who to contact rather than what went wrong.
    $response->assertOk()
        ->assertSee(e($tenant->name), escape: false)
        ->assertSee($tenant->email, escape: false)
        ->assertSee(__('hosted.read_only', ['email' => $tenant->email]), escape: false);
})->group('fast');

it('does not show the read-only message to an active operator', function (): void {
    Tenant::factory()->create(['slug' => 'active-one', 'hosted_page_enabled' => true]);

    get(HostedRequest::url('/active-one'))->assertDontSee(__('hosted.read_only', ['email' => 'x@example.gr']), escape: false);
})->group('fast');

it('serves the legal page with the operator identity HOS-9 requires', function (): void {
    $tenant = Tenant::factory()->create([
        'slug' => 'legal-one',
        'hosted_page_enabled' => true,
        'legal_name' => 'ΑΙΓΑΙΟ ΚΡΟΥΑΖΙΕΡΕΣ ΙΚΕ',
        'vat_number' => '801234567',
        'tax_office' => 'ΔΟΥ Πειραιά',
    ]);

    get(HostedRequest::url('/legal-one/legal'))
        ->assertOk()
        ->assertSee($tenant->legal_name, escape: false)
        ->assertSee('801234567', escape: false)
        ->assertSee('ΔΟΥ Πειραιά', escape: false);
})->group('fast');

it('renders «powered by Kaiki» from the flag', function (): void {
    Tenant::factory()->create(['slug' => 'powered', 'hosted_page_enabled' => true]);

    // Brand decision 6 of 2026-09-04: always, custom domains included. From a
    // flag rather than the template, so a white-label tier is a config change.
    get(HostedRequest::url('/powered'))->assertSee(__('hosted.footer.powered_by'), escape: false);

    config(['kaiki.hosted.powered_by' => false]);

    get(HostedRequest::url('/powered'))->assertDontSee(__('hosted.footer.powered_by'), escape: false);
})->group('fast');

it('reads a path segment as an operator slug on the hosted host and nowhere else', function (): void {
    Tenant::factory()->create(['slug' => 'aegean-blue-test', 'hosted_page_enabled' => true]);

    // `{operator}` is a single path segment. Registered on every host it would
    // swallow `/app`, `/admin` and any probe route a test declares — it did,
    // and it broke eight of #7's own tenant-resolution tests before the routes
    // were scoped. **The host is the guard**, so this asserts the guard rather
    // than trusting the registration order.
    Pest\Laravel\get('/aegean-blue-test')->assertNotFound();

    Pest\Laravel\get('/app/login')->assertOk();
    Pest\Laravel\get('/up')->assertOk();
})->group('fast');
