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

];
