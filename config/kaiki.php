<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | API keys
    |--------------------------------------------------------------------------
    */
    'api_keys' => [
        /*
         * Random characters after the `{type}_{environment}_` marker. 32 chars
         * of base62 is ~190 bits, far beyond what a bearer credential needs,
         * and short enough to paste into a WordPress settings field.
         */
        'secret_length' => 32,

        /*
         * How much of the key is stored in plaintext as the lookup handle.
         * `pk_live_` plus this many characters. Uniqueness is enforced by the
         * index and generation retries on collision.
         */
        'prefix_random_length' => 6,

        /*
         * `last_used_at` is written at most once per key per this many seconds.
         * Authentication happens on every public API request; without the
         * throttle, every read becomes a write and the availability endpoint's
         * p95 budget goes with it.
         */
        'last_used_throttle_seconds' => 60,

        /*
         * Attempts before generation gives up on a prefix collision. Each retry
         * draws a fresh prefix; hitting this many in a row means something is
         * wrong with the random source, not with luck.
         */
        'generation_attempts' => 5,

        /*
         * The panel warns when a key has gone this long without being used
         * (ADR-0013). A key nobody uses is a key nobody would notice leaking,
         * and rotation here is create-then-revoke — so the warning is the only
         * prompt an operator gets to tidy one away.
         */
        'unused_warning_days' => (int) env('KAIKI_API_KEY_UNUSED_WARNING_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenancy
    |--------------------------------------------------------------------------
    */
    'tenancy' => [
        /*
         * The host that serves operator hosted pages as
         * `book.{platform-domain}/{slug}`. Only this host reads the first path
         * segment as an operator slug — doing that on every host would turn
         * `/login` into a tenant lookup the day someone registers that slug.
         */
        'hosted_host' => env('KAIKI_HOSTED_HOST', 'book.kaiki.test'),

        /*
         * How long a hostname-to-tenant or slug-to-tenant answer is cached.
         * Resolution runs on every request including the hottest public read,
         * so it must not be a database round trip each time — but the window is
         * short, because disabling a domain or a hosted page should take effect
         * in seconds rather than needing a deploy.
         */
        'host_cache_seconds' => (int) env('KAIKI_HOST_CACHE_SECONDS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Panel
    |--------------------------------------------------------------------------
    */
    'panel' => [
        /*
         * How far either side of today crew may see departures (TEN-8).
         *
         * Crew access is read-only and deliberately narrow: someone standing on
         * the quay needs tomorrow's list, not the season's. Configurable
         * because a multi-day charter operation will want a wider window than a
         * day-trip one, and neither should have to change code to get it.
         */
        'crew_departure_window_days' => (int) env('KAIKI_CREW_WINDOW_DAYS', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | i18n
    |--------------------------------------------------------------------------
    */
    'i18n' => [
        /*
         * The locales a translatable field must be filled in before it may be
         * saved (`docs/data-model.md` §1.6: "Both keys are required on write;
         * a model observer rejects a translation set missing `el` or `en`").
         *
         * A **third** locale list, deliberately not derived from
         * `app.available_locales`. EXT-7 says adding `it` or `de` must be "a
         * lang-file plus widget-bundle addition only" — derive this from the
         * installed locales and shipping German lang files would instantly
         * invalidate every product in every catalogue and lock operators out of
         * their own data. Growing this list is a migration and a backfill, not
         * a config edit.
         *
         * Not env-settable for the same reason. An environment variable that
         * silently relaxes a data requirement means staging and production
         * disagree about what a valid product is, and the import that passed in
         * staging is the one that fails on launch day.
         */
        'required_locales' => ['el', 'en'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    */
    'branding' => [

        /*
         * How long `GET /api/v1/branding` may be cached, in seconds.
         *
         * Sixty, from `docs/api.md` §3.6 — the contract is the authority
         * (§10.5), and it says 60 where issue #35 said 300. It is both the
         * server-side cache TTL and the `Cache-Control: max-age`, deliberately:
         * two numbers would mean a CDN serving something the origin had already
         * forgotten.
         *
         * BRD-8 is what it buys — an operator changes a colour and the widget
         * picks it up within a minute, with no rebuild.
         */
        'cache_ttl_seconds' => 60,

        /*
         * The platform defaults a BrandProfile is created with, so that no
         * surface ever renders unbranded (spec BRD-3). These values are also
         * the column defaults in the `brand_profiles` migration — the column
         * default protects a row written around the observer, this is what the
         * observer writes, and `BrandProfileDefaultsTest` asserts the two agree
         * by reading the schema. Two sources that silently disagree would mean
         * a tenant's brand depending on which code path created it.
         *
         * Not env-settable. A platform default that differs between staging and
         * production means the screenshot in a support ticket does not match
         * what the operator is looking at.
         */
        'defaults' => [
            'colors' => [
                'primary' => '#0F62FE',
                'secondary' => '#0B3D91',
                'accent' => '#FFB000',
                'background' => '#FFFFFF',
                'text' => '#101828',
            ],
            'font_family' => 'Inter',
            'font_source' => 'system',
            'button_radius_px' => 8,
            'widget_theme' => 'auto',
        ],

        /*
         * WCAG AA, the two thresholds BRD-5 names. Body text on background is
         * judged at 4.5:1; button text on the primary colour is a UI component
         * and is judged at 3:1. It **warns and does not block** — an operator
         * whose brand has been on their boats for fifteen years is not going to
         * be told by a booking system that it is the wrong colour.
         */
        'contrast' => [
            'body_text_ratio' => 4.5,
            'ui_component_ratio' => 3.0,
        ],

        /*
         * Uploads (BRD-7, SEC-13).
         */
        'uploads' => [
            /*
             * `local` is `storage/app/private` with Laravel's signed local
             * serving enabled — outside the web root and reachable only through
             * a signed URL, which is what SEC-13 asks for. A public disk here
             * would satisfy the form and quietly fail the requirement.
             */
            'disk' => env('KAIKI_BRAND_DISK', 'local'),

            /*
             * 2 MB, from BRD-7. In kilobytes because that is the unit Laravel's
             * `max:` validation rule takes, and converting at the call site is
             * how a limit ends up meaning something different in two places.
             */
            'max_kilobytes' => 2048,

            /*
             * Checked against the file's magic bytes, not its extension and not
             * the browser-supplied Content-Type (SEC-13). BRD-7 fixes this list
             * at SVG, PNG and WebP; JPEG is deliberately absent, because a logo
             * that needs a photographic codec is a logo with a white box around
             * it on a dark widget.
             */
            'mime_types' => ['image/png', 'image/webp', 'image/svg+xml'],

            /*
             * Fixed widths in pixels, generated synchronously on upload
             * (ADR-0021, Option A — do not queue conversions). Changing a number
             * here changes nothing already on disk; `php artisan media:rebuild`
             * is what applies it to existing files.
             *
             * SVG is never in this table and never rasterised: it is already
             * resolution-independent, and generating a 200px PNG from it would
             * throw away the only reason to accept the format.
             */
            'variants' => [
                'logo' => [200, 400, 800],
                'favicon' => [32, 180],
                'email_header' => [600, 1200],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Catalogue
    |--------------------------------------------------------------------------
    */

    'catalog' => [

        /*
         * Where product, vessel and port images live.
         *
         * Separate from `kaiki.branding.uploads.disk`, which defaults to
         * `local` on purpose: a brand asset may be served through a signed URL
         * (SEC-13), while a catalogue photo is a public marketing image that
         * `GET /api/v1/products` hands to a third-party page as a plain URL.
         *
         * **The panel writes to this same disk.** Before #36 the two
         * `FileUpload` fields in `PortResource` and `VesselResource` used
         * Filament's default, which follows `FILESYSTEM_DISK=local` — and the
         * `local` disk cannot produce a URL at all. Reader and writer naming
         * one config value is what stops the API returning a link to a file
         * nothing can fetch.
         */
        'uploads' => [
            'disk' => env('KAIKI_CATALOG_DISK', 'public'),
        ],

        /*
         * `GET /api/v1/products` and `/products/{uuid}`, from `docs/api.md`
         * §3.5 and §3.6. Both the server-side answer and the `Cache-Control:
         * max-age`, for the reason the branding block gives: two numbers mean a
         * CDN serving something the origin has already forgotten.
         */
        'api' => [
            'cache_ttl_seconds' => 60,
            'per_page' => 24,
            'max_per_page' => 100,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pricing
    |--------------------------------------------------------------------------
    */

    'pricing' => [

        /*
         * How long a quoted price stands (spec PRC-15).
         *
         * A quote is a calculation with a shelf life, not a reservation: it
         * holds no seat and blocks nothing. The window exists so a guest who
         * left the checkout open over lunch is re-quoted rather than charged
         * yesterday's season price, and so the widget can say when the figure
         * it is showing stops being true.
         *
         * Twenty minutes matches the seat-hold window a booking will take in
         * M2, so a guest who completes checkout inside the quote's life never
         * sees the price move underneath them.
         */
        'quote_ttl_minutes' => 20,

    ],

    /*
    |--------------------------------------------------------------------------
    | Platform defaults
    |--------------------------------------------------------------------------
    */

    'defaults' => [

        /*
         * The timezone a tenant gets when they do not choose one (CNV-2).
         *
         * Deliberately **not** `config('app.timezone')`, which is UTC: storage
         * is UTC and display is local, and defaulting display to UTC would be
         * silently wrong by two or three hours on every departure time an
         * operator reads.
         */
        'timezone' => 'Europe/Athens',

    ],

    /*
    |--------------------------------------------------------------------------
    | Departure generation (ADR-0009)
    |--------------------------------------------------------------------------
    */

    'departures' => [

        /*
         * The rolling horizon, in days (ADR-0009 Option A).
         *
         * 400 covers a full season plus the "book next summer" case, and
         * comfortably exceeds any realistic `max_advance_days`. A per-tenant
         * override is reserved for later and deliberately not built.
         */
        'horizon_days' => 400,

        /*
         * When the nightly job runs, in the tenant timezone.
         *
         * 03:15 rather than a round hour: it is after the DST transitions
         * (03:00 and 04:00 local) have settled, and off the hour that every
         * other scheduled job in the world picks.
         */
        'nightly_at' => '03:15',

    ],

    /*
    |--------------------------------------------------------------------------
    | Availability (AVL-31)
    |--------------------------------------------------------------------------
    */

    'availability' => [

        /*
         * How long `GET /api/v1/availability` may be cached, in seconds.
         *
         * Thirty, from `docs/api.md` §3.6 — deliberately half the catalogue's
         * sixty. Availability is the one payload that goes stale because
         * *somebody else bought a seat*, so it carries the shortest window of
         * any read in the API. WGT-17's in-memory widget cache sits inside it.
         */
        'api' => [
            'cache_ttl_seconds' => 30,
        ],

        /*
         * The granularity a guest may propose a charter start on.
         *
         * Fifteen minutes is the smallest unit an operator actually schedules
         * in. Without a grid a guest books 09:07, the crew reads 09:07, and
         * every downstream display has to decide whether to round it.
         */
        'grid_minutes' => 15,

        /*
         * The daily window a charter may start in, local time (AVL-31 default).
         *
         * A product's own `earliest_start_time` and `latest_start_time` win
         * where the operator set them; this is what applies otherwise. A boat
         * that can be chartered at 03:00 is a boat whose crew finds out at
         * 03:00.
         */
        'operating_window' => [
            'earliest' => '06:00',
            'latest' => '23:00',
        ],

        /*
         * How many whole hours a guest may add to a flexible charter.
         *
         * Whole hours because `extra_hour_price_cents` is priced by the hour
         * and half of one has no price. Bounded because an unbounded extension
         * lets a single request block a vessel calendar for a fortnight.
         */
        'max_extension_hours' => 6,

    ],

];
