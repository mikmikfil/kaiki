<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Tenant;
use App\Support\PanelApp;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| The panel as an app on a phone (PWA, 2026-09-23)
|--------------------------------------------------------------------------
|
| Installing, the worker and the offline page are the browser's, and a
| Playwright run is where they are seen working. What PHP can hold still is
| what the browser is handed: a manifest Chrome accepts, icons of the sizes
| it names, the tags in every page's head, and two workers whose scopes do not
| fight — the panel's at `/app`, the boarding page's at `/app/boarding`.
|
*/

/**
 * Width and height from a PNG's IHDR chunk.
 *
 * @return array{int, int}
 */
function pngSize(string $path): array
{
    $bytes = (string) file_get_contents($path, length: 24);

    expect(substr($bytes, 0, 8))->toBe("\x89PNG\r\n\x1a\n");

    $size = unpack('Nw/Nh', substr($bytes, 16, 8));

    return is_array($size) ? [(int) $size['w'], (int) $size['h']] : [0, 0];
}

/**
 * The manifest's entries under a key — its icons or its shortcuts.
 *
 * @return list<array<mixed, mixed>>
 */
function manifestEntries(mixed $list): array
{
    expect($list)->toBeArray();

    return is_array($list) ? array_values(array_filter($list, is_array(...))) : [];
}

it('serves a manifest Chrome can install from', function (): void {
    $response = get(route('filament.app.manifest'))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('application/manifest+json');

    $manifest = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest)->toMatchArray([
        'name' => 'Kaiki',
        'short_name' => 'Kaiki',
        'id' => '/app',
        'start_url' => '/app?source=pwa',
        // A prefix, so the dashboard at `/app` is inside it and `/admin` is not.
        'scope' => '/app',
        'display' => 'standalone',
        'background_color' => PanelApp::NAVY,
        'theme_color' => PanelApp::NAVY,
        'description' => __('pwa.description'),
    ]);

    $icons = manifestEntries($manifest['icons']);

    foreach (['any', 'maskable'] as $purpose) {
        $sizes = array_column(array_filter($icons, fn (array $icon): bool => $icon['purpose'] === $purpose), 'sizes');

        expect($sizes)->toBe(['192x192', '512x512']);
    }

    expect(array_column(manifestEntries($manifest['shortcuts']), 'url'))
        ->toBe(['/app/boarding?camera=1', '/app/calendar']);
})->group('fast');

it('speaks the operator\'s language', function (): void {
    $manifest = get(route('filament.app.manifest', ['lang' => 'el']))->json();

    expect($manifest['lang'])->toBe('el')
        ->and($manifest['description'])->toBe(trans('pwa.description', [], 'el'))
        ->and($manifest['shortcuts'][0]['name'])->toBe('Επιβίβαση');
})->group('fast');

it('has every icon it names, at the size it names', function (): void {
    $manifest = get(route('filament.app.manifest'))->json();

    $icons = manifestEntries($manifest['icons']);

    foreach (manifestEntries($manifest['shortcuts']) as $shortcut) {
        array_push($icons, ...manifestEntries($shortcut['icons']));
    }

    $icons[] = ['src' => PanelApp::icon('apple-touch-icon.png'), 'sizes' => '180x180'];

    foreach ($icons as $icon) {
        $src = is_string($icon['src']) ? $icon['src'] : '';
        $path = public_path(ltrim((string) parse_url($src, PHP_URL_PATH), '/'));

        expect($path)->toBeFile();

        [$width, $height] = pngSize($path);

        expect("{$width}x{$height}")->toBe($icon['sizes'], $src);
    }
})->group('fast');

it('puts the manifest, the colours and the iPhone tags in the sign-in page', function (): void {
    $body = (string) get('/app/login')->assertOk()->getContent();

    expect($body)->toContain('<link rel="manifest" href="/app/manifest.webmanifest" crossorigin="use-credentials">')
        // The navy band is what sits under the status bar here.
        ->and($body)->toContain('<meta name="theme-color" content="' . PanelApp::NAVY . '"')
        ->and($body)->toContain('<link rel="apple-touch-icon" href="/images/app-icon/apple-touch-icon.png?v=')
        ->and($body)->toContain('<meta name="apple-mobile-web-app-capable" content="yes">')
        ->and($body)->toContain('<meta name="apple-mobile-web-app-title" content="Kaiki">')
        ->and($body)->toContain('<meta name="apple-mobile-web-app-status-bar-style" content="default">')
        ->and($body)->toContain('navigator.serviceWorker.register("\/app\/sw.js", { scope: "\/app" })');
})->group('fast');

it('puts them in every panel page, with the white top bar\'s colour', function (): void {
    $owner = OperatorUser::withRole(Role::Owner, Tenant::factory()->create());

    $body = (string) actingAs($owner)->get('/app')->assertSuccessful()->getContent();

    expect($body)->toContain('<link rel="manifest" href="/app/manifest.webmanifest"')
        ->and($body)->toContain('<meta name="theme-color" content="' . PanelApp::TOPBAR . '"')
        ->and($body)->toContain('apple-mobile-web-app-capable')
        ->and($body)->toContain('navigator.serviceWorker.register("\/app\/sw.js"');
});

it('offers the install in the user menu, and the iPhone\'s two steps', function (): void {
    $owner = OperatorUser::withRole(Role::Owner, Tenant::factory()->create());

    $body = (string) actingAs($owner)->get('/app?lang=el')->assertSuccessful()->getContent();

    expect($body)->toContain('x-data="kaikiInstall"')
        ->and($body)->toContain(e(__('pwa.install.menu', [], 'el')))
        ->and($body)->toContain('id="kaiki-install-ios"')
        ->and($body)->toContain(e(__('pwa.ios.step_share', [], 'el')))
        ->and($body)->toContain(e(__('pwa.ios.step_add', [], 'el')));
});

it('leaves the super-admin panel alone', function (): void {
    $body = (string) get('/admin/login')->assertOk()->getContent();

    expect($body)->not->toContain('rel="manifest"')
        ->and($body)->not->toContain('/app/sw.js');
})->group('fast');

it('serves the panel\'s worker from /app, allowed the scope /app', function (): void {
    $response = get(route('filament.app.sw'))->assertOk();

    // `/app/sw.js` may claim `/app/` on its own, which leaves out the
    // dashboard at `/app`; the header widens it by exactly that.
    expect(route('filament.app.sw'))->toEndWith('/app/sw.js')
        ->and($response->headers->get('Content-Type'))->toContain('application/javascript')
        ->and($response->headers->get('Service-Worker-Allowed'))->toBe('/app')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    $script = (string) $response->getContent();

    // Named after the build, and clearing only its own old caches — the
    // boarding worker's live in the same storage.
    expect($script)->toMatch('/const VERSION = "[0-9a-f]{12}";/')
        ->and($script)->toContain("const CACHE_PREFIX = 'kaiki-panel-';")
        ->and($script)->toContain('key.startsWith(CACHE_PREFIX) && key !== CACHE')
        ->and($script)->toContain('const OFFLINE = "/app/offline";');
})->group('fast');

it('keeps no page in the panel\'s worker but the offline one', function (): void {
    $script = (string) get(route('filament.app.sw'))->getContent();

    // Navigations are passed through; the only `cache.put` of a page is the
    // offline page's, and the other is for versioned files.
    expect(substr_count($script, 'cache.put('))->toBe(2)
        ->and($script)->toContain('cache.put(OFFLINE_URL, response)')
        ->and($script)->toContain("url.pathname.startsWith('/build/assets/')")
        ->and($script)->toContain("url.searchParams.has('v')");
})->group('fast');

it('serves the offline page to anybody, about nobody', function (): void {
    $response = get(route('filament.app.offline', ['lang' => 'el']))->assertOk();

    $body = (string) $response->getContent();

    expect($response->headers->get('Content-Language'))->toBe('el')
        ->and($body)->toContain(e(__('pwa.offline.title', [], 'el')))
        ->and($body)->toContain(e(__('pwa.offline.retry', [], 'el')))
        ->and($body)->toContain(e(__('pwa.offline.boarding', [], 'el')))
        ->and($body)->toContain('href="/app/boarding"');
})->group('fast');

it('leaves the boarding worker its own scope, and the panel\'s caches alone', function (): void {
    $owner = OperatorUser::withRole(Role::Owner, Tenant::factory()->create());

    $response = actingAs($owner)->get(route('filament.app.boarding.sw'))->assertOk();

    // Longer than the panel worker's `/app`, so it keeps `/app/boarding`.
    expect($response->headers->get('Service-Worker-Allowed'))->toBe('/app/boarding')
        ->and((string) $response->getContent())->toContain("k.startsWith('kaiki-boarding')");
});
