<?php

declare(strict_types=1);

use App\Enums\FontSource;
use App\Enums\IntegrationProvider;
use App\Models\BrandProfile;
use App\Models\IntegrationCredential;
use App\Models\Tenant;
use App\Support\Tenancy;
use Tests\Support\Hosted\HostedRequest;

/*
|--------------------------------------------------------------------------
| HOS-8 and SEC-10: the policy, and the things it must NOT permit
|--------------------------------------------------------------------------
|
| A Content-Security-Policy that is too permissive passes every test that
| checks the page renders — which is every test anybody writes for it. The
| only useful assertions here are about **absence**, and there are three:
|
|   `unsafe-inline`   would make WGT-22's "the widget works under a strict
|                     policy" untestable in the one place the platform
|                     controls its own headers.
|
|   Google Fonts      WGT-10 loads the operator font *only when configured*.
|                     A policy that always allowed `fonts.gstatic.com` would
|                     make that requirement decorative, and would be wrong on
|                     every operator who never chose a font — which is most.
|
|   an unconnected    an operator on Viva has no reason for a `form-action`
|   gateway           that admits Stripe. The narrower it is, the less a
|                     stored-content bug could do with it.
|
*/

it('sends a policy with no inline anything', function (): void {
    Tenant::factory()->create(['slug' => 'strict', 'hosted_page_enabled' => true]);

    $headers = HostedRequest::headers('strict');

    expect($headers['csp'])->not->toContain("'unsafe-inline'")
        ->and($headers['csp'])->not->toContain("'unsafe-eval'")
        ->and($headers['csp'])->toContain("default-src 'self'")
        ->and($headers['csp'])->toContain("frame-ancestors 'none'")
        ->and($headers['csp'])->toContain("object-src 'none'");
})->group('fast');

it('lets the brand colours through on a nonce rather than by relaxing the policy', function (): void {
    Tenant::factory()->create(['slug' => 'nonced', 'hosted_page_enabled' => true]);

    $headers = HostedRequest::headers('nonced');

    preg_match("/'nonce-([A-Za-z0-9+\/=]+)'/", $headers['csp'], $matches);

    // The header's nonce and the `<style>` element's nonce have to be the same
    // value, or the operator's colours never apply. Minting it in the
    // middleware is what makes that structural; this proves it.
    expect($matches[1] ?? null)->toBeString()
        ->and($headers['body'])->toContain('nonce="' . ($matches[1] ?? 'x') . '"');
})->group('fast');

it('omits Google Fonts entirely for an operator who chose none', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'system-font', 'hosted_page_enabled' => true]);

    Tenancy::forTenant($tenant, static function (): void {
        BrandProfile::query()->first()?->forceFill(['font_source' => FontSource::System])->save();
    });

    $headers = HostedRequest::headers('system-font');

    // WGT-10's rule, and the sharp one: the page issues **no third-party
    // request at all**, so the policy must not name a third party either.
    expect($headers['csp'])->not->toContain('fonts.googleapis.com')
        ->and($headers['csp'])->not->toContain('fonts.gstatic.com')
        ->and($headers['body'])->not->toContain('fonts.googleapis.com');
})->group('fast');

it('admits Google Fonts only when the operator picked one', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'google-font', 'hosted_page_enabled' => true]);

    Tenancy::forTenant($tenant, static function (): void {
        BrandProfile::query()->first()?->forceFill([
            'font_source' => FontSource::Google,
            'font_family' => 'Inter',
        ])->save();
    });

    $headers = HostedRequest::headers('google-font');

    expect($headers['csp'])->toContain('https://fonts.googleapis.com')
        ->and($headers['csp'])->toContain('https://fonts.gstatic.com');
})->group('fast');

it('names no gateway an operator has not connected', function (): void {
    Tenant::factory()->create(['slug' => 'no-gateway', 'hosted_page_enabled' => true]);

    $headers = HostedRequest::headers('no-gateway');

    expect($headers['csp'])->not->toContain('vivapayments.com')
        ->and($headers['csp'])->not->toContain('stripe.com');
})->group('fast');

it('names the gateway an operator has connected, and only that one', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'viva-only', 'hosted_page_enabled' => true]);

    Tenancy::forTenant($tenant, static function (): void {
        IntegrationCredential::factory()
            ->forProvider(IntegrationProvider::Viva)
            ->live()->verified()->default()->create();
    });

    $headers = HostedRequest::headers('viva-only');

    expect($headers['csp'])->toContain('vivapayments.com')
        // Stripe is configured on the platform and unconnected by this
        // operator. A policy that named both would be the version that passes
        // a test asserting only the presence of Viva.
        ->and($headers['csp'])->not->toContain('stripe.com');
})->group('fast');

it('sends the other headers SEC-10 names, and lets crawlers in', function (): void {
    Tenant::factory()->create(['slug' => 'crawlable', 'hosted_page_enabled' => true]);

    $headers = HostedRequest::headers('crawlable');

    expect($headers['nosniff'])->toBe('nosniff')
        ->and($headers['referrer'])->not->toBe('')
        // Unlike #86's token pages, which carry `noindex`. A hosted page is
        // exactly what an operator wants indexed — it is the whole point of
        // HOS-2's structured data, and a copied `X-Robots-Tag` would silently
        // undo it.
        ->and($headers['robots'])->toBe('');
})->group('fast');
