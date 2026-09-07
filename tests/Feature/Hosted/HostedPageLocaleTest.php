<?php

declare(strict_types=1);

use App\Models\BrandProfile;
use App\Models\Tenant;
use App\Support\Tenancy;

use function Pest\Laravel\get;

use Tests\Support\Hosted\HostedRequest;

/*
|--------------------------------------------------------------------------
| HOS-5 and BRD-1: two languages, one canonical each, and the operator's colours
|--------------------------------------------------------------------------
|
| > Hosted pages are available in EL and EN with `hreflang` alternates, a
| > language switcher, and a canonical URL per locale.
|
| The alternates are the half that is easy to get subtly wrong: two pages that
| each declare themselves canonical and never point at each other are two pages
| competing in search, which is the problem `hreflang` exists to solve. So the
| assertion is that each locale's page names **both** alternates and its own
| canonical — not merely that a `hreflang` attribute appears somewhere.
|
| The browser's `Accept-Language` is deliberately not consulted; brand decision
| 2 of 2026-09-04 put a visible switch on every guest surface instead. A German
| tourist on a Greek operator's page should see the operator's own default and
| a way to change it, not a language the operator never wrote.
|
*/

it('renders in the operator default locale when nothing is asked for', function (): void {
    Tenant::factory()->create([
        'slug' => 'greek-first',
        'hosted_page_enabled' => true,
        'default_locale' => 'el',
    ]);

    get(HostedRequest::url('/greek-first'))
        ->assertOk()
        ->assertSee('lang="el"', escape: false)
        ->assertSee(__('hosted.index.trips', [], 'el'), escape: false);
})->group('fast');

it('honours ?lang= over the operator default', function (): void {
    Tenant::factory()->create([
        'slug' => 'greek-first',
        'hosted_page_enabled' => true,
        'default_locale' => 'el',
    ]);

    get(HostedRequest::url('/greek-first?lang=en'))
        ->assertOk()
        ->assertSee('lang="en"', escape: false)
        ->assertSee(__('hosted.index.trips', [], 'en'), escape: false);
})->group('fast');

it('falls back rather than refusing an unknown language', function (): void {
    Tenant::factory()->create([
        'slug' => 'greek-first',
        'hosted_page_enabled' => true,
        'default_locale' => 'el',
    ]);

    // A mistyped query string must not cost a visitor the page. The API is
    // stricter — §4.2 gives it `400 unsupported_locale` — because an integrator
    // benefits from being told and a tourist does not.
    get(HostedRequest::url('/greek-first?lang=de'))->assertOk()->assertSee('lang="el"', escape: false);
})->group('fast');

it('declares a canonical per locale, and alternates pointing at each other', function (): void {
    Tenant::factory()->create(['slug' => 'alts', 'hosted_page_enabled' => true, 'default_locale' => 'el']);

    $greek = get(HostedRequest::url('/alts?lang=el'));
    $english = get(HostedRequest::url('/alts?lang=en'));

    $greekBody = (string) $greek->getContent();
    $englishBody = (string) $english->getContent();

    // Each page's canonical is its own locale's URL …
    expect($greekBody)->toContain('rel="canonical" href="' . url('/alts?lang=el') . '"')
        ->and($englishBody)->toContain('rel="canonical" href="' . url('/alts?lang=en') . '"');

    // … and each names both alternates, which is what stops the two competing.
    foreach ([$greekBody, $englishBody] as $body) {
        expect($body)->toContain('hreflang="el" href="' . url('/alts?lang=el') . '"')
            ->and($body)->toContain('hreflang="en" href="' . url('/alts?lang=en') . '"')
            ->and($body)->toContain('hreflang="x-default"');
    }
})->group('fast');

it('shows a language switch on every page', function (): void {
    Tenant::factory()->create(['slug' => 'switcher', 'hosted_page_enabled' => true]);

    // Brand decision 2 of 2026-09-04: a visible EL/EN switch on every guest
    // surface, and the one currently active marked for a screen reader too.
    foreach (['/switcher', '/switcher/legal'] as $path) {
        get(HostedRequest::url($path))
            ->assertOk()
            ->assertSee('ΕΛ', escape: false)
            ->assertSee('EN', escape: false)
            ->assertSee('aria-current="true"', escape: false);
    }
})->group('fast');

it('carries the operator own colours rather than the platform default', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'branded', 'hosted_page_enabled' => true]);

    Tenancy::forTenant($tenant, static function (): void {
        BrandProfile::query()->first()?->forceFill(['color_primary' => '#7A1F3D'])->save();
    });

    get(HostedRequest::url('/branded'))->assertOk()->assertSee('--kaiki-primary: #7A1F3D', escape: false);
})->group('fast');

it('brands two operators differently from the same template', function (): void {
    foreach ([['one', '#123456'], ['two', '#654321']] as [$slug, $colour]) {
        $tenant = Tenant::factory()->create(['slug' => $slug, 'hosted_page_enabled' => true]);

        Tenancy::forTenant($tenant, static function () use ($colour): void {
            BrandProfile::query()->first()?->forceFill(['color_primary' => $colour])->save();
        });
    }

    // The template is shared; the colours are not. A page that hardcoded one
    // would pass every single-tenant test in this file.
    get(HostedRequest::url('/one'))->assertSee('--kaiki-primary: #123456', escape: false)->assertDontSee('#654321');
    get(HostedRequest::url('/two'))->assertSee('--kaiki-primary: #654321', escape: false)->assertDontSee('#123456');
})->group('fast');

it('uppercases nothing, in either locale', function (): void {
    Tenant::factory()->create(['slug' => 'no-caps', 'hosted_page_enabled' => true]);

    // I18N-2. Greek capitals drop their accents and browsers disagree about the
    // final sigma, so the rule is enforced on the guest surfaces rather than
    // trusted to whoever writes the next stylesheet.
    foreach (['/no-caps?lang=el', '/no-caps?lang=en'] as $path) {
        $body = strtolower((string) get(HostedRequest::url($path))->getContent());

        expect($body)->not->toContain('text-transform')
            ->and($body)->not->toContain('uppercase');
    }
})->group('fast');

it('renders every word of content without a single script tag', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'no-js', 'hosted_page_enabled' => true]);

    $response = get(HostedRequest::url('/no-js'));

    $response->assertOk();

    $body = (string) $response->getContent();

    // HOS-4. A crawler runs no JavaScript and neither does a visitor in a
    // harbour on one bar of signal, so the page has to be complete without it —
    // asserted by the **absence of a script tag**, which is the only form of
    // the claim a test can check.
    //
    // This caught a real defect rather than confirming an intention: Livewire
    // appends its script to every HTML response from the web group once it has
    // booted, so a hosted page served by a worker that had previously served a
    // panel page carried fifty kilobytes of JavaScript **and a CSRF token** —
    // on a page a CDN may cache. It only failed when the whole file ran, which
    // is why the assertion is here rather than in a test of its own.
    expect($body)->not->toContain('<script')
        // Escaped, for the reason `HomePageBlockTest` records: a faker company
        // name with an apostrophe in it is `&#039;` in the markup, and the raw
        // comparison fails at random.
        ->and($body)->toContain(e($tenant->name))
        ->and($body)->toContain(__('hosted.footer.operator'));
})->group('fast');
