<?php

declare(strict_types=1);

use App\Enums\ApiKeyType;
use App\Enums\ApiScope;
use App\Enums\ProductStatus;
use App\Enums\Role;
use App\Models\AgeBand;
use App\Models\Booking;
use App\Models\CancellationPolicy;
use App\Models\Departure;
use App\Models\Port;
use App\Models\Product;
use App\Models\RatePlan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vessel;
use App\Support\Tenancy;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportRedirects\SupportRedirects;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withHeaders;

use Tests\Support\Api\CatalogRequest;
use Tests\Support\Hosted\HostedRequest;
use Tests\Support\OperatorUser;

/*
|--------------------------------------------------------------------------
| Every screen, as every role, over an account nobody finished setting up
|--------------------------------------------------------------------------
|
| Everything Mike has found by hand in September was in the panel, and every
| one of those screens had tests — of its rules, not of itself opening. The
| rules were right and the screen threw: an archived trip took the whole panel
| down on the 22nd, a trashed one took the home page down on the 23rd.
|
| So this walks every GET route both panels register — each custom page, each
| resource's list, create, view and edit — as owner, manager and crew, and the
| admin panel as the platform. It asks two things of each response:
|
|   1. **Never a 500.** A 403, a 404 or a redirect is a decision; a 500 is not.
|   2. **Never another operator's record.** Every `{record}` route is opened
|      once with this operator's record and once with a second operator's. The
|      second must not render.
|
| The account is deliberately half-built — the shape a real operator's data
| takes between signing up and going live — because that is where screens
| meet the nulls nobody seeded.
|
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-08 10:00:00');
});

/** The dirty operator: every half-finished shape at once. */
function sweepDirtyTenant(): Tenant
{
    $tenant = Tenant::factory()->create(['name' => 'Dirty Seas']);

    Tenancy::forTenant($tenant, function (): void {
        $port = Port::factory()->withoutCoordinates()->create();
        $boat = Vessel::factory()->atPort($port)->create();
        $portless = Vessel::factory()->create(['home_port_id' => null]);
        $laidUp = Vessel::factory()->inMaintenance()->create();

        CancellationPolicy::factory()->create(); // no tiers at all

        // A trip with no price and no age bands.
        Product::factory()->create(['vessel_id' => $portless->getKey()]);

        // A price with no ages: the rate plan exists, the bands do not.
        $priced = Product::factory()->create(['vessel_id' => $boat->getKey()]);
        RatePlan::factory()->create(['product_id' => $priced->getKey()]);

        // A draft, a quote trip, and a charter.
        Product::factory()->draft()->create(['vessel_id' => $boat->getKey()]);
        Product::factory()->quote()->create(['vessel_id' => $boat->getKey()]);
        Product::factory()->perVessel()->create(['vessel_id' => $boat->getKey()]);

        // Archived, with departures still in the future and a live rate plan.
        $archived = Product::factory()->create([
            'vessel_id' => $boat->getKey(),
            'status' => ProductStatus::Archived,
        ]);
        RatePlan::factory()->create(['product_id' => $archived->getKey()]);
        Departure::factory()->at('2026-07-20', '09:00')->withSeats(3)->create([
            'product_id' => $archived->getKey(),
            'vessel_id' => $boat->getKey(),
        ]);

        // In the bin, with a live rate plan, age bands and a booking on it.
        $binned = Product::factory()->create(['vessel_id' => $boat->getKey()]);
        RatePlan::factory()->create(['product_id' => $binned->getKey()]);
        AgeBand::factory()->create(['product_id' => $binned->getKey()]);
        $binnedDeparture = Departure::factory()->at('2026-07-08', '09:00')->withSeats(2)->create([
            'product_id' => $binned->getKey(),
            'vessel_id' => $boat->getKey(),
        ]);
        Booking::factory()->forDeparture($binnedDeparture)->create(['product_id' => $binned->getKey()]);
        $binned->delete();

        // A boat in maintenance with a booking on it today.
        $onLaidUp = Departure::factory()->at('2026-07-08', '11:00')->withSeats(4)->create([
            'product_id' => $priced->getKey(),
            'vessel_id' => $laidUp->getKey(),
        ]);
        Booking::factory()->forDeparture($onLaidUp)->create(['product_id' => $priced->getKey()]);

        // A hold that lapsed and was never swept, a booking at the gateway,
        // and one on a departure the operator cancelled.
        $today = Departure::factory()->at('2026-07-08', '17:00')->withSeats(0, 2)->create([
            'product_id' => $priced->getKey(),
            'vessel_id' => $boat->getKey(),
        ]);
        Booking::factory()->heldButExpired($today)->create(['product_id' => $priced->getKey()]);
        Booking::factory()->pendingPayment()->forDeparture($today)->create(['product_id' => $priced->getKey()]);

        $cancelled = Departure::factory()->at('2026-07-09', '09:00')->cancelled()->create([
            'product_id' => $priced->getKey(),
            'vessel_id' => $boat->getKey(),
        ]);
        Booking::factory()->forDeparture($cancelled)->create(['product_id' => $priced->getKey()]);

        // A booking with no departure at all, the shape an import leaves.
        Booking::factory()->create(['product_id' => $priced->getKey(), 'departure_id' => null]);
    });

    return $tenant;
}

/** A second, ordinary operator whose records the first must never open. */
function sweepNeighbour(): Tenant
{
    $tenant = Tenant::factory()->create(['name' => 'Next Door']);

    Tenancy::forTenant($tenant, function (): void {
        $port = Port::factory()->create();
        $boat = Vessel::factory()->atPort($port)->create();
        $trip = Product::factory()->create(['vessel_id' => $boat->getKey()]);
        RatePlan::factory()->create(['product_id' => $trip->getKey()]);
        AgeBand::factory()->create(['product_id' => $trip->getKey()]);
        CancellationPolicy::factory()->withTiers()->create();
        $departure = Departure::factory()->at('2026-07-10', '09:00')->withSeats(2)->create([
            'product_id' => $trip->getKey(),
            'vessel_id' => $boat->getKey(),
        ]);
        Booking::factory()->forDeparture($departure)->create(['product_id' => $trip->getKey()]);
    });

    return $tenant;
}

/**
 * Every GET route a panel registers, split by whether it takes a record.
 *
 * Routes with any parameter other than `{record}` are returned separately so
 * the report can say what it did not open rather than pretend it covered them.
 *
 * @return array{plain: list<LaravelRoute>, record: list<LaravelRoute>, skipped: list<string>}
 */
function sweepRoutes(string $panel): array
{
    $plain = $record = $skipped = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();

        if (! str_starts_with($name, "filament.{$panel}.") || ! in_array('GET', $route->methods(), true)) {
            continue;
        }

        // Leaving, arriving and resetting a password are not screens.
        if (preg_match('/\.(auth|logout|login|password-reset|email-verification|tenant-registration)\b/', $name) === 1) {
            continue;
        }

        $parameters = $route->parameterNames();

        match (true) {
            $parameters === [] => $plain[] = $route,
            $parameters === ['record'] => $record[] = $route,
            default => $skipped[] = $name,
        };
    }

    return ['plain' => $plain, 'record' => $record, 'skipped' => $skipped];
}

/** The model class behind a resource route, from the panel's own registry. */
function sweepModelFor(string $panel, string $routeName): ?string
{
    foreach (Filament::getPanel($panel)->getResources() as $resource) {
        $prefix = "filament.{$panel}.resources.{$resource::getSlug()}.";

        if (str_starts_with($routeName, $prefix)) {
            return $resource::getModel();
        }
    }

    return null;
}

/** Up to 25 records of a model as a tenant sees it, bin included. */
function sweepRecordsOf(string $model, Tenant $tenant): Collection
{
    return Tenancy::forTenant($tenant, static function () use ($model): Collection {
        $query = $model::query();

        if (method_exists($model, 'bootSoftDeletes')) {
            $query->withTrashed();
        }

        return $query->oldest((new $model)->getKeyName())->limit(25)->get();
    });
}

/**
 * Put Laravel's redirector back, as a fresh request would find it.
 *
 * Livewire swaps its own redirector in while a component mounts and swaps it
 * back when the component dehydrates. A component that refuses in `mount()` —
 * a 403 for crew, which is correct — never dehydrates, so the swap outlives
 * the request. On a server every request boots clean and nobody sees it; in
 * one test process the *next* request's redirect comes back as a Livewire
 * object and the session middleware throws on it. That 500 belongs to the
 * harness, not to any screen, so it is undone here rather than reported.
 */
function sweepRestoreRedirector(): void
{
    SupportRedirects::$redirectorCacheStack = [];

    app()->singleton('redirect', static function ($app): Redirector {
        $redirector = new Redirector($app['url']);

        if (isset($app['session.store'])) {
            $redirector->setSession($app['session.store']);
        }

        return $redirector;
    });
    app()->forgetInstance('redirect');
}

/**
 * GET a URL as a user, and describe any response that is not a decision.
 *
 * @return string|null a line for the report, or null when the response is fine
 */
function sweepHit(User $user, string $url, bool $mustNotRender = false): ?string
{
    sweepRestoreRedirector();

    $response = actingAs($user)->get($url);
    $status = $response->status();

    $GLOBALS['sweepTally'][$status] = ($GLOBALS['sweepTally'][$status] ?? 0) + 1;

    $GLOBALS['sweepTally'][$status] = ($GLOBALS['sweepTally'][$status] ?? 0) + 1;

    if ($status >= 500) {
        $exception = $response->exception;
        $where = $exception === null ? '' : sprintf(
            ' — %s: %s @ %s:%d',
            class_basename($exception),
            str($exception->getMessage())->limit(160),
            str_replace(base_path() . DIRECTORY_SEPARATOR, '', $exception->getFile()),
            $exception->getLine(),
        );

        return "{$status} {$url}{$where}";
    }

    if ($mustNotRender && $status === 200) {
        return "LEAK {$url} rendered another operator's record";
    }

    return null;
}

/** How many responses of each status a sweep saw, so a run that met only redirects says so. */
function sweepReport(string $label): void
{
    $tally = $GLOBALS['sweepTally'] ?? [];
    ksort($tally);

    fwrite(STDERR, sprintf(
        "\n%s: %d requests — %s\n",
        $label,
        array_sum($tally),
        implode(', ', array_map(static fn (int $status, int $n): string => "{$status}×{$n}", array_keys($tally), $tally)),
    ));

    $GLOBALS['sweepTally'] = [];
}

it('opens every /app screen as every role without a 500 or a neighbour\'s record', function (): void {
    $dirty = sweepDirtyTenant();
    $neighbour = sweepNeighbour();
    $routes = sweepRoutes('app');

    expect($routes['plain'])->not->toBeEmpty();

    $problems = [];

    foreach ([Role::Owner, Role::Manager, Role::Crew] as $role) {
        $user = OperatorUser::withRole($role, $dirty);

        foreach ($routes['plain'] as $route) {
            $line = sweepHit($user, '/' . $route->uri());
            if ($line !== null) {
                $problems[] = "[{$role->value}] {$line}";
            }
        }

        foreach ($routes['record'] as $route) {
            $model = sweepModelFor('app', (string) $route->getName());
            if ($model === null) {
                continue;
            }

            // Every one of this operator's rows, not the first: the archived
            // trip and the one in the bin are the reason the account is dirty.
            foreach (sweepRecordsOf($model, $dirty) as $own) {
                $line = sweepHit($user, route($route->getName(), ['record' => $own->getRouteKey()], false));
                if ($line !== null) {
                    $problems[] = "[{$role->value}] {$line}";
                }
            }

            $theirs = sweepRecordsOf($model, $neighbour)->first();
            if ($theirs !== null) {
                $line = sweepHit($user, route($route->getName(), ['record' => $theirs->getRouteKey()], false), mustNotRender: true);
                if ($line !== null) {
                    $problems[] = "[{$role->value}] {$line}";
                }
            }
        }
    }

    sweepReport('/app sweep');

    expect($problems)->toBe([], "\n" . implode("\n", $problems));
})->group('stress');

it('opens every /admin screen as the platform without a 500', function (): void {
    sweepDirtyTenant();
    sweepNeighbour();
    $routes = sweepRoutes('admin');
    $admin = User::factory()->superAdmin()->create();

    $problems = [];

    foreach ($routes['plain'] as $route) {
        $line = sweepHit($admin, '/' . $route->uri());
        if ($line !== null) {
            $problems[] = $line;
        }
    }

    foreach ($routes['record'] as $route) {
        $model = sweepModelFor('admin', (string) $route->getName());
        if ($model === null) {
            continue;
        }

        // The platform's records are not tenant-owned, or it reads across all
        // of them: every row is its own, so every row is fair to open.
        foreach ($model::query()->limit(5)->get() as $record) {
            $line = sweepHit($admin, route($route->getName(), ['record' => $record->getRouteKey()], false));
            if ($line !== null) {
                $problems[] = $line;
            }
        }
    }

    sweepReport('/admin sweep');

    expect($problems)->toBe([], "\n" . implode("\n", $problems));
})->group('stress');

/**
 * GET as a guest, with or without a key, and describe any 500.
 *
 * @param  array<string, string>  $headers
 */
function sweepGuestHit(string $url, array $headers = []): ?string
{
    sweepRestoreRedirector();

    $response = withHeaders($headers)->get($url);
    $status = $response->status();

    $GLOBALS['sweepTally'][$status] = ($GLOBALS['sweepTally'][$status] ?? 0) + 1;

    if ($status < 500) {
        return null;
    }

    $exception = $response->exception;

    return sprintf(
        '%d %s%s',
        $status,
        $url,
        $exception === null ? '' : sprintf(
            ' — %s: %s @ %s:%d',
            class_basename($exception),
            str($exception->getMessage())->limit(160),
            str_replace(base_path() . DIRECTORY_SEPARATOR, '', $exception->getFile()),
            $exception->getLine(),
        ),
    );
}

it('serves every guest page and API read of the dirty operator without a 500', function (): void {
    $dirty = sweepDirtyTenant();
    [, $key] = CatalogRequest::key($dirty, ApiKeyType::Secret, ApiScope::cases());

    $products = Tenancy::forTenant($dirty, fn (): Collection => Product::query()->withTrashed()->get());
    $bookings = Tenancy::forTenant($dirty, fn (): Collection => Booking::query()->get());

    $problems = [];
    $check = static function (?string $line) use (&$problems): void {
        if ($line !== null) {
            $problems[] = $line;
        }
    };

    // The hosted site, including every trip slug — archived, draft and binned
    // ones too, which must answer 404 or 410 and never throw.
    foreach (['', '/contact', '/legal', '/search', '/search?date=2026-07-10&adults=2&children=1'] as $path) {
        $check(sweepGuestHit(HostedRequest::url("/{$dirty->slug}{$path}")));
    }
    foreach ($products as $product) {
        $check(sweepGuestHit(HostedRequest::url("/{$dirty->slug}/{$product->slug}")));
    }

    // Every page a guest's link opens, for every shape of booking.
    foreach ($bookings as $booking) {
        foreach (['/b/%s', '/b/%s/calendar.ics', '/b/%s/ticket', '/c/%s'] as $pattern) {
            $check(sweepGuestHit(sprintf($pattern, $booking->manage_token)));
        }
        if ($booking->guest_details_token !== null) {
            $check(sweepGuestHit("/g/{$booking->guest_details_token}"));
        }
    }

    // The public API, read with a secret key that holds every scope.
    $auth = ['X-Kaiki-Key' => $key, 'Accept' => 'application/json'];
    foreach (['/products', '/branding', '/search', '/search?date=2026-07-10&adults=2', '/sync/products'] as $path) {
        $check(sweepGuestHit(CatalogRequest::url($path), $auth));
    }
    foreach ($products as $product) {
        $check(sweepGuestHit(CatalogRequest::url("/products/{$product->uuid}"), $auth));
        $check(sweepGuestHit(CatalogRequest::url('/availability', [
            'product' => $product->uuid, 'from' => '2026-07-01', 'to' => '2026-07-31',
        ]), $auth));
        $check(sweepGuestHit(CatalogRequest::url('/availability', [
            // A party, by slug: the path where a trip with no base age band
            // has to degrade rather than throw.
            'product' => $product->slug, 'from' => '2026-07-08', 'to' => '2026-07-08', 'pax' => '2',
        ]), $auth));
    }

    sweepReport('guest sweep');

    expect($problems)->toBe([], "\n" . implode("\n", $problems));
})->group('stress');

it('says which panel routes it could not open', function (): void {
    // Not a failure: a record of what the two sweeps above do not cover,
    // so nobody reads «every route» as more than it is.
    $skipped = [...sweepRoutes('app')['skipped'], ...sweepRoutes('admin')['skipped']];

    expect($skipped)->toBeArray();

    if ($skipped !== []) {
        fwrite(STDERR, "\nNot swept (parameters other than {record}):\n  " . implode("\n  ", $skipped) . "\n");
    }
})->group('stress');
