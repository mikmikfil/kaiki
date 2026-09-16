<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Proxies whose forwarded headers are believed
    |--------------------------------------------------------------------------
    |
    | Empty trusts none, which is the right default for an application that can
    | be reached directly: `X-Forwarded-For` and `X-Forwarded-Proto` are written
    | by the caller, so believing them from an untrusted source lets anyone claim
    | any client IP, host and scheme they like — and rate limits, audit rows and
    | generated URLs all rest on those three.
    |
    | `*` trusts the immediate caller, which is right behind a tunnel on a laptop
    | or a container whose only ingress is its own proxy. A deployment with a
    | fixed edge should name it: `10.0.0.5` or a comma-separated list.
    |
    | **Why this file rather than `trustProxies(at: …)` in `bootstrap/app.php`:**
    | that closure runs before `.env` is loaded, so `env()` there returns null and
    | the setting silently does nothing — which is exactly what happened on
    | 2026-09-16, with the symptom two steps away: an HTTPS page rendered a form
    | posting to `http://`, and the browser blocked the submission as insecure.
    | `TrustProxies` falls back to this config key, and a config file is the one
    | place `env()` is read at the right moment.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
