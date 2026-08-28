<?php

declare(strict_types=1);

use App\Domain\Tenancy\Actions\GenerateApiKey;
use App\Domain\Tenancy\Actions\RevokeApiKey;
use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Models\ApiKey;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withHeader;

/*
 * Spec TEN-4: exactly one tenant per request, resolved in a fixed order that
 * stops at the first match — API key, verified custom domain, hosted slug,
 * panel session — and 404 when none matches.
 *
 * The precedence cases matter more than the happy paths. A resolver that works
 * alone but loses to the wrong neighbour is how a signed-in operator ends up
 * seeing their own catalogue on somebody else's hosted page.
 */

beforeEach(function (): void {
    config()->set('kaiki.tenancy.hosted_host', 'book.kaiki.test');

    // `/_probe` rather than `/`: routes/web.php already owns the root path and
    // is registered first, so a catch-all on `/` would return the welcome page
    // and the assertion would fail for a reason unrelated to tenancy.
    Route::middleware('tenant')->any('/_probe/{any?}', fn () => response()->json([
        'tenant' => tenant()?->getKey(),
        'via' => request()->attributes->get('tenant_resolved_by'),
    ]))->where('any', '.*');

    Route::middleware('tenant')->any('/{slug}', fn () => response()->json([
        'tenant' => tenant()?->getKey(),
        'via' => request()->attributes->get('tenant_resolved_by'),
    ]));
});

function publishableKeyFor(Tenant $tenant): string
{
    return Tenancy::forTenant($tenant, fn (): string => (new GenerateApiKey)(
        name: 'Resolution test',
        type: ApiKeyType::Publishable,
        scopes: ApiScope::readScopes(),
    )->plainTextKey);
}

it('resolves from an API key', function (): void {
    $tenant = Tenant::factory()->create();
    $key = publishableKeyFor($tenant);

    withHeader('Authorization', "Bearer {$key}")
        ->get('/_probe')
        ->assertOk()
        ->assertJson(['tenant' => $tenant->getKey(), 'via' => 'api_key']);
})->group('fast');

it('resolves from a verified custom domain', function (): void {
    $tenant = Tenant::factory()->create();
    Tenancy::forTenant($tenant, fn () => TenantDomain::factory()->create(['hostname' => 'book.aegean.gr']));

    get('http://book.aegean.gr/_probe')
        ->assertOk()
        ->assertJson(['tenant' => $tenant->getKey(), 'via' => 'custom_domain']);
})->group('fast');

it('does not resolve from a pending or disabled domain', function (): void {
    // A pending row is not a claim of ownership. Honouring one would let anyone
    // point a hostname at the platform and be served another operator's data.
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function (): void {
        TenantDomain::factory()->pending()->create(['hostname' => 'pending.example']);
        TenantDomain::factory()->disabled()->create(['hostname' => 'disabled.example']);
    });

    get('http://pending.example/_probe')->assertNotFound();
    get('http://disabled.example/_probe')->assertNotFound();
})->group('fast');

it('matches a custom domain case-insensitively and in punycode', function (): void {
    // Greek operators register Greek domains, and a browser sends the xn-- form.
    $tenant = Tenant::factory()->create();
    Tenancy::forTenant($tenant, fn () => TenantDomain::factory()->create(['hostname' => 'Κρουαζιέρες.gr']));

    get('http://xn--ixahncpd9apfl0a.gr/_probe')
        ->assertOk()
        ->assertJson(['tenant' => $tenant->getKey(), 'via' => 'custom_domain']);
})->group('fast');

it('resolves from the first path segment on the hosted host', function (): void {
    $tenant = Tenant::factory()->create(['slug' => 'aegean-blue']);

    get('http://book.kaiki.test/aegean-blue')
        ->assertOk()
        ->assertJson(['tenant' => $tenant->getKey(), 'via' => 'hosted_slug']);
})->group('fast');

it('does not read a path segment as a slug on any other host', function (): void {
    // Otherwise `/login` resolves an operator the day someone registers that
    // slug, on every host the application answers.
    Tenant::factory()->create(['slug' => 'aegean-blue']);

    get('http://other.example/aegean-blue')->assertNotFound();
})->group('fast');

it('404s a hosted page the operator has switched off', function (): void {
    // HOS-6. Switched off should look switched off, not broken.
    Tenant::factory()->create(['slug' => 'quiet-operator', 'hosted_page_enabled' => false]);

    get('http://book.kaiki.test/quiet-operator')->assertNotFound();
})->group('fast');

it('resolves from the panel session', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();

    actingAs($user)
        ->get('http://kaiki.test/_probe')
        ->assertOk()
        ->assertJson(['tenant' => $tenant->getKey(), 'via' => 'panel_session']);
})->group('fast');

it('resolves nothing for a super admin, who has no tenant', function (): void {
    $admin = User::factory()->superAdmin()->create();

    actingAs($admin)->get('http://kaiki.test/_probe')->assertNotFound();
})->group('fast');

it('prefers the API key over a matching custom domain', function (): void {
    // The caller named a tenant explicitly. A request holding a valid key must
    // never be reinterpreted by host.
    $keyTenant = Tenant::factory()->create();
    $hostTenant = Tenant::factory()->create();

    Tenancy::forTenant($hostTenant, fn () => TenantDomain::factory()->create(['hostname' => 'host.example']));
    $key = publishableKeyFor($keyTenant);

    withHeader('Authorization', "Bearer {$key}")
        ->get('http://host.example/_probe')
        ->assertOk()
        ->assertJson(['tenant' => $keyTenant->getKey(), 'via' => 'api_key']);
})->group('fast');

it('prefers a custom domain over the hosted slug', function (): void {
    $hostTenant = Tenant::factory()->create();
    Tenant::factory()->create(['slug' => 'slug-tenant']);

    Tenancy::forTenant($hostTenant, fn () => TenantDomain::factory()->create(['hostname' => 'book.kaiki.test']));

    get('http://book.kaiki.test/slug-tenant')
        ->assertOk()
        ->assertJson(['tenant' => $hostTenant->getKey(), 'via' => 'custom_domain']);
})->group('fast');

it('prefers the hosted slug over the signed-in user tenant', function (): void {
    // The case this ordering exists for: an operator signed into their own
    // panel opens a competitor's hosted page. They must see that operator's
    // public page, not their own back office bleeding through.
    $visiting = Tenant::factory()->create();
    $hosted = Tenant::factory()->create(['slug' => 'other-operator']);
    $user = User::factory()->forTenant($visiting)->create();

    actingAs($user)
        ->get('http://book.kaiki.test/other-operator')
        ->assertOk()
        ->assertJson(['tenant' => $hosted->getKey(), 'via' => 'hosted_slug']);
})->group('fast');

it('ignores a revoked key rather than resolving its tenant', function (): void {
    $tenant = Tenant::factory()->create();
    $key = publishableKeyFor($tenant);

    Tenancy::forTenant($tenant, function (): void {
        (new RevokeApiKey)(ApiKey::query()->firstOrFail());
    });

    withHeader('Authorization', "Bearer {$key}")->get('http://other.example/_probe')->assertNotFound();
})->group('fast');
