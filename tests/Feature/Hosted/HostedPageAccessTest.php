<?php

declare(strict_types=1);

use App\Enums\HostedSiteMode;
use App\Enums\TenantStatus;
use App\Models\Tenant;

use function Pest\Laravel\get;

use Tests\Support\Hosted\HostedRequest;

/*
|--------------------------------------------------------------------------
| HOS-1, HOS-10: who gets served, who gets a 404, and who gets both
|--------------------------------------------------------------------------
|
| An unknown slug is a 404. There is no "switched off" operator any more —
| ADR-0029 as amended 2026-09-11 serves the booking pages in both site modes —
| and the one state easy to build wrong is the *helpful* one:
|
|   read-only      HOS-10 says serve it **in full**, and replace
|                  only the booking with a message. This is the one place in
|                  the product where a lapsed subscription changes nothing for
|                  the guest — SAA-7 closes the API's writes and leaves this
|                  page alone, because the pressure belongs on the operator who
|                  owes money, not on the tourist reading about a boat.
|
*/

it('serves an active operator page', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'aegean-blue-test', 'hosted_site_mode' => HostedSiteMode::Full]);

    get(HostedRequest::url('/aegean-blue-test'))
        ->assertOk()
        ->assertSee(e($tenant->name), escape: false);
})->group('fast');

it('404s a slug that belongs to nobody', function (): void {
    get(HostedRequest::url('/no-such-operator'))->assertNotFound();
})->group('fast');

it('serves a bookings-only operator their trip pages and no home page', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'own-website', 'hosted_site_mode' => HostedSiteMode::BookingsOnly]);

    // The operator with a website of their own: no second home page under
    // their name, but the legal page their checkout's terms point to is there.
    get(HostedRequest::url('/own-website'))->assertNotFound();
    get(HostedRequest::url('/own-website/legal'))->assertOk()->assertSee(e($tenant->name), escape: false);
})->group('fast');

it('serves a read-only operator in full, with the booking replaced', function (): void {
    $tenant = Tenant::factory()->create([
        'slug' => 'lapsed',
        'hosted_site_mode' => HostedSiteMode::Full,
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
    Tenant::factory()->create(['slug' => 'active-one', 'hosted_site_mode' => HostedSiteMode::Full]);

    get(HostedRequest::url('/active-one'))->assertDontSee(__('hosted.read_only', ['email' => 'x@example.gr']), escape: false);
})->group('fast');

it('serves the legal page with the operator identity HOS-9 requires', function (): void {
    $tenant = Tenant::factory()->create([
        'slug' => 'legal-one',
        'hosted_site_mode' => HostedSiteMode::Full,
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
    Tenant::factory()->create(['slug' => 'powered', 'hosted_site_mode' => HostedSiteMode::Full]);

    // Brand decision 6 of 2026-09-04: always, custom domains included. From a
    // flag rather than the template, so a white-label tier is a config change.
    get(HostedRequest::url('/powered'))->assertSee(__('hosted.footer.powered_by'), escape: false);

    config(['kaiki.hosted.powered_by' => false]);

    get(HostedRequest::url('/powered'))->assertDontSee(__('hosted.footer.powered_by'), escape: false);
})->group('fast');

it('reads a path segment as an operator slug on the hosted host and nowhere else', function (): void {
    Tenant::factory()->create(['slug' => 'aegean-blue-test', 'hosted_site_mode' => HostedSiteMode::Full]);

    // `{operator}` is a single path segment. Registered on every host it would
    // swallow `/app`, `/admin` and any probe route a test declares — it did,
    // and it broke eight of #7's own tenant-resolution tests before the routes
    // were scoped. **The host is the guard**, so this asserts the guard rather
    // than trusting the registration order.
    Pest\Laravel\get('/aegean-blue-test')->assertNotFound();

    Pest\Laravel\get('/app/login')->assertOk();
    Pest\Laravel\get('/up')->assertOk();
})->group('fast');
