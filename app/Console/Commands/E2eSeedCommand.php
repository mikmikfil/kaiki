<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tenancy\Actions\GenerateApiKey;
use App\Enums\ApiKeyEnvironment;
use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Enums\Role;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;

/**
 * `php artisan kaiki:e2e-prepare` — the world the Playwright run books in
 * (spec TST-3, issue 111).
 *
 * ## A command rather than a Playwright global setup in TypeScript
 *
 * Everything here is a decision the application already knows how to make: what
 * a seeded operator looks like, what scopes a widget key needs, which product is
 * bookable. A TypeScript setup that reconstructed any of it through HTTP would
 * be a second definition of the demo data, drifting from the seeders the moment
 * one of them changed — and the failure would read as a broken browser test
 * rather than as a stale fixture.
 *
 * ## The key is minted here because it can only exist once
 *
 * SEC-3: a publishable key is stored as a hash, a prefix and its last four. The
 * plaintext exists for exactly as long as the call that created it, so the run
 * has to be handed it at the moment of creation — which is what the state file
 * is for. It is **not** committed, and it is a `test` key: the bookings it
 * creates carry `is_test`, which is what routes them to the sandbox checkout
 * page and away from anybody's money (PAY-11).
 *
 * ## Origins are named, not left empty
 *
 * An empty allow-list means any origin. The run embeds the widget on a fixture
 * host, so naming that host exercises SEC-7's CORS path for real instead of
 * skipping past it — a suite whose key allowed everything would pass with the
 * origin check broken.
 *
 * ## The panel logins are published here for the same reason the key is
 *
 * OPS-22's specs sign in to `/app`. The three seeded operator users are
 * `DemoTenantSeeder`'s to define — one per role — and a spec that hardcoded
 * `maria@aegean-blue.example` would be a second copy of that decision, going
 * stale silently the day the seeder renames somebody. Publishing them through
 * the state file keeps one source and turns a rename into a failed seed rather
 * than a login page the run cannot get past.
 *
 * The password is the seeder's literal `password`, and it is not a secret: this
 * world exists for the length of one run, against a throwaway SQLite file.
 */
class E2eSeedCommand extends Command
{
    protected $signature = 'kaiki:e2e-prepare
        {--origin=* : Origins the widget key may be called from}
        {--state= : Where to write the run state JSON}';

    protected $description = 'Seed a bookable demo world and mint a test widget key for the Playwright run (TST-3).';

    public function handle(): int
    {
        if (app()->environment('production')) {
            // It drops every table. There is no flag for doing that on purpose.
            $this->error('Refusing to run in production.');

            return self::FAILURE;
        }

        $this->call('migrate:fresh', ['--force' => true, '--seed' => true]);

        $tenant = Tenant::query()->where('slug', 'aegean-blue')->first();

        if (! $tenant instanceof Tenant) {
            $this->error('No `aegean-blue` tenant after seeding — DemoTenantSeeder has changed.');

            return self::FAILURE;
        }

        /** @var list<string> $origins */
        $origins = array_values(array_filter((array) $this->option('origin')));

        $state = Tenancy::forTenant($tenant, function () use ($tenant, $origins): array {
            $key = (new GenerateApiKey)(
                name: 'Playwright',
                type: ApiKeyType::Publishable,
                scopes: [ApiScope::ProductsRead, ApiScope::AvailabilityRead, ApiScope::BookingsWrite, ApiScope::BrandingRead],
                environment: ApiKeyEnvironment::Test,
                allowedOrigins: $origins,
            )->plainTextKey;

            $product = Product::query()->sellable()->orderBy('sort_order')->firstOrFail();

            return [
                'key' => $key,
                'tenant_slug' => $tenant->slug,
                'product_uuid' => $product->uuid,
                'product_slug' => $product->slug,
                'origins' => $origins,
                'panel' => [
                    // Looked up by role rather than by address, so the run
                    // breaks here — loudly, before a browser starts — if the
                    // seeder ever stops producing one of the three.
                    'owner' => $this->emailForRole($tenant, Role::Owner),
                    'manager' => $this->emailForRole($tenant, Role::Manager),
                    'crew' => $this->emailForRole($tenant, Role::Crew),
                    'password' => 'password',
                ],
            ];
        });

        $path = (string) ($this->option('state') ?: base_path('packages/widget/e2e/.state.json'));

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $this->info("Seeded {$state['tenant_slug']}, key written to {$path}.");

        return self::SUCCESS;
    }

    /**
     * The seeded operator staff member holding one role.
     *
     * `tenant_id` is stated rather than inherited: `User` deliberately does not
     * use `BelongsToTenant` (a global scope there would hide super-admins from
     * their own panel), so nothing about being inside `forTenant` keeps this
     * off the other demo operator's owner.
     *
     * `firstOrFail` on purpose: an OPS-22 spec that cannot sign in should fail
     * here as a missing fixture, before a browser starts, rather than as a
     * timeout on a login form nobody can read.
     */
    private function emailForRole(Tenant $tenant, Role $role): string
    {
        return (string) User::query()
            ->where('tenant_id', $tenant->getKey())
            ->whereHas('roleAssignments', fn (Builder $query) => $query->where('role', $role))
            ->orderBy('id')
            ->firstOrFail()
            ->email;
    }
}
