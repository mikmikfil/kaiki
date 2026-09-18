{{--
    The shell every token page wears (spec TOK-5, BRD-1, TOK-3).

    Mobile-first and branded: a guest opens these on a phone, on a quay, on
    hotel wifi, and the page has to look like the operator they booked with
    rather than like this platform. The operator's colours, radius and font come
    through `css_variables` from `GetBrandPayload`, which is the same payload
    the widget uses — so a page and a widget on the same site cannot disagree
    about what the brand is.

    No analytics, no third-party script, no external image. TOK-3 sets
    `Referrer-Policy: no-referrer` so a token cannot leak through a `Referer`
    header, and the cheapest way to keep that true is to have almost nothing
    that makes a request. The one exception is the operator's own font
    stylesheet, which they chose.

    `<html lang>` is the resolved locale, not the browser's: TOK-5 takes it from
    the booking, and a screen reader reading Greek names in an English voice is
    the failure that gets noticed last.
--}}
@php
    /** @var array<string, mixed> $brand */
    $brand = $brand ?? [];
    $colors = $brand['css_variables'] ?? [];
    $tenantName = $brand['tenant']['name'] ?? config('app.name');
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Belt and braces with the header TOK-3 sets: a page saved to disk and
         reopened keeps the meta, and the header does not. --}}
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ $title ?? __('guest.common.title') }} — {{ $tenantName }}</title>

    @if (! empty($brand['font']['css_url']))
        <link rel="stylesheet" href="{{ $brand['font']['css_url'] }}">
    @endif

    {{-- The operator's own icon, else Kaiki's rather than none (2026-09-17). --}}
    <link rel="icon" href="{{ $brand['logo']['favicon_url'] ?? null ?: asset('favicon.svg') }}">


    <style>
        :root {
            @foreach ($colors as $name => $value)
                {{ $name }}: {{ $value }};
            @endforeach
        }

        * { box-sizing: border-box; }

        /* ---- direction B2, chosen 2026-09-18 ---------------------------

           One clean column on a white sheet, sections divided by a hairline
           rather than boxed into cards, and exactly two doses of the
           operator's colour: a four-pixel rule across the top and the tinted
           strip that carries the three times. The reasoning is the product
           owner's: the page has to look like the operator the guest booked
           with, and it has to stay readable on a quay on hotel wifi — a stack
           of bordered white cards on a grey ground did neither.

           Everything below is written against `.sheet` and its sections, so
           all five token pages — booking, checkout, guest details, voucher,
           quote — inherit it from this one file. */
        body {
            margin: 0;
            background: var(--kaiki-background, #f1f3f6);
            color: var(--kaiki-text, #14202b);
            font-family: var(--kaiki-font-family, system-ui), system-ui, -apple-system, sans-serif;
            font-size: 16px;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
        }

        /* The hairline everything is divided by. One value, so a section, the
           facts strip and the footer cannot drift apart by a percent of black. */
        :root { --hair: rgba(15, 32, 43, .10); }

        .wrap { max-width: 40rem; margin: 0 auto; padding: 1.25rem 1rem 4rem; }

        @media (max-width: 42rem) {
            /* On a phone the sheet is the page: no grey margin either side of
               a column that is already the width of the screen. */
            .wrap { padding: 0 0 3rem; }
        }

        .sheet {
            background: #fff;
            border: 1px solid var(--hair);
            border-top: 4px solid var(--kaiki-primary, #123a5e);
        }

        /* A section of the sheet. The last one keeps no rule, so the column
           does not end on a line that divides it from nothing. */
        .sheet > section { padding: 1.1rem 1.15rem; border-bottom: 1px solid var(--hair); }
        .sheet > section:last-of-type { border-bottom: 0; }

        /* The quiet end of the page: change of contact details, cancellation.
           Tinted rather than red — these are not warnings, they are the things
           nobody should press by accident. */
        .sheet > section.quiet { background: rgba(15, 32, 43, .025); }

        /* The small lower-case label above a section. Lower case on purpose:
           uppercase strips the accents off Greek, which the panel learned the
           hard way (2026-09-11). */
        .label {
            display: block; margin: 0 0 .5rem;
            font-size: .74rem; letter-spacing: .06em; font-weight: 600;
            color: rgba(15, 32, 43, .55);
        }

        .kicker { font-size: .82rem; color: rgba(15, 32, 43, .55); margin: 0 0 .15rem; }

        /* The three times, in the operator's colour at a tenth of its strength.
           This is the second and last dose of brand colour on the page. */
        .facts {
            display: grid; grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: .5rem 1rem;
            padding: .9rem 1.15rem;
            background: color-mix(in srgb, var(--kaiki-primary, #123a5e) 7%, #fff);
            border-bottom: 1px solid var(--hair);
        }

        .facts.four { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .facts > div > span { display: block; font-size: .74rem; color: rgba(15, 32, 43, .6); }
        .facts > div > b {
            font-size: 1.15rem; font-weight: 700; font-variant-numeric: tabular-nums;
            color: var(--kaiki-primary, #123a5e);
        }

        @media (max-width: 26rem) { .facts.four { grid-template-columns: repeat(2, minmax(0, 1fr)); } }

        /* The meeting point, drawn rather than linked (2026-09-18). Loaded with
           `no-referrer` so the token in this page's own address never reaches
           the map provider — the same reason the link beside it carries `rel`. */
        .map-embed { border: 1px solid var(--hair); line-height: 0; }
        .map-embed iframe { width: 100%; height: 190px; border: 0; }

        /* Checkout is the one guest page with two things to show at once — what
           you are paying for, and the form that pays for it — so it gets a
           wider measure than the pages that are a single column of facts. */
        .wrap.wide { max-width: 62rem; }

        .checkout-grid { display: grid; gap: 1.25rem; }

        /* ---- the order a phone reads this in ---------------------------

           The summary is first in the markup so a phone sees what is being paid
           for before the fields. True, and it took the pay button with it: the
           price card carries «Πληρωμή 110,00 €», so a guest met the button, then
           scrolled past name, email, telephone, a panel per passenger and the
           consent box, and had to scroll back **up** to press it.

           `display: contents` on the aside dissolves its box so its two cards
           become items of this grid in their own right, and each can be ordered
           separately. What a phone reads now is: which trip and when, then the
           form, then the price and the button — the button last, where it is
           the next thing to do rather than the first thing in the way.

           Desktop is untouched: the media query below restores the aside as a
           real sticky column. */
        @media (max-width: 55.999rem) {
            .checkout-side { display: contents; }

            .checkout-side > .card:first-child { order: 1; }
            .checkout-main { order: 2; }
            .checkout-side > .card:last-child { order: 3; }
        }

        /* The cancellation sentence, under the price and above the button. Not
           a card of its own — it is part of what the button commits to. */
        .policy { margin-block-start: 1rem; }

        .policy h3 {
            margin: 0 0 .25rem;
            font-size: .82rem;
            font-weight: 700;
            letter-spacing: .04em;
        }

        .policy p { margin: 0; }

        /* «Πολιτική ακύρωσης»: the whole ladder in a window over the page,
           opened by a link to its id (`:target`), closed by a link away. */
        .policy-link { font-weight: 600; text-decoration: none; }

        .policy-window {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 50;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            background: rgba(15, 23, 42, .45);
        }

        .policy-window:target { display: flex; }

        .policy-window-box {
            width: 100%;
            max-width: 32rem;
            max-height: 85vh;
            overflow-y: auto;
            padding: 1.25rem 1.4rem;
            border-radius: .75rem;
            background: #fff;
            box-shadow: 0 20px 40px -20px rgba(15, 23, 42, .5);
        }

        .policy-window-box h2 { margin: 0 0 .75rem; font-size: 1.1rem; }

        .policy-lines { margin: 0 0 1rem; padding-inline-start: 1.1rem; display: grid; gap: .45rem; }

        .policy-close { font-weight: 600; text-decoration: none; }

        /* The consent link was the one underlined link left on a guest page —
           the rule of 2026-09-11 is that nothing here is underlined, at rest or
           on hover — and against the sentence around it that read as damage
           rather than as a link. The operator's colour and the weight say it
           instead, which is what every other link on these pages does. */
        .consent a { color: var(--kaiki-primary, #0b3d91); font-weight: 600; }

        @media (min-width: 56rem) {
            .checkout-grid {
                grid-template-columns: minmax(0, 1fr) 23rem;
                gap: 1.5rem;
                align-items: start;
            }

            /* The summary is first in the markup so a phone shows what is being
               paid for before the fields, and `order` puts it on the right on a
               wide screen without a second copy of the markup. */
            .checkout-main { order: 1; }
            .checkout-side { order: 2; position: sticky; top: 1rem; }
        }

        header.brand {
            display: flex; align-items: center; gap: .6rem;
            padding: .85rem 1.15rem; border-bottom: 1px solid var(--hair);
        }
        header.brand img { max-height: 34px; width: auto; }
        header.brand .name { font-weight: 700; font-size: .95rem; }

        /* `.card` keeps its name because four of the five pages are written in
           it — but it is no longer a card. It is a section of the sheet,
           divided by a hairline like every other. */
        .card {
            background: transparent;
            border: 0;
            border-bottom: 1px solid var(--hair);
            border-radius: 0;
            padding: 1.1rem 1.15rem;
            margin: 0;
        }

        .card:last-child { border-bottom: 0; }

        /* Inside the checkout's two columns a card is a box again: a sticky
           price panel beside a form needs an edge of its own to sit in. */
        .checkout-grid .card {
            border: 1px solid var(--hair);
            border-radius: var(--kaiki-radius, 8px);
            background: #fff;
        }

        h1 { font-size: 1.5rem; line-height: 1.18; margin: 0 0 .35rem; letter-spacing: -.01em; }
        h2 { font-size: 1.05rem; margin: 0 0 .6rem; }

        .muted { color: rgba(0, 0, 0, .6); font-size: .92rem; }

        dl.rows { margin: 0; display: grid; grid-template-columns: 1fr auto; gap: .35rem .75rem; }
        dl.rows dt { color: rgba(0, 0, 0, .6); }
        dl.rows dd { margin: 0; text-align: right; font-variant-numeric: tabular-nums; }

        .total { font-weight: 700; border-top: 1px solid rgba(0, 0, 0, .12); padding-top: .5rem; margin-top: .35rem; }

        .btn {
            display: inline-block; width: 100%; text-align: center;
            padding: .8rem 1rem; border: 0; cursor: pointer;
            border-radius: var(--kaiki-radius, 8px);
            background: var(--kaiki-primary, #0b3d91); color: #fff;
            font: inherit; font-weight: 600; text-decoration: none;
        }

        /* No underlined links on a guest page, at rest or on hover — the
           product owner's rule of 2026-09-11. A link says so with the
           operator's colour and weight instead; the focus ring stays. */
        a, a:hover { text-decoration: none; }

        .btn.secondary { background: #fff; color: var(--kaiki-primary, #123a5e); border: 1px solid rgba(0, 0, 0, .18); }

        /* Cancellation is not a red button on a guest's own page. It is a thing
           they may legitimately have to do, spelled plainly, in a colour that
           does not shout across the rest of the column. */
        .btn.danger { background: transparent; color: #8a4b3c; border: 1px solid rgba(138, 75, 60, .35); }
        .btn + .btn { margin-top: .5rem; }

        /* Two or three buttons that belong together: side by side where there
           is room, stacked where there is not. */
        /* The two things at the quiet end of the booking page open in place
           rather than on another screen: a refund figure has to be read before
           the button under it is pressed, and a `<details>` is the only way to
           do that without asking a guest on hotel wifi to load a second page.
           They are drawn as the buttons the design calls for. */
        .quiet details { display: inline-block; margin: 0 .5rem .5rem 0; vertical-align: top; }

        .quiet details > summary {
            display: inline-block; cursor: pointer; list-style: none;
            padding: .8rem 1rem;
            border: 1px solid rgba(0, 0, 0, .18);
            border-radius: var(--kaiki-radius, 8px);
            background: #fff; color: var(--kaiki-primary, #123a5e);
            font-weight: 600; font-size: 1rem;
        }

        .quiet details > summary::-webkit-details-marker { display: none; }
        .quiet details > summary:focus-visible { outline: 2px solid var(--kaiki-primary, #123a5e); outline-offset: 2px; }
        .quiet details[open] { display: block; margin-right: 0; }
        .quiet details[open] > summary { margin-bottom: .6rem; }

        /* The voucher code: the one string on that page a guest will copy. */
        .voucher-code {
            margin: .2rem 0 .9rem;
            font-size: 1.9rem; font-weight: 700; letter-spacing: .08em;
            color: var(--kaiki-primary, #123a5e);
            word-break: break-all;
        }

        .btn-row { display: flex; flex-wrap: wrap; gap: .5rem; }
        .btn-row .btn { width: auto; margin-top: 0; }
        @media (max-width: 26rem) { .btn-row .btn { width: 100%; } }

        label { display: block; font-size: .9rem; font-weight: 600; margin: .75rem 0 .25rem; }

        input, select, textarea {
            width: 100%; padding: .65rem .7rem; font: inherit;
            border: 1px solid rgba(0, 0, 0, .25);
            border-radius: var(--kaiki-radius, 8px);
            background: #fff; color: inherit;
        }

        /* The consent row. `input { width: 100% }` above is right for text
           fields and wrong for a checkbox, which was stretching to the width of
           the card with its label orphaned underneath. */
        .consent { margin: 1rem 0 1.25rem; }

        .consent label {
            display: flex; align-items: flex-start; gap: .6rem;
            margin: 0; font-weight: 400; font-size: .92rem; line-height: 1.45;
            /* The sentence is short and the link inside it is five words long,
               so the greedy break left «ακύρωσης.» alone on its own line under
               a half-empty one. */
            text-wrap: pretty;
        }

        .consent input[type="checkbox"] {
            width: auto; flex: none; margin-block-start: .15rem;
        }

        .field-error { margin: .3rem 0 0; font-size: .85rem; color: #a8321f; }

        /* One folded panel per passenger, so a manifest of eight is a list the
           length of the party rather than twenty-four fields in a column. */
        details.passenger {
            margin: .5rem 0 0;
            border: 1px solid rgba(0, 0, 0, .15);
            border-radius: var(--kaiki-radius, 8px);
            background: #fff;
        }

        details.passenger > summary {
            cursor: pointer; list-style: none;
            padding: .7rem .9rem;
            font-size: .9rem; font-weight: 600;
            display: flex; align-items: baseline; gap: .5rem;
        }

        details.passenger > summary::-webkit-details-marker { display: none; }

        /* The chevron, and the only thing saying these open. */
        details.passenger > summary::after {
            content: '';
            inline-size: .45rem; block-size: .45rem; margin-inline-start: auto;
            border-right: 2px solid rgba(0, 0, 0, .45);
            border-bottom: 2px solid rgba(0, 0, 0, .45);
            rotate: 45deg; translate: 0 -2px;
        }

        details.passenger[open] > summary::after { rotate: 225deg; translate: 0 2px; }

        /* The name once it is typed, so a folded row still says who is in it. */
        details.passenger .passenger-name { font-weight: 400; color: rgba(0, 0, 0, .55); }

        /* Which band this person is — «Ενήλικας», «Παιδί», «Βρέφος». A pill
           rather than more grey text, because it is the one thing on the row
           that is not a name, and a parent scanning three folded panels is
           looking for it. */
        details.passenger .passenger-band {
            font-size: .72rem; font-weight: 600; letter-spacing: .02em;
            padding: .12rem .45rem; border-radius: 999px;
            color: var(--kaiki-primary, #123A5E);
            background: color-mix(in srgb, var(--kaiki-primary, #123A5E) 10%, transparent);
        }

        .passenger-body { padding: 0 .9rem 1rem; }
        /* «Κουπόνι» beside the price (2026-09-17). */
        .discount-form { margin-top: 1rem; }
        .discount-row { display: flex; gap: .5rem; }
        .discount-row input { flex: 1; text-transform: uppercase; }
        .btn-quiet { background: transparent; color: inherit; border: 1px solid rgba(0, 0, 0, .2); width: auto; }
        /* The operator's checkout questions (2026-09-17). */
        .trip-question .question-label { margin: .9rem 0 .35rem; font-weight: 600; }
        .trip-question .choice-row { display: flex; gap: 1.25rem; }
        .trip-question label.inline { display: inline-flex; align-items: center; gap: .4rem; margin: 0; font-weight: 400; }
        .trip-question label.inline input { width: auto; }
        .passenger-body label:first-of-type { margin-top: 0; }
        /* A passport has an expiry date; an identity card does not (2026-09-17). */
        .passenger-body:has(select option[value="id_card"]:checked) .passport-expiry { display: none; }

        /* The pay button sits in the summary column and submits the form in the
           other one, so it needs its own top margin rather than the form's. */
        .btn.pay { margin-top: 1.1rem; }

        /* Padlock, mark, sentence. Boxed and set apart from the price above it,
           because the job of this block is to look like the part of the page
           that handles money. */
        .pay-secure {
            display: flex; align-items: flex-start; gap: .7rem;
            margin-top: .9rem; padding: .75rem .85rem;
            border: 1px solid rgba(0, 0, 0, .12);
            border-radius: var(--kaiki-radius, 8px);
            background: rgba(0, 0, 0, .02);
        }

        .pay-secure > svg {
            inline-size: 1.35rem; block-size: 1.35rem; flex: none;
            margin-block-start: .1rem; color: var(--kaiki-primary, #123a5e);
        }

        .pay-brand { margin: 0; font-weight: 700; font-size: .95rem; letter-spacing: -.01em; }
        .pay-logo { display: block; height: 18px; width: auto; }
        .secure { margin-top: .25rem; font-size: .8rem; line-height: 1.4; }

        .notice-error { border-color: #a8321f; }

        .field-pair { display: grid; grid-template-columns: 1fr 1fr; gap: 0 .75rem; }
        @media (max-width: 30rem) { .field-pair { grid-template-columns: 1fr; } }

        .notice {
            border-left: 4px solid var(--kaiki-accent, #8a6410);
            background: rgba(0, 0, 0, .03);
            padding: .8rem .9rem; margin: 0 0 1rem;
            border-radius: 0 var(--kaiki-radius, 8px) var(--kaiki-radius, 8px) 0;
            font-size: .93rem;
        }

        .notice.bad { border-left-color: #a8321f; }

        /* The back-to-the-website link (2026-09-11). Never underlined, at rest
           or on hover — the product owner's rule — so the operator's colour and
           the weight say it is a link, and the focus ring says where the
           keyboard is. */
        .back-to-site { margin: 0 0 .9rem; font-size: .92rem; }
        .back-to-site a { color: var(--kaiki-primary, #0b3d91); font-weight: 600; text-decoration: none; }
        .back-to-site a:hover { text-decoration: none; opacity: .8; }
        .back-to-site a:focus-visible {
            outline: 2px solid var(--kaiki-primary, #0b3d91); outline-offset: 3px;
            border-radius: 2px; text-decoration: none;
        }

        footer.brand {
            padding: 1.1rem 1.15rem 1.3rem;
            font-size: .85rem; color: rgba(15, 32, 43, .55);
            display: grid; gap: .3rem;
        }

        /* The operator's telephone and email in the footer of every guest page
           (2026-09-18). A guest with a problem at a quay should not have to go
           back to the email to find out who to ring. */
        footer.brand .contact { display: flex; flex-wrap: wrap; gap: .3rem .9rem; }
        footer.brand a { color: var(--kaiki-primary, #123a5e); font-weight: 600; }
    </style>
</head>
<body>
<div class="wrap @if ($wide ?? false) wide @endif">
    <div class="sheet">
        <header class="brand">
            @if (! empty($brand['logo']['light_url']))
                <img src="{{ $brand['logo']['light_url'] }}" alt="{{ $tenantName }}">
            @else
                <span class="name">{{ $tenantName }}</span>
            @endif
        </header>

        @yield('content')

        @php
            $supportPhone = $brand['tenant']['support_phone'] ?? null;
            $supportEmail = $brand['tenant']['support_email'] ?? null;
        @endphp

        <footer class="brand">
            @if ($supportPhone || $supportEmail)
                <div class="contact">
                    @if ($supportPhone)
                        <a href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $supportPhone) }}">{{ $supportPhone }}</a>
                    @endif
                    @if ($supportEmail)
                        <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>
                    @endif
                </div>
            @endif

            <div>{{ $brand['email_footer_text'] ?? $tenantName }}</div>
        </footer>
    </div>
</div>
</body>
</html>
