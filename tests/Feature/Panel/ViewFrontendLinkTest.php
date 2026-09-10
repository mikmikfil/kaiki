<?php

declare(strict_types=1);

use App\Domain\Hosted\Support\HostedUrl;
use App\Enums\HostedSiteMode;
use App\Enums\Role;
use App\Models\Tenant;
use App\Support\Tenancy;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| The "view your page" link in the panel sidebar
|--------------------------------------------------------------------------
|
| A way out to the guest's side of the product, from the top of the sidebar.
| Two things are worth pinning, and neither is that it renders:
|
| 1. **It is absent when the hosted page is switched off** (HOS-6). A button
|    leading to a 404 teaches an operator the feature is broken rather than
|    switched off, and that is a support call.
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

it('says nothing when the operator has switched their page off', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    Tenancy::withoutTenancy(static fn () => Tenant::query()
        ->whereKey($user->tenant_id)
        ->update(['hosted_site_mode' => HostedSiteMode::Off]));

    $html = (string) actingAs($user)->get('/app')->assertSuccessful()->getContent();

    expect($html)->not->toContain(__('panel.view_frontend.label'));
})->group('fast');
