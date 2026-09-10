/**
 * The only thing the widget stores anywhere (WGT-12, GDR-12).
 *
 * *"The widget MUST NOT set cookies or write to `localStorage` for tracking. It
 * MAY use `sessionStorage` for the in-progress draft booking uuid, cleared on
 * completion."*
 *
 * So: one key, in `sessionStorage`. It exists because a guest sent to a payment
 * gateway comes back to a fresh page load and the widget has to know which draft
 * they were in the middle of — WGT-20's confirmation state has nothing to poll
 * otherwise.
 *
 * ## The manage token is stored beside the uuid, and the alternative was worse
 *
 * WGT-12 names the uuid. Reading the booking back needs the **token** as well:
 * a publishable key alone may not read a booking (`docs/api.md` §2.1 footnote 1),
 * which is the rule that stops a stranger with a uuid learning a guest's name.
 *
 * The obvious alternative was to carry both back in the return URL as query
 * parameters, and issue 111 rejected it: a credential in a URL lands in the
 * operator's server logs, in their analytics, and in a `Referer` header to
 * whatever the page loads next. `sessionStorage` on the operator's origin is
 * read by the operator's own scripts and nobody else, dies with the tab, and is
 * cleared the moment the booking is confirmed — the same lifetime WGT-12 grants
 * the uuid, for the same purpose.
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

export interface RememberedDraft {
    readonly uuid: string;
    readonly token: string | null;
    /**
     * Where this draft was being paid for (ADR-0030).
     *
     * Stored so that a guest who wanders back to the operator's page mid-way is
     * offered the checkout they left rather than an empty form. It is a URL
     * containing the same token already beside it, so it adds no exposure — and
     * it dies with the tab, like everything else here.
     */
    readonly checkoutUrl: string | null;
}

export function rememberDraft(
    uuid: string,
    token: string | null,
    checkoutUrl: string | null = null,
    storage: Storage | null = safeStorage(),
): void {
    try {
        storage?.setItem(KEY, JSON.stringify({ uuid, token, checkoutUrl }));
    } catch {
        // Storage refused. The guest can still finish; only the return from the
        // gateway loses its memory, and WGT-20 falls back to the email promise.
    }
}

export function recallDraft(storage: Storage | null = safeStorage()): RememberedDraft | null {
    try {
        const raw = storage?.getItem(KEY) ?? null;

        if (raw === null) {
            return null;
        }

        const parsed: unknown = JSON.parse(raw);

        if (typeof parsed !== 'object' || parsed === null) {
            return null;
        }

        const uuid = (parsed as { uuid?: unknown }).uuid;
        const token = (parsed as { token?: unknown }).token;
        const checkoutUrl = (parsed as { checkoutUrl?: unknown }).checkoutUrl;

        return typeof uuid === 'string' && uuid !== ''
            ? {
                  uuid,
                  token: typeof token === 'string' && token !== '' ? token : null,
                  checkoutUrl: typeof checkoutUrl === 'string' && checkoutUrl !== '' ? checkoutUrl : null,
              }
            : null;
    } catch {
        // Unreadable, or something else wrote this key. Either way the guest
        // gets the email promise rather than an exception.
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
