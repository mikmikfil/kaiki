{{--
    The panel as an app on a phone (PWA, 2026-09-23): the manifest, the
    colours and icons a phone asks for, the service worker, and what the
    «Εγκατάσταση εφαρμογής» item in the user menu needs.

    `/app` only, sign-in screens included, so the app can be installed before
    anybody signs in. See `App\Support\PanelApp` for the scope and the colours.

    ## The status bar

    The colour of whatever is under it: the navy band on the sign-in screens,
    Filament's white top bar everywhere else (its dark grey in dark mode). The
    manifest's own colour, navy, is the splash screen's and the task switcher's.

    On an iPhone the bar is `default` — white, dark text — and the page starts
    below it. `black-translucent` would lay the page under the clock and the
    notch, and every screen of the panel would need the safe-area padding that
    only the boarding page has; the top bar is white already, so there is
    nothing to gain.
--}}
<link rel="manifest" href="{{ $manifestUrl }}" crossorigin="use-credentials">
<meta name="theme-color" content="{{ $themeColor }}" data-kaiki-theme-color="{{ $themeColor }}">
<link rel="apple-touch-icon" href="{{ $appleIcon }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ $appName }}">
<meta name="apple-mobile-web-app-status-bar-style" content="default">

<script>
    (function () {
        /* --- «Εγκατάσταση εφαρμογής» ------------------------------------------
           Chrome (Android, desktop) offers the install through
           `beforeinstallprompt`, which fires once, early, and may come before
           Alpine exists — so it is caught here and kept. An iPhone has no such
           event; there the item opens the two steps instead. Neither is
           offered once the panel is already running as the app. */
        var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
        var ios = /iphone|ipad|ipod/i.test(navigator.userAgent)
            || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

        var install = window.kaikiInstall = {
            prompt: null,
            installed: false,
            available: function () {
                return ! standalone && ! install.installed && (install.prompt !== null || ios);
            },
            changed: function () { window.dispatchEvent(new CustomEvent('kaiki-install')); },
        };

        window.addEventListener('beforeinstallprompt', function (event) {
            event.preventDefault();
            install.prompt = event;
            install.changed();
        });

        window.addEventListener('appinstalled', function () {
            install.prompt = null;
            install.installed = true;
            install.changed();
        });

        document.addEventListener('alpine:init', function () {
            window.Alpine.data('kaikiInstall', function () {
                return {
                    available: install.available(),
                    init: function () {
                        var self = this;
                        window.addEventListener('kaiki-install', function () { self.available = install.available(); });
                    },
                    install: function () {
                        if (install.prompt) {
                            var prompt = install.prompt;
                            install.prompt = null;
                            prompt.prompt();
                            prompt.userChoice.then(function () { install.changed(); }).catch(function () { install.changed(); });
                            return;
                        }
                        this.$dispatch('open-modal', { id: 'kaiki-install-ios' });
                    },
                };
            });
        });

        /* --- The status bar in dark mode ------------------------------------- */
        var meta = document.querySelector('meta[data-kaiki-theme-color]');
        var paint = function () {
            var light = meta.getAttribute('data-kaiki-theme-color');
            meta.setAttribute('content', light === @json($topbar) && document.documentElement.classList.contains('dark') ? @json($topbarDark) : light);
        };
        paint();
        window.addEventListener('theme-changed', function () { setTimeout(paint, 0); });

        /* --- The service worker ----------------------------------------------
           Only where the browser allows one (https, or localhost). Once it is
           running it is told the page's language, so the offline page it keeps
           is in the one the operator reads. */
        if (! ('serviceWorker' in navigator) || ! window.isSecureContext) {
            return;
        }

        window.addEventListener('load', function () {
            navigator.serviceWorker.register(@json($workerUrl), { scope: @json($scope) })
                .then(function () { return navigator.serviceWorker.ready; })
                .then(function (registration) {
                    if (registration.active) {
                        registration.active.postMessage({ type: 'kaiki-locale', lang: document.documentElement.lang });
                    }
                })
                .catch(function () { /* No worker is a panel that works online, as before. */ });
        });
    })();
</script>
