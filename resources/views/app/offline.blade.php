{{--
    «Χωρίς σύνδεση» — the installed panel with no network (PWA, 2026-09-23).

    The one page the phone keeps, so it is about nobody and needs nothing: the
    styles and the mark are inline, and there is no request in it to fail. See
    `PanelOfflineController` and `PanelServiceWorkerController`.

    It reloads by itself when the phone finds a network. The way to boarding is
    shown only when this phone has the boarding page kept (its own worker is
    registered), because otherwise it would lead straight back here.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#FFFFFF">
    <meta name="robots" content="noindex">
    <title>{{ __('pwa.offline.title') }} - {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        html, body { margin: 0; min-height: 100%; }
        body {
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem 1.25rem 7rem;
            background: #F4F7FB;
            color: #0F172A;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .mark { inline-size: 4.5rem; block-size: 4.5rem; display: block; margin: 0 auto 1.5rem; }
        h1 { margin: 0; font-size: 1.5rem; font-weight: 700; letter-spacing: -.01em; }
        p { margin: .5rem auto 0; max-inline-size: 34ch; color: #475569; font-size: 1rem; line-height: 1.5; }
        .actions { display: flex; flex-direction: column; gap: .75rem; margin-top: 2rem; inline-size: min(100%, 20rem); }
        .btn {
            display: flex; align-items: center; justify-content: center; gap: .5rem;
            min-block-size: 3rem; padding: .75rem 1.25rem; border-radius: .75rem;
            font: inherit; font-weight: 600; font-size: 1rem;
            text-decoration: none; cursor: pointer; border: 1px solid transparent;
        }
        .btn:hover, .btn:focus { text-decoration: none; }
        .btn-primary { background: {{ $navy }}; color: #fff; }
        .btn-primary:hover { background: #17396A; }
        .btn-secondary { background: #fff; color: {{ $navy }}; border-color: #CBD5E1; }
        .btn-secondary:hover { border-color: {{ $navy }}; }
        .btn svg { inline-size: 1.25rem; block-size: 1.25rem; flex: none; }
        [hidden] { display: none !important; }
        .sea { position: absolute; inset-inline: 0; inset-block-end: 0; block-size: 6rem; pointer-events: none; }
    </style>
</head>
<body>
    <svg class="mark" viewBox="0 0 100 100" aria-hidden="true">
        <rect width="100" height="100" rx="22" fill="{{ $navy }}"/>
        <g transform="translate(10 10) scale(.8)">
            <path d="M38 20v44M38 46 61 20M45 39.5 63 64" fill="none" stroke="#fff" stroke-width="10.5" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M24 80.5q6.5-5 13 0t13 0 13 0 13 0" fill="none" stroke="#8FB0DA" stroke-width="4.5" stroke-linecap="round"/>
        </g>
    </svg>

    <h1>{{ __('pwa.offline.title') }}</h1>
    <p>{{ __('pwa.offline.body') }}</p>

    <div class="actions">
        <button type="button" class="btn btn-primary" id="retry">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
            {{ __('pwa.offline.retry') }}
        </button>
        <a class="btn btn-secondary" id="boarding" href="{{ $boardingUrl }}" hidden>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0 1 3.75 9.375v-4.5ZM3.75 14.625c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5a1.125 1.125 0 0 1-1.125-1.125v-4.5ZM13.5 4.875c0-.621.504-1.125 1.125-1.125h4.5c.621 0 1.125.504 1.125 1.125v4.5c0 .621-.504 1.125-1.125 1.125h-4.5A1.125 1.125 0 0 1 13.5 9.375v-4.5Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 6.75h.75v.75h-.75v-.75ZM6.75 16.5h.75v.75h-.75v-.75ZM16.5 6.75h.75v.75h-.75v-.75ZM13.5 13.5h.75v.75h-.75v-.75ZM13.5 19.5h.75v.75h-.75v-.75ZM19.5 13.5h.75v.75h-.75v-.75ZM19.5 19.5h.75v.75h-.75v-.75ZM16.5 16.5h.75v.75h-.75v-.75Z"/></svg>
            {{ __('pwa.offline.boarding') }}
        </a>
    </div>

    <svg class="sea" viewBox="0 0 400 96" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0 40q25-14 50 0t50 0 50 0 50 0 50 0 50 0 50 0 50 0V96H0Z" fill="#E3EBF5"/>
        <path d="M0 62q25-12 50 0t50 0 50 0 50 0 50 0 50 0 50 0 50 0V96H0Z" fill="#D5E1EF"/>
    </svg>

    <script>
        (function () {
            document.getElementById('retry').addEventListener('click', function () { location.reload(); });
            window.addEventListener('online', function () { location.reload(); });

            // Only when the boarding page is kept on this phone.
            var link = document.getElementById('boarding');
            if ('serviceWorker' in navigator && navigator.serviceWorker.getRegistrations) {
                navigator.serviceWorker.getRegistrations().then(function (all) {
                    var scope = new URL(@json($boardingScope), location.href).href;
                    if (all.some(function (r) { return r.scope === scope; })) {
                        link.hidden = false;
                    }
                });
            }
        })();
    </script>
</body>
</html>
