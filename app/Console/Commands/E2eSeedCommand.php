<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tenancy\Actions\GenerateApiKey;
use App\Enums\ApiKeyEnvironment;
use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Console\Command;
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
            ];
        });

        $path = (string) ($this->option('state') ?: base_path('packages/widget/e2e/.state.json'));

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $this->info("Seeded {$state['tenant_slug']}, key written to {$path}.");

        return self::SUCCESS;
    }
}
