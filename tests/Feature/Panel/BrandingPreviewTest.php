<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Filament\App\Pages\Branding;
use App\Models\Tenant;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| BRD-4's live preview — issue 110
|--------------------------------------------------------------------------
|
| Deferred from #17 because there was no widget to preview. Two claims are
| worth testing and one of them is the whole point of the feature:
|
| 1. **It is the real widget.** The page embeds the published bundle, not a
|    styled `div` shaped like one. A hand-made preview is a second
|    implementation of the widget's appearance and it is wrong the first time
|    either changes.
| 2. **It needs no credential.** Only a publishable key's hash, prefix and last
|    four are ever stored (SEC-3), so there is no plaintext key on this page to
|    leak — and none is needed, because the preview transport answers from a
|    payload the panel puts on the page.
|
| The live half — colours travelling to an already-mounted shadow root as
| custom properties — is asserted through the dispatched event, because the
| repaint itself happens in a browser and belongs to the Playwright smoke test.
|
*/

/** @return array<string, mixed> */
function brandingPreviewAs(User $user): array
{
    tenancy()->initialize(Tenant::query()->findOrFail($user->tenant_id));

    /** @var Branding $page */
    $page = Livewire::actingAs($user)->test(Branding::class)->instance();

    return $page->previewState();
}

it('embeds the real widget bundle from the alias rather than a versioned path', function (): void {
    $preview = brandingPreviewAs(OperatorUser::withRole(Role::Owner));

    // ADR-0011: the panel shows what operators are actually running, so it must
    // load the alias. A versioned path here would show an operator a preview of
    // a release nobody has been moved onto yet.
    expect($preview['bundle'])->toEndWith('/widget/kaiki-widget.js');
    expect($preview['bundle'])->not->toContain('/widget/v');
})->group('fast');

it('renders the preview with no publishable key of the tenant on the page', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    $html = (string) actingAs($user)->get('/app/branding')->assertSuccessful()->getContent();

    // The `data-key` is a placeholder the transport never sends anywhere. What
    // matters is that nothing resembling a real publishable key is here: there
    // is no plaintext key in the database to put here in the first place.
    expect($html)->toContain('data-key="pk_preview"');
    expect($html)->toContain('/widget/kaiki-widget.js');
    expect(preg_match('/pk_(?!preview)[A-Za-z0-9_-]{8,}/', $html))->toBe(0);
})->group('fast');

it('hands the widget the operator own trips rather than invented ones', function (): void {
    $preview = brandingPreviewAs(OperatorUser::withRole(Role::Owner));

    // An operator checks whether their longest title fits. A made-up sample trip
    // answers a question nobody asked.
    expect($preview['payload'])->toHaveKeys(['branding', 'products']);
    expect($preview['payload']['products'])->toBeArray();
})->group('fast');

it('sends no saved colours in the preview payload, so the host element wins', function (): void {
    $preview = brandingPreviewAs(OperatorUser::withRole(Role::Owner));

    // The widget writes what `/branding` returns into `:host` inside the shadow
    // root; the panel writes the *unsaved* choices onto the host element, where
    // an inline declaration outranks a `:host` rule. Sending both would make the
    // preview race itself, and which colour won would depend on which request
    // finished first.
    expect($preview['payload']['branding']['colors'])->toBe([]);
})->group('fast');

it('never asks the panel to load a third-party font sheet', function (): void {
    $preview = brandingPreviewAs(OperatorUser::withRole(Role::Owner));

    // WGT-10 governs the widget on an operator's site. Inside the panel there is
    // no reason at all to reach a font CDN to preview a colour.
    expect($preview['payload']['branding']['font']['css_url'])->toBeNull();
})->group('fast');

it('previews the unsaved colour, not the saved one', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    tenancy()->initialize(Tenant::query()->findOrFail($user->tenant_id));

    $page = Livewire::actingAs($user)
        ->test(Branding::class)
        ->set('data.color_primary', '#8B1E3F')
        ->set('data.button_radius_px', 20);

    $preview = $page->instance()->previewState();

    expect($preview['properties']['--kaiki-primary'])->toBe('#8B1E3F');
    expect($preview['properties']['--kaiki-radius'])->toBe('20px');
})->group('fast');

it('falls back to the saved colour while one is being typed', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    tenancy()->initialize(Tenant::query()->findOrFail($user->tenant_id));

    $page = Livewire::actingAs($user)->test(Branding::class);
    $saved = $page->instance()->previewState()['properties']['--kaiki-primary'];

    // `#8B` is what a colour field holds for a few hundred milliseconds every
    // time somebody types one. A preview that flashed black here is a preview an
    // operator turns off.
    $typing = $page->set('data.color_primary', '#8B')->instance()->previewState();

    expect($typing['properties']['--kaiki-primary'])->toBe($saved);
})->group('fast');

it('tells the mounted widget about a colour change instead of re-rendering it', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    tenancy()->initialize(Tenant::query()->findOrFail($user->tenant_id));

    // Re-rendering would tear the shadow root down on every keystroke — which is
    // why the embed sits behind `wire:ignore`. Six custom properties travel
    // instead.
    Livewire::actingAs($user)
        ->test(Branding::class)
        ->set('data.color_primary', '#123C69')
        ->call('previewChanged')
        ->assertDispatched('branding-changed');
})->group('fast');

it('previews the real confirmation email rather than a lookalike', function (): void {
    $preview = brandingPreviewAs(OperatorUser::withRole(Role::Owner));

    // `mail.booking.html` is what a guest receives. A second template built to
    // look like the first is a promise that they stay in step, and they will not.
    expect($preview['email'])->toContain('KAI-2026-0001');
    expect($preview['email'])->toContain($preview['properties']['--kaiki-primary']);
    expect($preview['email'])->toContain('<!doctype html>');
})->group('fast');

it('moves the preview back to the config defaults when reset is used', function (): void {
    $user = OperatorUser::withRole(Role::Owner);

    tenancy()->initialize(Tenant::query()->findOrFail($user->tenant_id));

    $page = Livewire::actingAs($user)
        ->test(Branding::class)
        ->set('data.color_primary', '#8B1E3F')
        ->call('resetToDefaults');

    // The hull teal of 2026-09-04, read from config rather than repeated here:
    // a test that pinned the hex would be the second place the platform default
    // lives, which is the thing `BrandProfileDefaultsTest` exists to prevent.
    $default = (string) config('kaiki.branding.defaults.colors.primary');

    expect($page->instance()->previewState()['properties']['--kaiki-primary'])->toBe($default);

    // Behind `wire:ignore`, the mounted widget is still holding the discarded
    // colour unless the reset tells it otherwise.
    $page->assertDispatched('branding-changed');
})->group('fast');

it('keeps the preview away from crew, like the rest of the screen', function (): void {
    actingAs(OperatorUser::withRole(Role::Crew))->get('/app/branding')->assertForbidden();
})->group('fast');
