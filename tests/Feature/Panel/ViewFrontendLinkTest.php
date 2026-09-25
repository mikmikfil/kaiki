<?php

declare(strict_types=1);

use App\Domain\Hosted\Support\HostedUrl;
use App\Enums\HostedSiteMode;
use App\Enums\Role;
use App\Models\Tenant;
use App\Support\Tenancy;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| The "view your page" link in the panel sidebar
|--------------------------------------------------------------------------
|
| A way out to the guest's side of the product, from the top of the sidebar.
| Two things are worth pinning, and neither is that it renders:
|
| 1. **It is absent when there is no home page** (*bookings only*, ADR-0029). A
|    button leading to a 404 teaches an operator the feature is broken rather
|    than switched off, and that is a support call.
| 2. **It is not in `/admin`.** The super-admin has no tenant (ADR-0020) and so
|    no page to view; an unscoped render hook would put a link to nowhere in
|    front of the one person who cannot use it.
|
*/

it('offers the operator their own page', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    $tenant = Tenant::query()->findOrFail($user->tenant_id);

    $html = (string) actingAs($user)->get('/app')->assertSuccessful()->getContent();

    expect($html)->toContain(__('panel.view_frontend.label'))
        ->and($html)->toContain(HostedUrl::operator($tenant))
        // A new tab: the operator is mid-edit, and sending them away from a
        // half-finished form is how work gets lost.
        ->and($html)->toContain('target="_blank"');
})->group('fast');

it('offers an operator with no home page their trips instead', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    Tenancy::withoutTenancy(static fn () => Tenant::query()
        ->whereKey($user->tenant_id)
        ->update(['hosted_site_mode' => HostedSiteMode::BookingsOnly]));

    $tenant = Tenancy::withoutTenancy(static fn () => Tenant::query()->findOrFail($user->tenant_id));

    $html = (string) actingAs($user)->get('/app')->assertSuccessful()->getContent();

    // The search page, built by HostedUrl like every hosted link — never the
    // `/{operator}` home page, which is a 404 in this mode (Mike, 2026-09-25).
    $search = HostedUrl::siteStart($tenant);

    expect($search)->toEndWith('/' . $tenant->slug . '/search')
        ->and($html)->toContain(__('panel.view_frontend.trips_label'))
        ->and($html)->not->toContain(__('panel.view_frontend.label'))
        ->and($html)->toContain('href="' . e($search) . '"')
        ->and($html)->not->toContain('href="' . e(HostedUrl::operator($tenant)) . '"')
        ->and($html)->toContain('target="_blank"');
})->group('fast');

it('leads the crew nowhere, whichever the mode', function (HostedSiteMode $mode): void {
    $user = OperatorUser::withRole(Role::Crew);

    Tenancy::withoutTenancy(static fn () => Tenant::query()
        ->whereKey($user->tenant_id)
        ->update(['hosted_site_mode' => $mode]));

    $html = (string) actingAs($user)->followingRedirects()->get('/app')->assertSuccessful()->getContent();

    expect($html)->not->toContain(__('panel.view_frontend.label'))
        ->and($html)->not->toContain(__('panel.view_frontend.trips_label'));
})->with([HostedSiteMode::Full, HostedSiteMode::BookingsOnly])->group('fast');

it('serves the search page the card leads to when there is no home page', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    Tenancy::withoutTenancy(static fn () => Tenant::query()
        ->whereKey($user->tenant_id)
        ->update(['hosted_site_mode' => HostedSiteMode::BookingsOnly]));

    $tenant = Tenancy::withoutTenancy(static fn () => Tenant::query()->findOrFail($user->tenant_id));

    auth()->logout();

    get(HostedUrl::siteStart($tenant))->assertOk();
    get(HostedUrl::operator($tenant))->assertNotFound();
})->group('fast');
