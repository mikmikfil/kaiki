{{--
    The boarding page that survives a bad signal (OPS-12, BKG-20, OOS-6).

    Plain Blade, no Livewire. Everything the crew needs to recognise a ticket is
    in the bytes that loaded this page: a page that fetched its manifest would be
    a page that works only when it does not need to.

    The service worker below is scoped to this path alone. Caching the whole
    authenticated panel would be a phone holding another operator's screens after
    somebody signed out on it.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    {{-- `viewport-fit=cover` for the notch, and no `user-scalable=no`: somebody
         in the sun with a cracked screen has every right to zoom. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0B3D91">
    <title>{{ __('boarding.title') }}</title>
    <style>
        :root {
            --sea: #0B3D91;
            --ink: #101828;
            --soft: #47526B;
            --line: #E4E9F2;
            --ok: #0E7C5A;
            --warn: #8A6410;
            --bad: #A8321F;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #F4F6FA;
            color: var(--ink);
            /* The safe areas matter here more than anywhere: this is used one
               handed, on a phone, at the bottom of the screen. */
            padding: env(safe-area-inset-top) env(safe-area-inset-right) env(safe-area-inset-bottom) env(safe-area-inset-left);
        }

        header {
            position: sticky; top: 0; z-index: 2;
            background: var(--sea); color: #fff;
            padding: .8rem 1rem;
            display: flex; align-items: center; justify-content: space-between; gap: 1rem;
        }

        header h1 { font-size: 1rem; margin: 0; font-weight: 600; }

        /* The connection state is the single most important thing on this page.
           Crew have to know whether a tick means "the office knows" or "this
           phone knows". */
        #net {
            font-size: .72rem; font-weight: 600; letter-spacing: .04em;
            padding: .2rem .5rem; border-radius: 999px; white-space: nowrap;
        }
        #net.online { background: rgba(255,255,255,.16); }
        #net.offline { background: #F0B429; color: #3B2C00; }

        main { padding: 1rem; max-width: 40rem; margin: 0 auto; }

        .scan {
            display: flex; gap: .5rem; margin-bottom: 1rem;
        }

        .scan input {
            flex: 1; min-width: 0;
            font-size: 1.1rem; padding: .8rem;
            border: 2px solid var(--line); border-radius: 12px;
            font-family: ui-monospace, monospace;
        }

        .scan input:focus { outline: none; border-color: var(--sea); }

        .scan button {
            font-size: 1rem; font-weight: 600; padding: .8rem 1.1rem;
            background: var(--sea); color: #fff;
            border: 0; border-radius: 12px;
            /* 44px is the smallest thing a thumb hits reliably. */
            min-height: 48px; min-width: 88px;
        }

        #result {
            border-radius: 12px; padding: .9rem 1rem; margin-bottom: 1rem;
            font-size: 1rem; display: none;
        }
        #result.show { display: block; }
        #result.ok { background: #E4F5EE; color: var(--ok); }
        #result.already { background: #FFF6E0; color: var(--warn); }
        #result.bad { background: #FDECE9; color: var(--bad); }
        #result strong { display: block; font-size: 1.05rem; }

        .queue {
            font-size: .8rem; color: var(--soft);
            margin-bottom: 1rem; display: none;
        }
        .queue.show { display: block; }

        ul.people { list-style: none; margin: 0; padding: 0; }

        ul.people li {
            display: flex; align-items: center; justify-content: space-between; gap: .8rem;
            padding: .7rem .2rem; border-bottom: 1px solid var(--line);
        }

        .who { min-width: 0; }
        .who b { display: block; font-size: .95rem; }
        .who span { font-size: .78rem; color: var(--soft); }

        .tick { font-size: .78rem; font-weight: 600; white-space: nowrap; }
        .tick.yes { color: var(--ok); }
        .tick.no { color: var(--soft); }

        .meta { font-size: .75rem; color: var(--soft); margin: 1.2rem 0 0; }
        .meta a { color: var(--sea); }
    </style>
</head>
<body>
<header>
    <h1>{{ __('boarding.title') }}</h1>
    <span id="net" class="online">{{ __('boarding.online') }}</span>
</header>

<main>
    <form class="scan" id="scanForm" autocomplete="off">
        <input id="code"
               name="code"
               inputmode="text"
               autocapitalize="characters"
               autocomplete="off"
               spellcheck="false"
               placeholder="{{ __('boarding.placeholder') }}"
               aria-label="{{ __('boarding.placeholder') }}">
        <button type="submit">{{ __('boarding.scan') }}</button>
    </form>

    <div id="result" role="status" aria-live="polite"></div>

    <p class="queue" id="queue"></p>

    <ul class="people" id="people"></ul>

    <p class="meta">
        {{ __('boarding.loaded_at', ['time' => \Illuminate\Support\Carbon::parse($generatedAt)->timezone(config('app.timezone'))->format('H:i')]) }}
        &middot;
        <a href="{{ route('filament.app.pages.check-in') }}">{{ __('boarding.full_page') }}</a>
    </p>
</main>

<script>
(function () {
    'use strict';

    // The manifest that came with the page. Everything below can recognise a
    // ticket and show a name without a network.
    // Unescaped: a Greek name is half the bytes as UTF-8 rather than
    // `Μα...`, and this payload is downloaded on a phone with one
    // bar of signal.
    var manifest = @json($manifest, JSON_UNESCAPED_UNICODE);
    var byCode = {};
    manifest.forEach(function (row) { byCode[row.ticket_code] = row; });

    var DB_NAME = 'kaiki-boarding-{{ $tenantId }}';
    var STORE = 'queue';

    var codeInput = document.getElementById('code');
    var resultBox = document.getElementById('result');
    var queueLine = document.getElementById('queue');
    var netBadge = document.getElementById('net');
    var list = document.getElementById('people');

    var T = {
        online: @json(__('boarding.online')),
        offline: @json(__('boarding.offline')),
        checkedIn: @json(__('boarding.checked_in')),
        already: @json(__('boarding.already')),
        unknown: @json(__('boarding.unknown')),
        queued: @json(__('boarding.queued')),
        queueOne: @json(__('boarding.queue_one')),
        queueCount: @json(__('boarding.queue_count')),
        synced: @json(__('boarding.synced')),
        aboard: @json(__('boarding.aboard')),
        waiting: @json(__('boarding.waiting'))
    };

    /* ---------------------------------------------------------------
       The queue.

       IndexedDB rather than localStorage: a scan must survive the browser
       being killed while the phone is in a pocket, and localStorage is
       synchronous and capped in ways that bite exactly when a queue is long.
       --------------------------------------------------------------- */

    function open() {
        return new Promise(function (resolve, reject) {
            var req = indexedDB.open(DB_NAME, 1);
            req.onupgradeneeded = function () {
                req.result.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
            };
            req.onsuccess = function () { resolve(req.result); };
            req.onerror = function () { reject(req.error); };
        });
    }

    function withStore(mode, fn) {
        return open().then(function (db) {
            return new Promise(function (resolve, reject) {
                var tx = db.transaction(STORE, mode);
                var out = fn(tx.objectStore(STORE));
                tx.oncomplete = function () { resolve(out && out.result !== undefined ? out.result : out); };
                tx.onerror = function () { reject(tx.error); };
            });
        });
    }

    function enqueue(code) {
        return withStore('readwrite', function (store) {
            return store.add({ ticket_code: code, scanned_at: new Date().toISOString() });
        });
    }

    function allQueued() {
        return withStore('readonly', function (store) { return store.getAll(); });
    }

    function clearQueued(ids) {
        return withStore('readwrite', function (store) {
            ids.forEach(function (id) { store.delete(id); });
        });
    }

    /* --------------------------------------------------------------- */

    function say(kind, title, detail) {
        resultBox.className = 'show ' + kind;
        resultBox.innerHTML = '';
        var b = document.createElement('strong');
        b.textContent = title;
        resultBox.appendChild(b);
        if (detail) {
            var d = document.createElement('span');
            d.textContent = detail;
            resultBox.appendChild(d);
        }
    }

    function render() {
        list.innerHTML = '';
        manifest.forEach(function (row) {
            var li = document.createElement('li');

            var who = document.createElement('div');
            who.className = 'who';
            var name = document.createElement('b');
            name.textContent = row.name;
            var sub = document.createElement('span');
            sub.textContent = row.local_time + ' · ' + row.trip + ' · ' + row.reference;
            who.appendChild(name);
            who.appendChild(sub);

            var tick = document.createElement('span');
            tick.className = 'tick ' + (row.checked_in ? 'yes' : 'no');
            tick.textContent = row.checked_in ? T.aboard : T.waiting;

            li.appendChild(who);
            li.appendChild(tick);
            list.appendChild(li);
        });
    }

    function showQueue() {
        allQueued().then(function (rows) {
            if (!rows || rows.length === 0) {
                queueLine.className = 'queue';
                // Emptied, not merely hidden. A stale count behind
                // `display:none` is a lie waiting for the next CSS change.
                queueLine.textContent = '';
                return;
            }
            queueLine.className = 'queue show';
            queueLine.textContent = rows.length === 1
                ? T.queueOne
                : T.queueCount.replace(':count', rows.length);
        });
    }

    /* ---------------------------------------------------------------
       A scan.

       The phone answers immediately from the cached manifest, and the server
       replaces that answer when it can. The tick is optimistic; the truth is
       whatever `CheckInGuest` decides, because two crew on two phones can scan
       the same guest and only one of them boarded anybody.
       --------------------------------------------------------------- */

    function scan(code) {
        code = (code || '').trim();
        if (code === '') { return; }

        var known = byCode[code];

        if (!known) {
            say('bad', T.unknown, code);
        } else if (known.checked_in) {
            say('already', T.already, known.name);
        } else {
            known.checked_in = true;
            render();
            say('ok', navigator.onLine ? T.checkedIn : T.queued, known.name);
        }

        enqueue(code).then(showQueue).then(sync);
    }

    var syncing = false;

    function sync() {
        if (syncing || !navigator.onLine) { return Promise.resolve(); }
        syncing = true;

        return allQueued().then(function (rows) {
            if (!rows || rows.length === 0) { return null; }

            return fetch(@json(route('filament.app.boarding.scan')), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': @json(csrf_token())
                },
                body: JSON.stringify({ scans: rows })
            }).then(function (r) {
                if (!r.ok) { throw new Error('sync failed'); }
                return r.json();
            }).then(function (payload) {
                // The server decides. A queued scan may have lost a race with
                // another phone, and its answer is the one that counts.
                (payload.results || []).forEach(function (res) {
                    var row = byCode[res.ticket_code];
                    if (row) { row.checked_in = res.status === 'checked_in' || res.status === 'already'; }
                });

                render();
                return clearQueued(rows.map(function (r) { return r.id; }));
            });
        }).then(function () {
            syncing = false;
            showQueue();
        }).catch(function () {
            // Left in the queue on purpose. The next reconnect, the next scan
            // or the next poll tries again; nothing is dropped because a
            // request failed.
            syncing = false;
        });
    }

    function net() {
        var on = navigator.onLine;
        netBadge.className = on ? 'online' : 'offline';
        netBadge.textContent = on ? T.online : T.offline;
        if (on) { sync(); }
    }

    document.getElementById('scanForm').addEventListener('submit', function (e) {
        e.preventDefault();
        scan(codeInput.value);
        codeInput.value = '';
        codeInput.focus();
    });

    window.addEventListener('online', net);
    window.addEventListener('offline', net);

    // A phone that regained signal in a pocket, with the page still open.
    setInterval(sync, 20000);

    // A QR scanned with the camera lands here with the code in the query.
    var fromUrl = new URLSearchParams(location.search).get('ticket');
    if (fromUrl) { scan(fromUrl); }

    render();
    showQueue();
    net();
    codeInput.focus();

    if ('serviceWorker' in navigator) {
        // Scoped to this path by where it is served from, so nothing else in
        // the panel is ever cached.
        navigator.serviceWorker.register(@json(route('filament.app.boarding.sw')));
    }
})();
</script>
</body>
</html>
