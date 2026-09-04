<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing — deliberately switched off here (SEC-7)
|--------------------------------------------------------------------------
|
| Laravel's default for this file is `'paths' => ['api/*']` with
| `'allowed_origins' => ['*']`. On this application that default is not a
| convenience, it is a hole: it tells **every** site on the internet that it may
| read our API responses, and the whole point of `api_keys.allowed_origins` is
| that an operator's publishable key belongs on their site and nowhere else.
|
| It is also silently overriding: `HandleCors` runs in the global stack, so its
| `*` lands on the response *after* per-key middleware has set the correct
| origin. The symptom is a header that looks permissive and is — the per-key
| allow-list appears to work in the panel and does nothing in the browser.
|
| So the framework's CORS handling covers **no paths at all**, and
| `App\Http\Middleware\ApiKeyCors` is the only thing in the codebase that emits
| these headers. One list per key, applied where the key is known.
|
| If a future path genuinely needs application-wide CORS — a public health
| probe, say — add that path here explicitly and say why. Never `api/*`.
|
*/

return [

    'paths' => [],

    'allowed_methods' => [],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
