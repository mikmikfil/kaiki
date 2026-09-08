<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\MyDataGateway;
use App\Domain\Catalog\Actions\GuardVesselCapacity;
use App\Domain\Catalog\Actions\SaveProduct;
use App\Domain\Compliance\Gateways\NullMyDataGateway;
use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Tenancy\Support\DnsLookup;
use App\Domain\Tenancy\Support\SystemDnsLookup;
use App\Http\Middleware\SetLocale;
use App\Providers\Filament\PanelRenderHooks;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageManagerInterface;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One id per request (or per queued job), resolved lazily and reused by
        // every log line that request produces (OBS-2). A singleton rather than
        // middleware so it also exists for console commands and jobs, which
        // never pass through the HTTP stack.
        $this->app->singleton('kaiki.request_id', static fn (): string => (string) Str::uuid());

        // Everything that can already have promised seats on a vessel, so
        // GuardVesselCapacity can ask before `capacity_max` is lowered.
        //
        // **Deliberately empty today.** `products.max_pax` arrives with #18 and
        // `departures.capacity` with #23; until those tables exist nothing can
        // claim a seat and the guard correctly refuses nothing. Each of those
        // issues adds one implementation of VesselCapacityClaims and one `tag()`
        // line here — the Action, the message, its localisation and both
        // enforcement points are already built and tested.
        $this->app->tag([], GuardVesselCapacity::TAG);

        $this->app->singleton(
            GuardVesselCapacity::class,
            static fn ($app): GuardVesselCapacity => new GuardVesselCapacity($app->tagged(GuardVesselCapacity::TAG)),
        );

        // Everything that already holds a commitment against a product, so
        // SaveProduct can refuse a `mode` change after the first booking
        // (data-model §2.3).
        //
        // **Deliberately empty**, exactly like the vessel capacity claims
        // above: `bookings` does not exist until M2, so nothing can hold one
        // and the guard correctly refuses nothing. M2 adds one implementation
        // of ProductBookingCount and one entry here — the refusal, its Greek
        // message and its tests are already built and proven against a fake.
        $this->app->tag([], SaveProduct::TAG);

        $this->app->singleton(
            SaveProduct::class,
            static fn ($app): SaveProduct => new SaveProduct($app->tagged(SaveProduct::TAG)),
        );

        // **GD, named rather than detected.** `intervention/image` will happily
        // pick Imagick when it is present, and Imagick is not installed on the
        // local Windows stack (ENV-3) nor in the CI image — so a driver chosen
        // by availability would run one engine in development and a different
        // one in production, with the resize path tested on neither. GD covers
        // PNG and WebP on both, which is the whole of BRD-7's raster list.
        //
        // Bound to the interface so a test can swap the driver, and so
        // `media:rebuild` can type-hint it in `handle()`.
        $this->app->singleton(
            ImageManagerInterface::class,
            static fn (): ImageManagerInterface => new ImageManager(new Driver),
        );

        $this->app->singleton(
            StoreUploadedImage::class,
            static fn ($app): StoreUploadedImage => new StoreUploadedImage($app->make(ImageManagerInterface::class)),
        );

        // #109: what a custom hostname resolves to (HOS-3, ADR-0010).
        //
        // Bound to the interface for the same reason the image manager is — a
        // test cannot make a registrar answer "not yet", and a test that asked
        // the real resolver would pass on a laptop and fail on a runner behind
        // a proxy. `Tests\Support\Tenancy\FakeDns` is what takes its place.
        $this->app->singleton(DnsLookup::class, SystemDnsLookup::class);

        /*
         * The AADE client (MYD-1, MYD-11, M6).
         *
         * **The null one, today, and that is a deliberate default rather than a
         * placeholder.** The platform has no AADE credentials, and a binding
         * that reached for a real endpoint would fail at the moment of issuance
         * with a transport error rather than at the moment of configuration
         * with a sentence. `NullMyDataGateway` refuses cleanly and puts «Δεν
         * έχει συνδεθεί το myDATA» in front of the operator — see its docblock
         * for why it refuses rather than swallowing, which is the opposite of
         * what the SMS null gateway does and for a good reason.
         *
         * When credentials exist this becomes a resolver reading the tenant's
         * `IntegrationCredential`, the same shape `SmsGatewayResolver` has. The
         * interface is what makes that a one-line change here.
         */
        $this->app->bind(MyDataGateway::class, NullMyDataGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Once, not once per panel: Filament render hooks are global unless
        // scoped, so registering from both panel providers would draw the
        // language switcher twice. See PanelRenderHooks.
        PanelRenderHooks::register();

        // **Every button in the panel is a `POST /livewire/update`**, and on
        // that endpoint `SetLocale` would otherwise run from the `web` group
        // only — before `ResolveTenant`, which Filament registers as *Livewire
        // persistent* middleware and which therefore runs later, inside the
        // request. The tenant is null when the locale is decided, so step 5 of
        // the I18N-5 chain and the `supported_locales` narrowing both vanish on
        // exactly the requests an operator makes most.
        //
        // The visible symptom is a half-translated panel: correct on a full
        // page load, wrong on every table sort, filter and modal. This is the
        // same class of bug as `e62d7e1`, where the panel's tenancy middleware
        // never ran on this endpoint either. Registering it here re-runs it
        // after `ResolveTenant`, and the priority list agrees on that order.
        Livewire::addPersistentMiddleware(SetLocale::class);
    }
}
