/**
 * What a scanned QR says, as a ticket code — or null when it is not a ticket.
 *
 * A Kaiki ticket's QR is a URL, `APP_URL/app/boarding?ticket=CODE`, so that a
 * phone's own camera opens the boarding page (see `TicketQr::payloadFor`). The
 * in-page scanner reads the same QR and wants only the code. A bare code is
 * accepted as well: that is what somebody types, and what an older or
 * hand-made QR might carry.
 *
 * Any host is accepted. A ticket printed while the site answered on another
 * address is still that passenger's ticket; the host proves nothing, and the
 * server checks the code against this operator's guests anyway.
 *
 * A URL with no `ticket` — a website, a Wi-Fi sticker on the quay — is not a
 * ticket, and returns null so it never reaches the queue.
 *
 * @param {unknown} raw
 * @returns {string|null}
 */
export function ticketCodeFrom(raw) {
    const text = String(raw ?? '').trim();

    if (text === '') {
        return null;
    }

    if (/^https?:\/\//i.test(text)) {
        let url;

        try {
            url = new URL(text);
        } catch {
            return null;
        }

        const code = (url.searchParams.get('ticket') ?? '').trim();

        return code === '' ? null : code;
    }

    // Something with spaces or line breaks is a sentence, not a code.
    if (/\s/.test(text)) {
        return null;
    }

    return text;
}
