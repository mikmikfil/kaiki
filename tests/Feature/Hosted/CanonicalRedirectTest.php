<?php

declare(strict_types=1);

use App\Enums\DomainStatus;
use App\Models\TenantDomain;
use App\Support\Tenancy;

use function Pest\Laravel\get;

use Tests\Support\Hosted\HostedRequest;
use Tests\Support\Hosted\OperatorPage;
use Tests\Support\Hosted\TripPage;

/*
|--------------------------------------------------------------------------
| HOS-7: the platform URL moves out of the way of the operator's own
|--------------------------------------------------------------------------
|
| *"When a custom domain is active, the `book.{platform-domain}/{slug}` URL
| issues a 301 redirect to the custom domain so the two never compete in
| search."*
|
| Two addresses serving identical pages is a duplicate-content problem a
| canonical tag only half solves. A **permanent** redirect moves the ranking
| rather than splitting it — and 302 would tell a search engine to keep the
| platform URL indexed, which is the competition the requirement exists to end.
|
*/

function verifiedDomain(string $slug, string $hostname): void
{
    $tenant = OperatorPage::operator($slug);

    Tenancy::forTenant($tenant, static fn () => TenantDomain::query()->create([
        'hostname' => $hostname,
        'status' => DomainStatus::Verified,
        'verification_token' => 'token',
        'verified_at' => now(),
    ]));
}

it('301s the operator home page to their own domain', function (): void {
    verifiedDomain('aegean-redirect', 'book.aegean.example');

    get(HostedRequest::url('/aegean-redirect'))
        ->assertStatus(301)
        ->assertRedirect('https://book.aegean.example/');
})->group('fast');

it('carries the path and the query with it', function (): void {
    verifiedDomain('deep-redirect', 'book.deep.example');

    // A link to one trip has to land on that trip. Dropping the path would
    // send every shared link to the operator's home page, which is worse than
    // not redirecting at all.
    get(HostedRequest::url('/deep-redirect/search?date=2026-07-18&pax=4'))
        ->assertStatus(301)
        ->assertRedirect('https://book.deep.example/search?date=2026-07-18&pax=4');
})->group('fast');

it('drops the slug segment, because on their own domain the operator is the site', function (): void {
    $tenant = OperatorPage::operator('slug-drop');
    $product = TripPage::create($tenant);

    Tenancy::forTenant($tenant, static fn () => TenantDomain::query()->create([
        'hostname' => 'book.slugdrop.example',
        'status' => DomainStatus::Verified,
        'verification_token' => 'token',
        'verified_at' => now(),
    ]));

    get(HostedRequest::url('/slug-drop/' . $product->slug))
        ->assertStatus(301)
        ->assertRedirect('https://book.slugdrop.example/' . $product->slug);
})->group('fast');

it('does not redirect an operator who has no custom domain', function (): void {
    OperatorPage::operator('no-domain');

    get(HostedRequest::url('/no-domain'))->assertOk();
})->group('fast');

it('does not redirect to a domain that has not verified yet', function (): void {
    $tenant = OperatorPage::operator('pending-domain');

    Tenancy::forTenant($tenant, static fn () => TenantDomain::query()->create([
        'hostname' => 'book.pending.example',
        'status' => DomainStatus::Pending,
        'verification_token' => 'token',
    ]));

    // Redirecting to a hostname nobody is serving yet would take the operator's
    // page down until their registrar catches up.
    get(HostedRequest::url('/pending-domain'))->assertOk();
})->group('fast');
