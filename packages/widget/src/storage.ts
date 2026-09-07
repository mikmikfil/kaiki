/**
 * The only thing the widget stores anywhere (WGT-12, GDR-12).
 *
 * *"The widget MUST NOT set cookies or write to `localStorage` for tracking. It
 * MAY use `sessionStorage` for the in-progress draft booking uuid, cleared on
 * completion."*
 *
 * So: one key, in `sessionStorage`, holding one uuid. It exists because a guest
 * sent to a payment gateway comes back to a fresh page load and the widget has
 * to know which draft they were in the middle of — WGT-20's confirmation state
 * has nothing to poll otherwise.
 *
 * `sessionStorage` rather than `localStorage` is the whole point: it dies with
 * the tab, so an abandoned booking on a shared machine in a hotel lobby does not
 * greet the next person.
 *
 * Every access is wrapped, because `sessionStorage` **throws** rather than
 * returning null in a Safari private window and inside a sandboxed iframe — both
 * of which are ordinary places for a widget on somebody else's page to run. A
 * widget that crashed there would be a widget that works in testing and not on a
 * page builder.
 */
const KEY = 'kaiki:draft';

export function rememberDraft(uuid: string, storage: Storage | null = safeStorage()): void {
    try {
        storage?.setItem(KEY, uuid);
    } catch {
        // Storage refused. The guest can still finish; only the return from the
        // gateway loses its memory, and WGT-20 falls back to the email promise.
    }
}

export function recallDraft(storage: Storage | null = safeStorage()): string | null {
    try {
        return storage?.getItem(KEY) ?? null;
    } catch {
        return null;
    }
}

export function forgetDraft(storage: Storage | null = safeStorage()): void {
    try {
        storage?.removeItem(KEY);
    } catch {
        // Nothing to do, and nothing worth telling a guest about.
    }
}

function safeStorage(): Storage | null {
    try {
        return globalThis.sessionStorage ?? null;
    } catch {
        return null;
    }
}
