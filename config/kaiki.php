<?php

declare(strict_types=1);
use App\Domain\Notifications\Gateways\ApifonSmsGateway;
use App\Domain\Notifications\Gateways\NullSmsGateway;
use App\Domain\Notifications\Gateways\TwilioSmsGateway;

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

        /*
         * What an operator's CNAME must point at for their custom domain to
         * verify (HOS-3, ADR-0010), added by #109.
         *
         * Separate from `hosted_host` because they are the same string today
         * and need not be tomorrow: a platform behind a CDN points customer
         * domains at the CDN's hostname while its own pages are served from the
         * origin. `VerifyDomain` accepts either, so changing this is a config
         * edit rather than a migration of every verified row.
         *
         * **Empty means nothing verifies.** An unconfigured platform must not
         * approve certificate requests for hostnames it cannot serve — see
         * `TlsAskController` for why that matters more than it looks.
         */
        'custom_domain_target' => env('KAIKI_CUSTOM_DOMAIN_TARGET', env('KAIKI_HOSTED_HOST', 'book.kaiki.test')),
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
            /*
             * Hull teal, settled in the design review of 2026-09-04 and landed
             * in issue 110 with the live preview that shows it.
             *
             * It replaced a blue placeholder nobody had chosen. The accent is a
             * rust orange rather than a second blue because it has to survive
             * being the only colour on a boat in daylight, and `text` is a very
             * dark teal rather than near-black so a page of an operator's own
             * defaults reads as one palette instead of a colour plus some ink.
             *
             * The column defaults in the `brand_profiles` migration move with
             * these five lines. `BrandProfileDefaultsTest` reads both.
             */
            'colors' => [
                'primary' => '#0B4F4A',
                'secondary' => '#063733',
                'accent' => '#B5511F',
                'background' => '#FFFFFF',
                'text' => '#16211F',
            ],
            'font_family' => 'Inter',
            'font_source' => 'system',
            'button_radius_px' => 8,
            /*
             * Light, not `auto`, since #106.
             *
             * Settled in the design review of 2026-09-04: guest-facing surfaces
             * are light and light/dark is a dashboard concern. `auto` handed the
             * decision to the visitor's operating system, which meant an
             * operator's colours — chosen against white, on a boat, in daylight
             * — were rendered on a dark ground for anybody whose phone was in
             * night mode, and the operator had no way to see it.
             *
             * The column default in the `brand_profiles` migration moves with
             * this line. `BrandProfileDefaultsTest` reads both and fails when
             * they disagree, which is the only thing that keeps a config value
             * and a schema default from drifting apart.
             */
            'widget_theme' => 'light',
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
    | Audit trail
    |--------------------------------------------------------------------------
    */

    'audit' => [

        /*
         * How long an audit row is kept, in days (ADR-0025 §3).
         *
         * **2555 — seven years, not ADR-0012's ninety.** The two requirements
         * pull in opposite directions and both are real: Greek bookkeeping
         * wants records available for years, and a trail that purges at ninety
         * days cannot answer a dispute about last season, which is the dispute
         * people actually have.
         *
         * They are reconciled by storing the actor as a `user_id` and never a
         * name. A GDPR erasure anonymises the user row; the trail keeps its
         * timestamps and its causality and simply stops identifying a person.
         * The legal basis for keeping it is the operator's own bookkeeping and
         * dispute-resolution obligation, not consent.
         *
         * Env-settable so a staging box can keep less, and platform-wide rather
         * than per tenant: retention is an obligation, not a preference.
         */
        'retention_days' => (int) env('KAIKI_AUDIT_RETENTION_DAYS', 2555),

        /*
         * When the nightly purge runs, platform-default timezone.
         *
         * Deliberately not the same minute as the departure generator: two jobs
         * that both take a while should not contend for the same connection
         * pool at 03:15 on a single Hetzner box.
         */
        'purge_at' => env('KAIKI_AUDIT_PURGE_AT', '04:10'),
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

            /*
             * `GET /api/v1/sync/products` asks for a page and gets the largest
             * one there is: §3.5 gives it a default and a maximum of 100, both.
             * A sync wants as few round trips as it can have, and the cap is
             * what stops one call asking for the whole catalogue at once.
             */
            'sync_per_page' => 100,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Exports
    |--------------------------------------------------------------------------
    */

    'exports' => [
        /*
         * The CSV field separator (OPS-8, OPS-17).
         *
         * A comma, and configurable because it is a property of the operator's
         * *machine* rather than of the data: Excel in a Greek locale splits on
         * a semicolon, so a technically correct comma-separated file opens as
         * one column and the harbourmaster cannot read it.
         *
         * Left as a comma by default because that is what everything except
         * Excel expects, and because an operator who needs the other one has a
         * support conversation rather than a broken file.
         */
        'csv_separator' => env('KAIKI_CSV_SEPARATOR', ','),

        /*
         * Where a finished export is written (OPS-18).
         *
         * `local` is the private disk — `storage/app/private` — and never
         * `public`. A bookings CSV carries every guest's name, email and phone
         * number, and the public disk is served by the web server at a
         * guessable path with no session in front of it.
         */
        'disk' => env('KAIKI_EXPORT_DISK', 'local'),

        /*
         * How long a finished export stays downloadable (OPS-18: 24 hours).
         *
         * Two things read this and both matter: the link stops working, and
         * `PurgeExpiredExportsJob` deletes the file. A link that expires over a
         * file that lives for ever is the disclosure GDR-2 exists to prevent,
         * and no screen in the product would ever mention it.
         */
        'link_ttl_hours' => (int) env('KAIKI_EXPORT_TTL_HOURS', 24),

        /*
         * How large the in-flight file grows before PHP spills it to disk.
         *
         * `php://temp` holds the export in memory up to this, then transparently
         * moves to a temporary file. Eight megabytes is tens of thousands of
         * rows — so an ordinary season never touches the disk, and a four-season
         * export does not touch the memory limit either.
         */
        'memory_spill_mb' => (int) env('KAIKI_EXPORT_SPILL_MB', 8),

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

        /*
         * The country a bare national phone number is read as (BKG-8).
         *
         * BKG-8 says "the guest-selected country or the **tenant** country as
         * default", and `tenants` has no country column — the operator profile
         * that would carry one is M7. This is the platform default standing in
         * for it: every operator this product is built for is Greek, so `GR` is
         * right for all of them today and the day it stops being right is the
         * day the column is worth adding.
         *
         * It only ever applies to a number with no `+` prefix. One that has one
         * has already answered the question.
         */
        'country' => 'GR',

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

    /*
    |--------------------------------------------------------------------------
    | Bookings and the seat hold (ADR-0005, ADR-0007)
    |--------------------------------------------------------------------------
    */

    'booking' => [

        /*
         * How long a draft booking holds its seats, in minutes (AVL-38).
         *
         * **Global, not per tenant**, and AVL-38 says so outright. A hold is a
         * promise made to one guest at the expense of every other guest looking
         * at the same boat, and an operator who sets it to two hours has not
         * bought themselves anything — they have taken their own inventory off
         * sale.
         *
         * Fifteen minutes is the brief's §5.4 figure. It is **not** the same
         * setting as `kaiki.pricing.quote_ttl_minutes`, and the two are
         * deliberately allowed to differ: AVL-40 says a hold does not reserve a
         * price, and lets the price outlive the hold plus a grace period.
         */
        'hold_minutes' => (int) env('KAIKI_HOLD_MINUTES', 15),

        /*
         * How long a recomputed price may differ from the snapshot before the
         * guest is shown the difference, in minutes (AVL-40).
         *
         * The grace on top of the hold. Without it, a guest who completes
         * checkout in the last second of their hold is re-quoted for no reason
         * they can see.
         */
        'price_grace_minutes' => 5,

        /*
         * The `Cache::lock` around a hold write (ADR-0005, AVL-37.2).
         *
         * **This is a mutex, not the hold.** Five seconds is a ceiling on a
         * critical section that takes milliseconds — long enough that a slow
         * query cannot drop the lock mid-write, short enough that a crashed
         * process cannot block the boat for a coffee break. The three-second
         * block is how long a second guest waits before being told to try
         * again, which is roughly the longest a person will stare at a spinner.
         */
        'hold_lock_seconds' => 5,
        'hold_lock_wait_seconds' => 3,

        /*
         * How often the stale-hold sweeper runs.
         *
         * Every minute, per ADR-0005. Correctness does not depend on it —
         * every availability read treats an expired hold as released on its own
         * (AVL-38) — but a seat that reads as free and still counts against the
         * stored counter is a discrepancy that shows up in the operator's
         * dashboard, so the counter is tidied promptly rather than eventually.
         */
        'sweeper_cron' => env('KAIKI_HOLD_SWEEPER_CRON', '* * * * *'),

        /*
         * The booking reference (BKG-3, ADR-0007).
         *
         * The prefix is config so that a rename changes one value and not the
         * schema. Five random characters over the 30-symbol alphabet is 24.3
         * million combinations per tenant; on the fifth collision the generator
         * widens to six, which is 729 million and is why the column is
         * `varchar(16)` rather than the ADR's `char(9)`.
         */
        'reference_prefix' => env('KAIKI_BOOKING_PREFIX', 'KAI'),
        'reference_length' => 5,
        'reference_attempts' => 5,

        /*
         * How long an abandoned checkout keeps its seats, in minutes (BKG-10).
         *
         * **The gateway session lifetime plus the grace period, together** —
         * sixty *total*, not sixty on top of a session that already lasted
         * thirty. It is a ceiling on how long a seat can be held by silence,
         * which is the number an operator would want to reason about.
         *
         * This exists because BKG-9 commits seats at redirect rather than at
         * the webhook: the alternative leaves the last seat buyable while a
         * guest types a card number, and the cost of not doing that is a guest
         * who closes the tab holding a committed seat that the hold sweeper
         * cannot touch.
         */
        'checkout_expiry_minutes' => (int) env('KAIKI_CHECKOUT_EXPIRY_MINUTES', 60),

        /*
         * The weather-cancellation choice deadline, in days (CXL-7).
         *
         * Fourteen, and the requirement is marked RESOLVED with its own
         * reason: the brief leaves the no-response case undefined, and money
         * that is not the operator's must not sit on a booking nobody will ever
         * close. A guest who never opens the email is not a guest who forfeited
         * their refund.
         *
         * The *choice* applied at the deadline is per tenant
         * (`tenants.weather_choice_default`); the deadline itself is not,
         * because a tenant who set it to a year would have reintroduced exactly
         * the problem the number exists to solve.
         */
        'weather_choice_deadline_days' => (int) env('KAIKI_WEATHER_CHOICE_DAYS', 14),

        /*
         * The single reminder before it (CXL-7), in hours.
         *
         * Seventy-two: long enough that it is not nagging somebody who is still
         * on the trip they were about to take, short enough to leave eleven
         * days to act on it.
         */
        'weather_choice_reminder_hours' => (int) env('KAIKI_WEATHER_CHOICE_REMINDER_HOURS', 72),

        /*
         * How long an operator's quote is good for, in days (BKG-26).
         *
         * Seven, and it is a **default** rather than a rule: `quotes.valid_until`
         * is a column and the operator sets it per quote, because a charter for
         * next weekend and one for next August are not the same offer.
         *
         * It matters more than it looks. BKG-25 ties an opt-in vessel hold's
         * expiry to this date exactly — so a careless thirty-day default would
         * be a careless thirty days of a boat off sale.
         */
        'quote_validity_days' => (int) env('KAIKI_QUOTE_VALIDITY_DAYS', 7),

    ],

    /*
    |--------------------------------------------------------------------------
    | Payment gateways (PAY-1, PAY-2, ADR-0004)
    |--------------------------------------------------------------------------
    |
    | **No endpoint is ever written into a class** (`CLAUDE.md`). They are here
    | so that a sandbox, a staging box and a test run can each point somewhere
    | different without touching code — and so the suite can point every call at
    | a host that cannot resolve.
    */

    /*
    |--------------------------------------------------------------------------
    | Notifications (spec NTF-1, NTF-2, NTF-5)
    |--------------------------------------------------------------------------
    |
    | **No endpoint is ever written into a class** (`CLAUDE.md`), so the two SMS
    | providers' hosts live here — and the suite points them at a host that
    | cannot resolve, exactly as the payment gateways are pinned.
    |
    | The gateway map is here rather than in a `match` inside the resolver so
    | that a deployment can substitute an implementation without a code change,
    | and so the fallback is a value somebody can read rather than a `default:`
    | arm somebody has to find.
    */
    'notifications' => [

        /*
         * How long any single provider call may take.
         *
         * Ten seconds, like the payment gateways. This runs on a worker rather
         * than while a guest waits, but a reminder sweep of four hundred
         * bookings behind a hung provider is a queue that never drains.
         */
        'timeout_seconds' => (int) env('KAIKI_SMS_TIMEOUT', 10),

        /*
         * NTF-2's three implementations, keyed by `NotificationProvider`.
         *
         * `null_gateway` is a real entry rather than an absence: it composes,
         * counts segments and logs, and sends nothing. An operator who has not
         * configured SMS can therefore *see* that their reminders are being
         * written and dropped, which is a five-minute fix — where a silent skip
         * is indistinguishable from a broken platform.
         */
        'gateways' => [
            'apifon' => ApifonSmsGateway::class,
            'twilio' => TwilioSmsGateway::class,
            'null_gateway' => NullSmsGateway::class,
        ],

        'apifon' => [
            'host' => env('KAIKI_APIFON_HOST', 'https://ars.apifon.com'),
        ],

        'twilio' => [
            'host' => env('KAIKI_TWILIO_HOST', 'https://api.twilio.com'),
        ],

        /*
         * The ceiling a reminder SMS may cost, in segments (NTF-5).
         *
         * Two. Greek falls back to UCS-2 at seventy characters a segment, so a
         * sentence with a meeting point, a time and a link is already close —
         * and `SmsComposer` trims the operator's own preamble rather than the
         * things NTF-5 fixes as mandatory.
         */
        'sms_max_segments' => 2,

    ],

    /*
    |--------------------------------------------------------------------------
    | The public API (`docs/api.md` §3.4)
    |--------------------------------------------------------------------------
    */
    'api' => [

        /*
         * `Idempotency-Key`, per §3.4.
         *
         * **The cache, and not a table.** §3.4 fixes the semantics and the
         * retention and says nothing about storage. A table would need a
         * migration, a model, a policy, a purge job and a schedule entry — five
         * moving parts to hold something worthless after a day that nobody
         * queries by anything but its exact key. The cache expires its own
         * rows, which is the whole of the retention requirement.
         *
         * The honest cost is that a cache flush forgets in-flight keys. That is
         * survivable because the Actions behind these endpoints are each
         * idempotent in their own right — see `IdempotencyStore`.
         *
         * `store` is null for the application default: Redis in production,
         * the database driver locally, and the array driver in tests, which is
         * exactly the isolation a test wants.
         */
        'idempotency' => [
            'store' => env('KAIKI_IDEMPOTENCY_STORE'),
            'retention_hours' => (int) env('KAIKI_IDEMPOTENCY_HOURS', 24),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Hosted operator pages (spec HOS-1 … HOS-10)
    |--------------------------------------------------------------------------
    |
    | `book.{platform-domain}/{operator-slug}` — the page an operator with no
    | website of their own hands out. Everything here is an origin or a switch;
    | the content is theirs.
    */
    'hosted' => [

        /*
         * Brand decision 6 of 2026-09-04: «powered by Kaiki» always, custom
         * domains included — stricter than what was proposed.
         *
         * A flag rather than a line in the template, so a white-label tier is a
         * configuration change instead of an edit to a Blade file somebody then
         * has to remember on the next release.
         */
        'powered_by' => (bool) env('KAIKI_HOSTED_POWERED_BY', true),

        /*
         * The origins HOS-8's Content-Security-Policy names.
         *
         * Null means "the same origin", which is the ordinary deployment: the
         * API, the widget and the hosted pages all come from the platform's own
         * host. They are configurable because a CDN or a separate API host is a
         * deployment decision (M8) and a policy hard-coded to `self` would have
         * to be edited to survive it.
         */
        'api_origin' => env('KAIKI_HOSTED_API_ORIGIN'),
        'widget_origin' => env('KAIKI_HOSTED_WIDGET_ORIGIN'),

        /*
         * Where each gateway sends a guest to pay, keyed by the provider value
         * in `integration_credentials`.
         *
         * Only the ones an operator has actually connected reach the policy —
         * an operator on Viva alone has no reason for a `form-action` that
         * admits anything else, and the narrower it is the less a
         * stored-content bug could do with it.
         *
         * Keyed rather than a single value so that a second gateway is an entry
         * here, which is what `HostedPageHeaders::gatewayOrigins()` already
         * expects to iterate.
         */
        'gateway_origins' => [
            'viva' => env('KAIKI_HOSTED_VIVA_ORIGIN', 'https://www.vivapayments.com'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | E-tickets (spec BKG-13.1, ENV-20, SEC-14)
    |--------------------------------------------------------------------------
    |
    | ENV-20: *"Browsershot requires Chromium. Locally it points at an installed
    | Chrome through an `.env` path; PDF tests are in the `chromium` group and
    | are skipped when the path is absent. CI always runs them."*
    |
    | So `chrome_path` is the switch that decides whether the PDF tests run at
    | all — and `Pest.php` skips the group with an **explicit message** rather
    | than passing quietly, because a silently skipped test is a test that has
    | stopped existing.
    */
    'tickets' => [

        /*
         * ENV-20's `.env` path. Empty in CI, where the binary is on `PATH`.
         */
        'chrome_path' => env('KAIKI_CHROME_PATH'),

        /*
         * The private disk, and never `public`.
         *
         * A ticket carries the guest's name, the meeting point and a scannable
         * ticket code. On the `public` disk the web server hands it to anybody
         * who can guess the path, with no session and no policy in the way —
         * which would be a wider hole than every token page in #86 put
         * together. It is streamed through a controller instead.
         */
        'disk' => env('KAIKI_TICKETS_DISK', 'local'),

        /*
         * How long a single render may take.
         *
         * Thirty seconds. A ticket for fourteen guests is fourteen pages of
         * inline SVG, and Chromium's cold start on a small Hetzner box is
         * several seconds of that — but a render still going at thirty seconds
         * is a render that is not going to finish.
         */
        'timeout_seconds' => (int) env('KAIKI_TICKETS_TIMEOUT', 30),

    ],

    'payments' => [

        /*
         * How long any single gateway call may take.
         *
         * Short, and deliberately so: this runs while a guest waits on a
         * checkout button, and AVL-46 forbids holding a row lock across it. Ten
         * seconds is longer than the gateway's own p99 and short enough that
         * a hung gateway is a message rather than a timed-out request.
         */
        'timeout_seconds' => (int) env('KAIKI_GATEWAY_TIMEOUT', 10),

        /*
         * How far out of date a signed webhook may be, in seconds (PAY-6).
         *
         * A valid signature over an old payload is a **replay**, and a verifier
         * that only checks the HMAC accepts one forever. Five minutes is the
         * industry-standard tolerance and is generous enough for clock skew
         * between a gateway's servers and a Hetzner box.
         */
        'webhook_tolerance_seconds' => (int) env('KAIKI_WEBHOOK_TOLERANCE', 300),

        'viva' => [
            /*
             * Three hosts per environment, because Viva splits them: the OAuth2
             * token comes from `accounts`, the order from `api`, and the guest
             * is sent to `checkout`. The demo and live domains are genuinely
             * different servers, so an environment mix-up here is a request to
             * the wrong host rather than a rejected key — a clearer failure,
             * and the reason these are separate values rather than one with a
             * path.
             */
            'live' => [
                'accounts' => env('KAIKI_VIVA_ACCOUNTS', 'https://accounts.vivapayments.com'),
                'api' => env('KAIKI_VIVA_API', 'https://api.vivapayments.com'),
                'checkout' => env('KAIKI_VIVA_CHECKOUT', 'https://www.vivapayments.com'),
            ],
            'demo' => [
                'accounts' => env('KAIKI_VIVA_DEMO_ACCOUNTS', 'https://demo-accounts.vivapayments.com'),
                'api' => env('KAIKI_VIVA_DEMO_API', 'https://demo-api.vivapayments.com'),
                'checkout' => env('KAIKI_VIVA_DEMO_CHECKOUT', 'https://demo.vivapayments.com'),
            ],
        ],

    ],

];
