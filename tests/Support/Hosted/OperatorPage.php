<?php

declare(strict_types=1);

namespace Tests\Support\Hosted;

use App\Domain\Hosted\Support\BlockSettings;
use App\Enums\HomeBlockType;
use App\Enums\HostedSiteMode;
use App\Models\Tenant;
use App\Support\Tenancy;

/**
 * Setup for the home-page tests of #102.
 *
 * A class rather than helper `function`s at the top of a Pest file, and this
 * time the rule was proved rather than quoted: `asOperator()` was written here
 * as a file-local function and collided with the identical helper in
 * `AuditTrailTest`, which is a **fatal error** rather than a failed assertion —
 * the whole suite stops, in a file that has nothing to do with either.
 *
 * The same thing happened in #101 with `hosted()`. Twice is a convention.
 */
final class OperatorPage
{
    /** An operator whose hosted page is switched on. */
    public static function operator(string $slug): Tenant
    {
        return Tenant::factory()->create(['slug' => $slug, 'hosted_site_mode' => HostedSiteMode::Full]);
    }

    /**
     * Run something inside one operator's tenancy.
     *
     * Blocks are tenant-scoped, so writing them needs a current tenant — and
     * writing them *outside* one would either throw or, worse, land unscoped.
     *
     * @param  callable(): void  $work
     */
    public static function as(Tenant $tenant, callable $work): void
    {
        Tenancy::forTenant($tenant, $work);
    }

    /**
     * One block as the editor would submit it.
     *
     * Settings come from {@see BlockSettings::defaults()} rather than from a
     * literal, so a setting added later reaches these tests instead of leaving
     * them asserting against a shape that no longer exists.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function input(HomeBlockType $type, array $overrides = []): array
    {
        return [
            'type' => $type->value,
            'is_visible' => true,
            'heading' => ['el' => 'Τίτλος', 'en' => 'Heading'],
            'settings' => BlockSettings::defaults($type),
            ...$overrides,
        ];
    }
}
