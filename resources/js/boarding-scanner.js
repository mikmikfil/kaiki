/**
 * The camera on the boarding page: read ticket after ticket without leaving it.
 *
 * Loaded by `resources/views/app/boarding.blade.php` and nowhere else. The page
 * owns everything a crew member sees — the panel, the words, the result, the
 * queue — and this file owns only the camera and the decoding, behind one
 * small object on `window`, so the page's own script stays plain and readable.
 *
 * ## Why a decoder is bundled
 *
 * `BarcodeDetector` is on Android Chrome and not on iPhone Safari, and the
 * crew carry both. It is used where it exists — it is native and fast — and
 * jsQR does the work everywhere else. jsQR is bundled into this file by Vite,
 * not fetched from a CDN: the page must scan with no signal, so the service
 * worker precaches this file, and a script on somebody else's server would be
 * the one piece that is missing on the quay.
 *
 * ## What it does not do
 *
 * It never checks anybody in. A code goes to the page's `onCode`, which calls
 * the same `scan()` a typed code does, so the queue, the optimistic tick and
 * the server's final word are all exactly what they are for a typed code.
 */
import jsQR from 'jsqr';
import { ticketCodeFrom } from './boarding/ticket-code.js';

/** How often a frame is decoded. Fast enough to feel instant, slow enough to spare a battery. */
const INTERVAL_MS = 150;

/** jsQR is given at most this many pixels on the longer side; a QR held at arm's length needs no more. */
const MAX_SIDE = 720;

/**
 * Whether this browser can open a camera at all, and if not, why.
 *
 * @returns {'ok'|'insecure'|'unsupported'}
 */
function availability() {
    // `getUserMedia` exists only on HTTPS and localhost. On a LAN address over
    // plain HTTP `navigator.mediaDevices` is simply undefined, so this is the
    // question to ask first — the answer to "why" is different.
    if (window.isSecureContext === false) {
        return 'insecure';
    }

    if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
        return 'unsupported';
    }

    return 'ok';
}

/** A native detector, or null to fall back on jsQR. */
async function nativeDetector() {
    if (typeof window.BarcodeDetector !== 'function') {
        return null;
    }

    try {
        const formats = await window.BarcodeDetector.getSupportedFormats();

        return formats.includes('qr_code') ? new window.BarcodeDetector({ formats: ['qr_code'] }) : null;
    } catch {
        return null;
    }
}

/**
 * The reason a camera would not open, in the page's vocabulary.
 *
 * @param {unknown} error
 * @returns {'denied'|'nocamera'|'busy'|'failed'}
 */
function reasonFor(error) {
    const name = error && typeof error === 'object' && 'name' in error ? String(error.name) : '';

    if (name === 'NotAllowedError' || name === 'SecurityError' || name === 'PermissionDeniedError') {
        return 'denied';
    }

    if (name === 'NotFoundError' || name === 'OverconstrainedError' || name === 'DevicesNotFoundError') {
        return 'nocamera';
    }

    if (name === 'NotReadableError' || name === 'TrackStartError' || name === 'AbortError') {
        return 'busy';
    }

    return 'failed';
}

/**
 * Open the rear camera into `video` and report every QR it reads.
 *
 * Resolves to a `stop()` that releases the camera, or rejects with an Error
 * whose `reason` is one of `insecure`, `unsupported`, `denied`, `nocamera`,
 * `busy` or `failed`.
 *
 * @param {{ video: HTMLVideoElement, onRead: (text: string) => void }} options
 * @returns {Promise<() => void>}
 */
async function open({ video, onRead }) {
    const state = availability();

    if (state !== 'ok') {
        throw Object.assign(new Error(state), { reason: state });
    }

    let stream;

    try {
        stream = await navigator.mediaDevices.getUserMedia({
            audio: false,
            // The rear camera. `ideal` rather than `exact`: a laptop has only
            // the front one, and scanning with it beats refusing to.
            video: {
                facingMode: { ideal: 'environment' },
                width: { ideal: 1280 },
                height: { ideal: 720 },
            },
        });
    } catch (error) {
        const reason = reasonFor(error);

        throw Object.assign(new Error(reason), { reason });
    }

    // iPhone Safari plays a camera inline only with these set, and only muted.
    video.setAttribute('playsinline', '');
    video.setAttribute('muted', '');
    video.muted = true;
    video.srcObject = stream;

    try {
        await video.play();
    } catch {
        // Autoplay of a muted inline stream is allowed everywhere that matters;
        // if it is not, the frames still arrive and decoding still works.
    }

    const detector = await nativeDetector();
    const canvas = document.createElement('canvas');
    const context = canvas.getContext('2d', { willReadFrequently: true });

    let stopped = false;
    let timer = 0;

    async function decode() {
        if (video.readyState < 2 || video.videoWidth === 0) {
            return null;
        }

        if (detector) {
            try {
                const found = await detector.detect(video);

                return found.length > 0 ? found[0].rawValue : null;
            } catch {
                // A detector that throws once throws for ever; jsQR below.
            }
        }

        const scale = Math.min(1, MAX_SIDE / Math.max(video.videoWidth, video.videoHeight));
        const width = Math.round(video.videoWidth * scale);
        const height = Math.round(video.videoHeight * scale);

        canvas.width = width;
        canvas.height = height;
        context.drawImage(video, 0, 0, width, height);

        const found = jsQR(context.getImageData(0, 0, width, height).data, width, height, {
            inversionAttempts: 'dontInvert',
        });

        return found ? found.data : null;
    }

    async function tick() {
        if (stopped) {
            return;
        }

        try {
            const text = await decode();

            if (text && !stopped) {
                onRead(text);
            }
        } finally {
            if (!stopped) {
                timer = window.setTimeout(tick, INTERVAL_MS);
            }
        }
    }

    tick();

    return function stop() {
        stopped = true;
        window.clearTimeout(timer);
        stream.getTracks().forEach((track) => track.stop());
        video.srcObject = null;
    };
}

window.KaikiScanner = { availability, open, ticketCodeFrom };

// The page may have been waiting for this file (a `?camera=1` that arrived
// before the module did).
window.dispatchEvent(new Event('kaiki-scanner-ready'));
