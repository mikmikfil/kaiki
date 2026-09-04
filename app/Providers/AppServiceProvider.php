<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Catalog\Actions\GuardVesselCapacity;
use App\Domain\Media\Actions\StoreUploadedImage;
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
