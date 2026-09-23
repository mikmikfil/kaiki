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

        /* The camera. One big button, the first thing under a thumb; the panel
           it opens stays open from one passenger to the next. */
        [hidden] { display: none !important; }

        .cam-open {
            display: flex; align-items: center; justify-content: center; gap: .6rem;
            width: 100%; min-height: 60px; margin-bottom: .9rem;
            font: inherit; font-size: 1.1rem; font-weight: 600;
            background: var(--sea); color: #fff;
            border: 0; border-radius: 14px;
            box-shadow: 0 6px 18px rgba(11, 61, 145, .22);
        }
        .cam-open:focus-visible, .cam-close:focus-visible { outline: 3px solid #F0B429; outline-offset: 2px; }
        .cam-open svg { width: 26px; height: 26px; flex: none; }

        .cam-note {
            font-size: .85rem; color: var(--soft);
            background: #fff; border: 1px solid var(--line); border-radius: 12px;
            padding: .7rem .9rem; margin: 0 0 .9rem;
        }
        .cam-note.bad { color: var(--bad); background: #FDECE9; border-color: #F6CFC7; }

        .cam-panel { margin-bottom: .9rem; }

        .cam-frame {
            position: relative; overflow: hidden;
            width: 100%; max-width: 26rem; margin: 0 auto;
            aspect-ratio: 1 / 1; max-height: 58vh;
            background: #06122B; border-radius: 16px;
            border: 3px solid transparent;
            transition: border-color .15s;
        }
        .cam-frame.hit { border-color: #2BB68A; }
        .cam-frame video {
            position: absolute; inset: 0;
            width: 100%; height: 100%; object-fit: cover;
        }
        /* Where to hold the ticket. Drawn, not decoded: the whole frame is read. */
        .cam-aim {
            position: absolute; inset: 18%;
            border: 3px solid rgba(255, 255, 255, .85); border-radius: 18px;
            box-shadow: 0 0 0 999px rgba(6, 18, 43, .28);
            pointer-events: none;
        }

        .cam-hint {
            text-align: center; font-size: .9rem; color: var(--soft);
            margin: .6rem 0;
        }

        .cam-close {
            display: block; width: 100%; max-width: 26rem; margin: 0 auto;
            min-height: 48px;
            font: inherit; font-size: 1rem; font-weight: 600;
            background: #fff; color: var(--sea);
            border: 2px solid var(--sea); border-radius: 12px;
        }

        .or-type { font-size: .8rem; color: var(--soft); margin: 0 0 .35rem; }

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

        /* One per name, for boarding without a ticket in hand — and the only
           control on the page for an operator without QR. 44px tall at least,
           the smallest thing a thumb hits reliably. */
        .board {
            flex: none;
            font-size: .9rem; font-weight: 600;
            min-height: 44px; padding: .5rem .95rem;
            background: var(--sea); color: #fff;
            border: 0; border-radius: 10px;
        }

        .tick { font-size: .78rem; font-weight: 600; white-space: nowrap; }
        .tick.yes { color: var(--ok); }
        .tick.no { color: var(--soft); }

        .meta { font-size: .75rem; color: var(--soft); margin: 1.2rem 0 0; }
        /* Never underlined (Mike's standing rule), not even on hover. */
        .meta a { color: var(--sea); font-weight: 600; text-decoration: none; }
    </style>
</head>
<body>
<header>
    <h1>{{ __('boarding.title') }}</h1>
    <span id="net" class="online">{{ __('boarding.online') }}</span>
</header>

<main>
    {{-- No scan box for an operator without QR boarding: their tickets carry
         no code to scan. The list below is the whole page. --}}
    @if ($qrEnabled)
    {{-- The camera. Hidden until the script below knows this phone can open
         one; on plain http, or with no build, the text box is the page. --}}
    <section id="camera" data-auto="{{ $autoCamera ? '1' : '0' }}">
        <button type="button" class="cam-open" id="camOpen" hidden>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/>
                <rect x="7" y="7" width="4" height="4" rx=".5"/><rect x="13" y="7" width="4" height="4" rx=".5"/><rect x="7" y="13" width="4" height="4" rx=".5"/><path d="M14 14h3v3M14 17h.01"/>
            </svg>
            <span>{{ __('boarding.camera.open') }}</span>
        </button>

        <p class="cam-note" id="camNote" role="status" hidden></p>

        <div class="cam-panel" id="camPanel" hidden>
            <div class="cam-frame" id="camFrame">
                <video id="camVideo" playsinline muted autoplay></video>
                <span class="cam-aim" aria-hidden="true"></span>
            </div>
            <p class="cam-hint" id="camHint">{{ __('boarding.camera.hint') }}</p>
            <button type="button" class="cam-close" id="camClose">{{ __('boarding.camera.close') }}</button>
        </div>
    </section>

    <p class="or-type" id="orType" hidden>{{ __('boarding.camera.or_type') }}</p>
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
    @endif

    <div id="result" role="status" aria-live="polite"></div>

    <p class="queue" id="queue"></p>

    <ul class="people" id="people"></ul>

    <p class="meta">
        {{ __('boarding.loaded_at', ['time' => \Illuminate\Support\Carbon::parse($generatedAt)->timezone(config('app.timezone'))->format('H:i')]) }}
        &middot;
        <a href="{{ route('filament.app.pages.check-in') }}">{{ __('boarding.full_page') }}</a>
    </p>
</main>

{{-- The camera and its QR decoder, built by Vite and served from this
     origin — never a CDN, because the service worker precaches it for a quay
     with no signal. A module, so it runs after the script below. --}}
@if ($qrEnabled && $scannerUrl)
<script type="module" id="scannerScript" src="{{ $scannerUrl }}"></script>
@endif

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

    // Whether this operator scans at all. Off, there is no scan box and a
    // `?ticket=` from an old QR is ignored rather than acted on.
    var QR_ENABLED = @json($qrEnabled);

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
        waiting: @json(__('boarding.waiting')),
        board: @json(__('boarding.board'))
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
            if (row.answers) {
                var answers = document.createElement('span');
                answers.textContent = row.answers;
                who.appendChild(answers);
            }

            li.appendChild(who);

            if (row.checked_in) {
                var tick = document.createElement('span');
                tick.className = 'tick yes';
                tick.textContent = T.aboard;
                li.appendChild(tick);
            } else {
                // A button, not a tappable row: a thumb scrolling a list of
                // forty names must not board somebody by brushing past them.
                // It goes through `scan()`, so a tap is queued offline and
                // reconciled by the server exactly as a scan is.
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'board';
                btn.textContent = T.board;
                btn.addEventListener('click', function () { scan(row.ticket_code); });
                li.appendChild(btn);
            }

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

        var kind;

        if (!known) {
            kind = 'bad';
            say('bad', T.unknown, code);
        } else if (known.checked_in) {
            kind = 'already';
            say('already', T.already, known.name);
        } else {
            kind = 'ok';
            known.checked_in = true;
            render();
            say('ok', navigator.onLine ? T.checkedIn : T.queued, known.name);
        }

        enqueue(code).then(showQueue).then(sync);

        return kind;
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

                    // Refused — usually a tap before the window opens. The
                    // optimistic tick is withdrawn above, and the reason is the
                    // server's own Greek sentence (CNV-11), so crew know what
                    // to do next rather than watching a tick vanish.
                    if (res.status === 'refused') { say('bad', res.message || T.unknown, res.guest || ''); }
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

    if (codeInput) {
        document.getElementById('scanForm').addEventListener('submit', function (e) {
            e.preventDefault();
            scan(codeInput.value);
            codeInput.value = '';
            codeInput.focus();
        });
    }

    window.addEventListener('online', net);
    window.addEventListener('offline', net);

    // A phone that regained signal in a pocket, with the page still open.
    setInterval(sync, 20000);

    // A QR scanned with the camera lands here with the code in the query.
    var fromUrl = new URLSearchParams(location.search).get('ticket');
    if (QR_ENABLED && fromUrl) { scan(fromUrl); }

    render();
    showQueue();
    net();

    var cameraReady = QR_ENABLED && setUpCamera();

    // No focus in the text box when there is a camera button: a keyboard
    // sliding up over it is the last thing somebody holding a ticket needs.
    if (!cameraReady && codeInput) { codeInput.focus(); }

    /* ---------------------------------------------------------------
       The camera.

       `boarding-scanner.js` opens it and reads QR codes; everything a
       crew member sees is here. A read goes through `scan()`, exactly as
       a typed code does, so the queue, the tick and the server's answer
       are the same whichever way a ticket arrived.

       Returns whether the camera button is on offer.
       --------------------------------------------------------------- */

    function setUpCamera() {
        var section = document.getElementById('camera');
        if (!section) { return false; }

        var openBtn = document.getElementById('camOpen');
        var closeBtn = document.getElementById('camClose');
        var note = document.getElementById('camNote');
        var panel = document.getElementById('camPanel');
        var frame = document.getElementById('camFrame');
        var video = document.getElementById('camVideo');
        var hint = document.getElementById('camHint');
        var orType = document.getElementById('orType');
        var resultHome = resultBox.nextSibling;

        var C = {
            hint: @json(__('boarding.camera.hint')),
            starting: @json(__('boarding.camera.starting')),
            notTicket: @json(__('boarding.camera.not_ticket')),
            insecure: @json(__('boarding.camera.insecure')),
            unsupported: @json(__('boarding.camera.unsupported')),
            denied: @json(__('boarding.camera.denied')),
            nocamera: @json(__('boarding.camera.nocamera')),
            busy: @json(__('boarding.camera.busy')),
            failed: @json(__('boarding.camera.failed'))
        };

        // One ticket held in front of the lens is read ten times a second.
        // The same code is ignored until it has been out of sight this long.
        var REPEAT_MS = 2500;

        var stop = null;
        var opening = false;
        var resumeOnShow = false;
        var last = { text: '', at: 0 };

        function showNote(kind, text) {
            note.textContent = text;
            note.className = 'cam-note' + (kind === 'bad' ? ' bad' : '');
            note.hidden = false;
        }

        function hideNote() { note.hidden = true; note.textContent = ''; }

        // Asked before the script has even loaded: on plain http from a
        // network address there is no camera to be had, whatever loads.
        var secure = window.isSecureContext !== false;
        var media = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);

        if (!secure) { showNote('info', C.insecure); return false; }
        if (!media) { showNote('info', C.unsupported); return false; }

        // No build (`npm run build` never ran) means no script tag: the text
        // box is the page, and saying why would only puzzle the crew.
        if (!document.getElementById('scannerScript')) { return false; }

        openBtn.hidden = false;
        orType.hidden = false;

        function scanner() {
            if (window.KaikiScanner) { return Promise.resolve(window.KaikiScanner); }

            // A module script runs after this one, so a `?camera=1` can get
            // here first. Waits for it, briefly.
            return new Promise(function (resolve, reject) {
                var timer = setTimeout(function () { reject(Object.assign(new Error('failed'), { reason: 'failed' })); }, 8000);
                window.addEventListener('kaiki-scanner-ready', function () {
                    clearTimeout(timer);
                    resolve(window.KaikiScanner);
                }, { once: true });
            });
        }

        function onRead(text) {
            var now = Date.now();
            var repeat = text === last.text && now - last.at < REPEAT_MS;
            last = { text: text, at: now };
            if (repeat) { return; }

            var code = window.KaikiScanner.ticketCodeFrom(text);
            var kind;

            if (code === null) {
                kind = 'bad';
                say('bad', C.notTicket, '');
            } else {
                kind = scan(code);
            }

            // A flash of the frame, and a buzz where the phone can: the crew
            // are looking at the passenger, not at the screen.
            frame.classList.add('hit');
            setTimeout(function () { frame.classList.remove('hit'); }, 450);
            // Chrome refuses a buzz before the first tap on the page, which
            // is the camera opened by itself from the home page.
            var tapped = !navigator.userActivation || navigator.userActivation.hasBeenActive;
            if (navigator.vibrate && tapped) {
                try { navigator.vibrate(kind === 'ok' ? 90 : [70, 60, 70]); } catch (e) { /* not allowed */ }
            }
        }

        function openCamera(auto) {
            if (stop || opening) { return; }
            opening = true;
            hideNote();

            panel.hidden = false;
            openBtn.hidden = true;
            hint.textContent = C.starting;
            // The answer to a scan sits right under the picture, where the
            // eyes already are, for as long as the camera is open.
            frame.parentNode.insertBefore(resultBox, hint.nextSibling);

            scanner().then(function (s) {
                return s.open({ video: video, onRead: onRead });
            }).then(function (stopper) {
                opening = false;
                // Closed, or the page hidden, while the camera was starting.
                if (panel.hidden || document.hidden) { stopper(); return; }
                stop = stopper;
                hint.textContent = C.hint;
            }).catch(function (error) {
                opening = false;
                closeCamera();
                var reason = (error && error.reason) || 'failed';
                // Opened by itself from the home page: some browsers want a
                // tap first. The button, focused, is the honest fallback.
                if (!(auto && reason === 'denied')) {
                    showNote('bad', C[reason] || C.failed);
                }
                openBtn.focus();
            });
        }

        function closeCamera() {
            if (stop) { stop(); stop = null; }
            panel.hidden = true;
            openBtn.hidden = false;
            resultHome.parentNode.insertBefore(resultBox, resultHome);
        }

        openBtn.addEventListener('click', function () { openCamera(false); });
        closeBtn.addEventListener('click', function () { closeCamera(); openBtn.focus(); });

        // A camera left running in a pocket is a flat battery by lunchtime,
        // and iOS stops it anyway. Released when the page is hidden, and
        // picked up again when it comes back.
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) {
                if (stop) { stop(); stop = null; resumeOnShow = true; }
            } else if (resumeOnShow && !panel.hidden) {
                resumeOnShow = false;
                panel.hidden = true;
                openCamera(true);
            }
        });
        window.addEventListener('pagehide', function () { if (stop) { stop(); stop = null; } });

        // «Σάρωση εισιτηρίων» on the home page. Read from the query as well
        // as the server's flag, so the copy the service worker keeps (under
        // the bare path) still opens the camera.
        var auto = section.getAttribute('data-auto') === '1'
            || new URLSearchParams(location.search).get('camera') === '1';

        if (auto) { openCamera(true); }

        return true;
    }

    if ('serviceWorker' in navigator) {
        // Scoped to this page's own path, so nothing else in the panel is
        // ever cached. Named explicitly: the default, the worker's directory
        // `/app/boarding/`, does not include `/app/boarding` itself.
        navigator.serviceWorker.register(@json(route('filament.app.boarding.sw')), {
            scope: @json(\App\Http\Controllers\App\BoardingServiceWorkerController::scope())
        });
    }
})();
</script>
</body>
</html>
