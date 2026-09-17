<?php

declare(strict_types=1);

use App\Domain\Branding\Actions\GetBrandPayload;
use App\Domain\Branding\Actions\UpdateBrandProfile;
use App\Models\BrandProfile;
use App\Models\Tenant;
use App\Support\Tenancy;

/*
|--------------------------------------------------------------------------
| BRD-8: a saved colour shows on the next page load (2026-09-17)
|--------------------------------------------------------------------------
|
| The operator changed a colour, opened the page, and saw the old one for up
| to a minute, because nothing dropped the cached payload on save.
|
*/

it('shows a changed colour straight away rather than after the cache window', function (): void {
    $tenant = Tenant::factory()->create();

    Tenancy::forTenant($tenant, function () use ($tenant): void {
        $payload = app(GetBrandPayload::class);
        $before = $payload($tenant, 'el')['colors']['accent'];

        app(UpdateBrandProfile::class)(BrandProfile::query()->firstOrFail(), ['color_accent' => '#1F8FB5']);

        expect($before)->not->toBe('#1F8FB5')
            ->and(strtoupper((string) $payload($tenant, 'el')['colors']['accent']))->toBe('#1F8FB5');
    });
})->group('fast');
