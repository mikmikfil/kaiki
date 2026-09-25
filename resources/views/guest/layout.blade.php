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
    @else
        {{-- Inter, same-origin, the same files as the operator's site (2026-09-24). --}}
        <link rel="preload" href="/fonts/inter/inter-greek-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
        <link rel="preload" href="/fonts/inter/inter-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
    @endif

    {{-- The operator's own icon, else Kaiki's rather than none (2026-09-17). --}}
    <link rel="icon" href="{{ $brand['logo']['favicon_url'] ?? null ?: asset('favicon.svg') }}">


    <style>
        @include('partials.inter-font-face')

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
            /* The operator's face, then Inter (now actually loaded), then the
               system's. It was system-ui straight after the brand's family,
               so a brand that said «Inter» without installing it got Segoe UI. */
            font-family: var(--kaiki-font-family, Inter), Inter, "Helvetica Neue", Arial, sans-serif;
            font-size: 16px;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
        }

        /* The hairline everything is divided by. One value, so a section, the
           facts strip and the footer cannot drift apart by a percent of black. */
        :root { --hair: rgba(15, 32, 43, .10); }

        .wrap { max-width: 40rem; margin: 0 auto; padding: 2rem 1rem 5rem; }

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

        /* A section of the sheet, at whatever depth: on a wide screen the
           booking page puts its sections into two columns, and they are still
           sections. The last one in a column keeps no rule, so a column does
           not end on a line that divides it from nothing. */
        .sheet section { padding: 1.35rem 1.4rem; border-bottom: 1px solid var(--hair); }
        .sheet section:last-child { border-bottom: 0; }

        /* ---- the booking page on a wide screen (2026-09-18) --------------

           A phone reads one column in the order things are needed: what is
           owed, where to stand, what to take, what it cost, and the quiet end.
           A desktop has room to answer two questions at once, so the same
           sections become two columns — the trip on the left, the money and
           the actions on the right — rather than one 40rem ribbon down the
           middle of a 1400px screen.

           The order is kept honest at both widths with `display: contents` and
           `order`, the trick the checkout page already uses: one set of
           markup, read top to bottom on a phone and side by side on a desk. */
        .b-grid { display: flex; flex-direction: column; }
        .b-main, .b-side { display: contents; }

        .sec-balance { order: 1; }
        .sec-weather { order: 2; }
        .sec-meeting { order: 4; }
        .sec-take { order: 5; }
        .sec-price { order: 6; }
        .sec-quiet { order: 7; }

        .only-wide { display: none; }

        @media (min-width: 56rem) {
            .wrap.wide-booking { max-width: 58rem; }

            .b-grid {
                display: grid;
                grid-template-columns: minmax(0, 1.1fr) minmax(0, .9fr);
                align-items: start;
            }

            .b-main, .b-side { display: block; }
            .b-side { border-left: 1px solid var(--hair); }

            /* Both columns end flush with the footer's rule rather than each
               drawing its own last line at a different height. */
            .b-main > section:last-child, .b-side > section:last-child { border-bottom: 0; }

            .facts.wide-four { grid-template-columns: repeat(4, minmax(0, 1fr)); }
            .only-wide { display: block; }
        }

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

        /* The boarding codes (2026-09-18). One per passenger, big enough for a
           scanner to read off a screen at arm's length — 150px is about the
           floor for that, and a code that has to be pinch-zoomed at a gangway
           is a code the crew types in by hand instead. */
        .passes { display: flex; flex-wrap: wrap; gap: 1rem; }

        .pass {
            margin: 0;
            flex: 0 1 auto;
            text-align: center;
            padding: .75rem;
            border: 1px solid var(--hair);
            background: #fff;
        }

        .pass svg { width: 150px; height: 150px; display: block; }
        .pass figcaption { display: grid; gap: .1rem; margin-top: .45rem; font-size: .9rem; }
        .pass .code {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: .8rem; letter-spacing: .08em; color: rgba(15, 32, 43, .55);
        }

        .sec-passes { order: 3; }

        /* The meeting point, drawn rather than linked (2026-09-18). Loaded with
           `no-referrer` so the token in this page's own address never reaches
           the map provider — the same reason the link beside it carries `rel`. */
        .map-embed { border: 1px solid var(--hair); line-height: 0; }
        .map-embed iframe { width: 100%; height: 190px; border: 0; }

        /* Checkout is the one guest page with two things to show at once — what
           you are paying for, and the form that pays for it — so it gets a
           wider measure than the pages that are a single column of facts. */
        .wrap.wide { max-width: 66rem; }

        /* **Το περιθώριο ανήκει στο πλέγμα, όχι στις ενότητες** (2026-09-23).
           Κατεύθυνση Α: οι ενότητες έγιναν φύλλο και έχασαν το οριζόντιο
           padding τους — που ήταν όμως το **μόνο** περιθώριο της σελίδας, αφού
           το `.wrap` δεν έχει δικό του.
           Έτσι, ανάμεσα στα 896px και τα 1056px — όπου το `.wrap` πιάνει όλη
           την οθόνη χωρίς ακόμη να έχει φτάσει το `max-width` του — το ταμείο
           κολλούσε και στις δύο άκρες. Στα 390px φαινόταν σωστό, γιατί εκεί το
           padding της κάρτας υπήρχε ακόμη· το βρήκε ο Mike, όχι η μέτρησή μου.
           Ένα περιθώριο, στο πλέγμα, σε κάθε πλάτος. */
        .checkout-grid { display: grid; gap: 1.6rem; padding-inline: 1.4rem; }

        /* Και οι ενότητες δεν βάζουν δεύτερο από μέσα, σε κανένα πλάτος. */
        .checkout-grid .card { padding-inline: 0; }

        /* Η `.trip-photo` έφυγε μαζί με τη φωτογραφία στην κορυφή του ταμείου
           (Mike, 2026-09-23: ντεγκραντέ, όχι εικόνα). Καμία σελίδα του
           επισκέπτη δεν έγραφε πια αυτή την κλάση, και κανόνες που δεν τους
           καλεί κανείς είναι ακριβώς ό,τι ψάχναμε όλη μέρα να βγάλουμε. */

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
        .policy { margin-block-start: 1.4rem; }

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
                grid-template-columns: minmax(0, 1fr) 24rem;
                gap: 2.25rem;
                align-items: start;
            }

            /* The summary is first in the markup so a phone shows what is being
               paid for before the fields, and `order` puts it on the right on a
               wide screen without a second copy of the markup. */
            .checkout-main { order: 1; }
            .checkout-side { order: 2; position: sticky; top: 1rem; }

        }

        /* ---- the masthead (2026-09-22) ----------------------------------
           Shaped like the hosted site's: the mark or the logo, the name with
           the town under it, and on the right a telephone that dials and the
           two languages. Taller than the old strip on purpose — it is the
           first thing on the page and it should look like the operator. */
        header.brand {
            display: flex; align-items: center; justify-content: space-between;
            gap: 1rem; flex-wrap: wrap;
            padding: .95rem 1.15rem; border-bottom: 1px solid var(--hair);
        }
        header.brand .who { display: flex; align-items: center; gap: .7rem; min-width: 0; }
        header.brand img { max-height: 42px; width: auto; }
        header.brand .mark {
            width: 2.4rem; height: 2.4rem; flex: none; border-radius: 11px;
            display: grid; place-items: center; color: #fff; font-weight: 800; font-size: 1.1rem;
            background: var(--kaiki-primary, #123a5e);
        }
        header.brand .text { display: flex; flex-direction: column; line-height: 1.15; min-width: 0; }
        header.brand .name { font-weight: 800; font-size: 1.05rem; letter-spacing: -.02em; }
        header.brand .tag { font-size: .75rem; color: #6b7a89; margin-top: .15rem; }

        header.brand .aside { display: flex; align-items: center; gap: .9rem; margin-left: auto; }
        header.brand .phone { font-weight: 600; font-size: .9rem; color: var(--kaiki-primary, #123a5e); }
        header.brand .langs { display: flex; gap: .2rem; }
        header.brand .langs a {
            padding: .2rem .45rem; border-radius: 6px; font-size: .8rem; font-weight: 600; color: #6b7a89;
        }
        header.brand .langs a[aria-current="true"] { background: #eef3f9; color: var(--kaiki-primary, #123a5e); }

        /* `.card` keeps its name because four of the five pages are written in
           it — but it is no longer a card. It is a section of the sheet,
           divided by a hairline like every other. */
        .card {
            background: transparent;
            border: 0;
            border-bottom: 1px solid var(--hair);
            border-radius: 0;
            padding: 1.35rem 1.4rem;
            margin: 0;
        }

        .card:last-child { border-bottom: 0; }

        /* **Ήσυχες γραμμές** — κατεύθυνση Α των μακετών (Mike, 2026-09-23,
           https://claude.ai/artifact/FtgPVNxXNxxijExizrNKxU).

           Μέσα στις δύο στήλες του ταμείου μια ενότητα ήταν πάλι κουτί: δικό
           της περίγραμμα, δική της γωνία, δικό της λευκό. Οι υπόλοιπες σελίδες
           του επισκέπτη είναι ήδη ένα φύλλο χωρισμένο με λεπτές γραμμές, οπότε
           το ταμείο ήταν η εξαίρεση — και είναι η σελίδα όπου τα κουτιά
           κοστίζουν περισσότερο, γιατί εκεί ο επισκέπτης πληρώνει.

           Δεν προστίθεται τίποτα εδώ πια: η `.card` από πάνω δίνει ήδη διάφανο
           φόντο και μια λεπτή γραμμή από κάτω, και το περιθώριο το κρατά το
           `.checkout-grid` παραπάνω. */

        /* Η τελευταία ενότητα κάθε στήλης δεν κρεμάει γραμμή στο κενό. Δύο
           επιλογείς και όχι ένας, γιατί οι δύο στήλες τελειώνουν σε
           διαφορετικά σημεία. */
        .checkout-grid .checkout-main > .card:last-child,
        .checkout-grid .checkout-side > .card:last-child { border-bottom: 0; }

        h1 { font-size: 1.6rem; line-height: 1.18; margin: 0 0 .5rem; letter-spacing: -.01em; }
        h2 { font-size: 1.08rem; margin: 0 0 .8rem; }

        /* A heading in the middle of the form — «Οι ερωτήσεις μας», «Επιβάτες»
           — starts a new subject, so it is given the space to say so. The first
           one does not: it is the top of the card and already has the card's
           padding above it. */
        .checkout-main h2 { margin-block-start: 2.1rem; }
        .checkout-main h2:first-of-type { margin-block-start: 0; }

        .muted { color: rgba(0, 0, 0, .6); font-size: .92rem; }

        dl.rows { margin: 0; display: grid; grid-template-columns: 1fr auto; gap: .5rem .9rem; }
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
        .consent { margin: 1.6rem 0 .25rem; }

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

        /* ---- floating labels, on the checkout only (Mike, 2026-09-22) ----

           The label starts inside the field and rises out of the way once there
           is something in it. Two things come of that: a field is one line of
           the page instead of two, so a form of fifteen questions is fifteen
           lines shorter — which is most of the air this page was asked for —
           and the name of what you typed stays on screen while you type it,
           which a placeholder-only form loses.

           No script, and no second set of markup. `:placeholder-shown` is what
           tells an empty text field from a filled one, which is why every input
           below carries `placeholder=" "`: it is never seen, and it is what
           makes the selector work. `:has()` does the rest — a `<select>` shows
           its first option and a date input shows dd/mm/yyyy from the moment
           it is drawn, so neither can ever look empty and both keep their label
           floated for good.

           `:has()` is the same feature this page already depends on for the
           passport expiry, so it is not a new bet. A browser without it shows
           the label over the top of the field's first line — ugly for a version
           of Safari nobody is on any more, not broken. */
        .checkout-grid .field { position: relative; margin: 0 0 .9rem; }

        .checkout-grid .field > label {
            position: absolute;
            inset-inline-start: .75rem;
            inset-block-start: .8rem;
            margin: 0;
            max-width: calc(100% - 1.5rem);
            overflow: hidden; white-space: nowrap; text-overflow: ellipsis;
            font-size: .95rem; font-weight: 500;
            color: rgba(15, 32, 43, .55);
            transform-origin: left top;
            transition: transform .12s ease-out, color .12s ease-out;
            /* The press has to reach the field under it; the label is still the
               field's name to a screen reader, through `for`. */
            pointer-events: none;
        }

        /* Room for the label above the value. */
        .checkout-grid .field > input,
        .checkout-grid .field > select,
        .checkout-grid .field > textarea,
        .checkout-grid .field .discount-row input { padding-block: 1.4rem .5rem; }

        .checkout-grid .field > textarea { min-height: 5.5rem; }

        .checkout-grid .field:focus-within > label,
        .checkout-grid .field:has(input:not(:placeholder-shown)) > label,
        .checkout-grid .field:has(textarea:not(:placeholder-shown)) > label,
        .checkout-grid .field:has(select) > label,
        .checkout-grid .field:has(input[type="date"]) > label {
            transform: translateY(-.6rem) scale(.76);
            color: rgba(15, 32, 43, .62);
        }

        .checkout-grid .field:focus-within > label { color: var(--kaiki-primary, #123a5e); }

        .checkout-grid .field .field-error { margin-block-start: .35rem; }


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
        /* No text-transform (I18N-2, and the guest pages' own rule): the code
           is matched through DiscountCode::normalise(), so it may be typed in
           any case and is shown as typed. */
        .discount-row input { flex: 1; }
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
        .btn.pay { margin-top: 1.4rem; padding-block: .95rem; }

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

        /* ================================================================
           ΤΟ ΤΑΜΕΙΟ: κατεύθυνση Α — «Ταινία»
           (Mike, 2026-09-23, https://claude.ai/artifact/RSFfLYRH1R212KQDRb3BeG,
           «header footer να ειναι full screen»)
           ================================================================

           Η κεφαλίδα και το υποσέλιδο κόβονταν στο max-width του .wrap, οπότε
           σε μεγάλη οθόνη το ταμείο ήταν μια στήλη στη μέση με γκρι δεξιά κι
           αριστερά — η σελίδα όπου ο επισκέπτης πληρώνει έμοιαζε στενότερη από
           τη σελίδα της εκδρομής απ' όπου ήρθε.

           Αντί να βγουν οι δύο ταινίες έξω από το .wrap — που θα άλλαζε τη δομή
           και των πέντε σελίδων του επισκέπτη — το .wrap ανοίγει σε όλο το
           πλάτος και το ΠΕΡΙΕΧΟΜΕΝΟ κρατά το όριο. Μία γραμμή το κάνει:

               padding-inline: max(1.4rem, calc((100% - 66rem) / 2))

           σε στενή οθόνη δίνει το περιθώριο του κινητού, σε πλατιά κεντράρει
           στα 66rem. Καμία media query, κανένα δεύτερο max-width να ξεφύγει
           από το πρώτο. */
        .wrap.full-bleed { max-width: none; padding: 0; }
        .wrap.full-bleed > .sheet { border: 0; border-radius: 0; box-shadow: none; }

        .wrap.full-bleed > .sheet > header.brand,
        .wrap.full-bleed > .sheet > footer.brand,
        .wrap.full-bleed .checkout-hero > .inner,
        .wrap.full-bleed .checkout-grid,
        .wrap.full-bleed .back-to-site {
            padding-inline: max(1.4rem, calc((100% - 66rem) / 2));
        }

        /* Η ταινία της κεφαλίδας, στο χρώμα του πλοιοκτήτη. Σκούρα και όχι
           λευκή: είναι η κορυφή της σελίδας και πρέπει να μοιάζει με εκείνον,
           όχι με φόρμα. */
        .wrap.full-bleed > .sheet > header.brand {
            background: var(--kaiki-primary, #123a5e);
            color: #fff;
            border-bottom: 0;
            padding-block: 1rem;
        }

        .wrap.full-bleed > .sheet > header.brand .mark { background: #fff; color: var(--kaiki-primary, #123a5e); }
        .wrap.full-bleed > .sheet > header.brand .tag { color: rgba(255, 255, 255, .72); }
        .wrap.full-bleed > .sheet > header.brand .phone { color: #fff; }
        .wrap.full-bleed > .sheet > header.brand .langs a { color: rgba(255, 255, 255, .72); }
        .wrap.full-bleed > .sheet > header.brand .langs a[aria-current="true"] { background: #fff; color: var(--kaiki-primary, #123a5e); }

        /* **Χρώμα, όχι φωτογραφία** (Mike, 2026-09-23: *«θέλω να υπάρχει κάτι
           στάνταρ στο header image εκεί πάνω· ίσως ένα απλό χρώμα, ένα
           ντεγκραντέ; όχι εικόνα»*).

           Πρώτα δοκιμάστηκε με τη φωτογραφία της εκδρομής. Δύο πράγματα δεν
           δούλευαν: οι μισές εκδρομές δεν έχουν φωτογραφία, οπότε η σελίδα είχε
           δύο όψεις και η μία ήταν ένα σκούρο μπλοκ με 130 νεκρά pixel· και μια
           φωτογραφία στην κορυφή της σελίδας πληρωμής πουλάει κάτι που έχει ήδη
           αγοραστεί.

           Το ντεγκραντέ βγαίνει από το ίδιο το χρώμα του πλοιοκτήτη με
           `color-mix`, οπότε είναι το ίδιο σε κάθε εκδρομή και διαφορετικό σε
           κάθε λογαριασμό — χωρίς δεύτερη ρύθμιση να συμπληρώσει κανείς. Η
           διαγώνιος είναι ό,τι χρειάζεται για να μη διαβάζεται ως συνέχεια της
           κεφαλίδας από πάνω· η λεπτή γραμμή κάνει το υπόλοιπο. */
        .checkout-hero {
            position: relative;
            display: flex; align-items: flex-end;
            color: #fff;
            border-top: 1px solid rgba(255, 255, 255, .14);
            background:
                linear-gradient(
                    118deg,
                    color-mix(in srgb, var(--kaiki-primary, #123a5e) 88%, #0a1620) 0%,
                    color-mix(in srgb, var(--kaiki-primary, #123a5e) 62%, #0a1620) 46%,
                    color-mix(in srgb, var(--kaiki-primary, #123a5e) 92%, #000) 100%
                );
        }

        /* Πιο ψηλή ταινία απ' όσο χρειάζεται το κείμενο (Mike, 2026-09-23:
           *«λίγο πιο πολύ padding πάνω κάτω»*): σφιχτή γύρω από δύο γραμμές
           διάβαζε σαν μπάρα συστήματος και όχι σαν η κορυφή της σελίδας. */
        .checkout-hero > .inner {
            position: relative; z-index: 1;
            width: 100%;
            padding-block: 2rem 2.15rem;
        }

        .checkout-hero .reference {
            margin: 0 0 .25rem;
            font-size: .72rem; letter-spacing: .09em;
            color: rgba(255, 255, 255, .88);
        }

        .checkout-hero h1 {
            margin: 0;
            font-size: 1.45rem; line-height: 1.16;
            color: #fff;
            text-wrap: balance;
        }

        @media (min-width: 56rem) {
            .checkout-hero > .inner { padding-block: 2.7rem 2.9rem; }
            .checkout-hero h1 { font-size: 1.9rem; }
        }

        .wrap.full-bleed .back-to-site { padding-block: .8rem 0; margin: 0; }

        /* Πιο σφιχτό από το 1.6rem που είχε: οι ενότητες χωρίζονται ήδη με
           λεπτή γραμμή και το δικό τους padding, οπότε το κενό του πλέγματος
           προστίθεται σε ό,τι υπάρχει ήδη και άνοιγε τρύπα ανάμεσα σε μια
           γραμμή και την επόμενη επικεφαλίδα. */
        .wrap.full-bleed .checkout-grid { padding-block: 1.2rem 0; gap: 1rem; }

        /* Η ταινία του υποσέλιδου, ίδια λογική με την κεφαλίδα. */
        .wrap.full-bleed > .sheet > footer.brand {
            background: var(--kaiki-primary, #123a5e);
            color: rgba(255, 255, 255, .72);
            padding-block: 1.25rem 1.45rem;
            margin-block-start: 2rem;
        }

        .wrap.full-bleed > .sheet > footer.brand a { color: #fff; }

        /* Η γραμμή πληρωμής στον πάτο, μόνο σε κινητό.

           Το κουμπί ήταν στο τέλος της πλαϊνής στήλης, δηλαδή μετά από μια
           οθόνη κύλισης· ο επισκέπτης που είχε συμπληρώσει τα στοιχεία του
           έπρεπε να ψάξει τι πατάει. Η μπάρα δείχνει το ποσό και το κουμπί
           μόνιμα, και υποβάλλει την ίδια φόρμα με το form= — χωρίς δεύτερο
           αντίγραφο της φόρμας και χωρίς script, που αυτή η σελίδα δεν έχει.

           Το κουμπί μέσα στη στήλη κρύβεται όταν υπάρχει η μπάρα: δύο ορατά
           κουμπιά πληρωμής είναι δύο ερωτήσεις. */
        .pay-dock { display: none; }

        @media (max-width: 55.99rem) {
            .pay-dock {
                display: flex; align-items: center; gap: .9rem;
                position: fixed; inset-inline: 0; bottom: 0; z-index: 30;
                background: #fff;
                border-top: 1px solid var(--hair);
                padding: .7rem 1.4rem calc(.85rem + env(safe-area-inset-bottom, 0px));
                box-shadow: 0 -6px 20px rgba(10, 22, 32, .07);
            }

            .pay-dock .amount { flex: none; line-height: 1.1; }
            .pay-dock .amount .label { display: block; font-size: .7rem; color: rgba(15, 32, 43, .55); }
            .pay-dock .amount .value { display: block; font-size: 1.25rem; font-weight: 800; font-variant-numeric: tabular-nums; }
            .pay-dock .btn { flex: 1 1 auto; margin: 0; }

            /* Χώρος από κάτω, αλλιώς η μπάρα σκεπάζει το υποσέλιδο. */
            .wrap.full-bleed > .sheet > footer.brand { padding-bottom: calc(5.6rem + env(safe-area-inset-bottom, 0px)); }

            .checkout-grid .btn.pay { display: none; }
        }

        /* ==================================================================
           The guest pages on the operator's site's type system (Mike,
           2026-09-24; docs/mockups/typo/index.html). Same steps, same greys,
           same link rule as the hosted pages:
             caption 13 · small 15 · body 16 · title 18 · h1 24→32
           Greys: #4A5D5A for secondary text, #5F716E for labels — the three
           translucent blacks (.55 at 3.8:1, .6, .62) are gone. Links in the
           accent at 600, never the browser's #0000EE. Every solid button in the
           accent, as on the site.
           ================================================================== */
        :root { --g-soft: #4A5D5A; --g-faint: #5F716E; --g-deep: #0E2D49; }
        a { color: var(--kaiki-accent, var(--kaiki-primary, #123a5e)); font-weight: 600; }
        button, input, select, textarea { font-family: inherit; }

        h1 { font-size: clamp(1.5rem, 2.3vw, 2rem); font-weight: 700; line-height: 1.15; letter-spacing: -.02em; color: var(--g-deep); }
        .checkout-hero h1 { font-size: clamp(1.5rem, 2.3vw, 2rem); font-weight: 700; letter-spacing: -.02em; }
        @media (min-width: 56rem) { .checkout-hero h1 { font-size: 2rem; } }
        h2 { font-size: 1.125rem; font-weight: 700; letter-spacing: -.012em; color: var(--g-deep); }
        .policy h3, .checkout-side h3 { font-size: .8125rem; font-weight: 600; letter-spacing: .02em; color: var(--g-soft); }

        .label { font-size: .8125rem; letter-spacing: .02em; color: var(--g-faint); }
        .kicker { font-size: .8125rem; font-weight: 600; letter-spacing: .02em; color: var(--g-faint); }
        .checkout-hero .reference { font-size: .8125rem; font-weight: 600; letter-spacing: .02em; color: rgba(255, 255, 255, .78); }
        .facts > div > span { font-size: .8125rem; color: var(--g-soft); }
        .facts > div > b { font-size: 1.125rem; }
        .muted { font-size: .9375rem; color: var(--g-soft); }
        dl.rows dt { color: var(--g-soft); }
        details.passenger .passenger-name { color: var(--g-soft); }
        details.passenger .passenger-band { font-size: .8125rem; }
        header.brand .tag { font-size: .8125rem; color: var(--g-faint); }
        .pass .code { font-size: .8125rem; letter-spacing: .02em; color: var(--g-faint); }
        .checkout-grid .field > label { color: var(--g-faint); }
        .checkout-grid .field:has(input:not(:placeholder-shown)) > label,
        .checkout-grid .field:has(textarea:not(:placeholder-shown)) > label,
        .checkout-grid .field:has(select) > label,
        .checkout-grid .field:has(input[type="date"]) > label { color: var(--g-faint); }
        .checkout-grid .field:focus-within > label { color: var(--kaiki-primary, #123a5e); }

        label, .consent label, details.passenger > summary, .back-to-site, header.brand .phone, .pay-brand { font-size: .9375rem; }
        .checkout-grid .field > label { font-size: .9375rem; }
        header.brand .langs a, footer.brand { font-size: .8125rem; }

        .btn { background: var(--kaiki-accent, var(--kaiki-primary, #0b3d91)); }
        .btn.secondary { background: #fff; }
        .btn.danger, .btn-quiet { background: transparent; }
        @media (max-width: 55.99rem) {
            .pay-dock .amount .label { font-size: .8125rem; color: var(--g-faint); }
            .pay-dock .amount .value { font-weight: 700; }
        }
    </style>
</head>
<body>
{{-- `full-bleed` is the checkout's alone (Mike, 2026-09-23): it drops the
     wrapper's own width so the masthead and the footer span the screen, and
     hands the width limit to the content instead. Every other guest page keeps
     the sheet it has. --}}
<div class="wrap @if ($wide ?? false) wide @endif @if ($wideBooking ?? false) wide-booking @endif @if ($fullBleed ?? false) full-bleed @endif">
    <div class="sheet">
        {{--
            The masthead the rest of the operator's site wears (product owner,
            2026-09-22: the checkout «είναι χάλια» next to the trip page).

            It was the operator's name in small bold type on a hairline — a
            different product from the page the guest was reading a minute
            earlier. The same three things the hosted header carries: who they
            are, a telephone that dials, and the language.

            No navigation, and that is the one deliberate difference. A menu on
            a page somebody is paying on is an invitation to leave it.
        --}}
        <header class="brand">
            <div class="who">
                @if (! empty($brand['logo']['light_url']))
                    <img src="{{ $brand['logo']['light_url'] }}" alt="{{ $tenantName }}">
                @else
                    {{-- The operator who never uploaded a logo gets a mark in
                         their own colour, exactly as on their site. --}}
                    <span class="mark" aria-hidden="true">{{ mb_substr($tenantName, 0, 1) }}</span>
                @endif

                <span class="text">
                    <span class="name">{{ $tenantName }}</span>
                    @if (! empty($brand['tenant']['city']))
                        <span class="tag">{{ $brand['tenant']['city'] }}</span>
                    @endif
                </span>
            </div>

            <div class="aside">
                @if (! empty($brand['tenant']['support_phone']))
                    <a class="phone" href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $brand['tenant']['support_phone']) }}">
                        {{ $brand['tenant']['support_phone'] }}
                    </a>
                @endif

                {{-- `?lang=` is first in the I18N-5 chain, so this switches the
                     page without a second route or any state of its own.

                     **Off where the page must reveal nothing** (TOK-4): these
                     links carry the current address, and on a token page the
                     address is the token. See `link-not-valid`. --}}
                @if ($languages ?? true)
                    <span class="langs">
                        @foreach (\App\Support\Locale\LocaleResolver::installed() as $code)
                            <a
                                href="{{ request()->fullUrlWithQuery(['lang' => $code]) }}"
                                @if (app()->getLocale() === $code) aria-current="true" @endif
                            >{{ __('enums.locale.' . $code . '.short') }}</a>
                        @endforeach
                    </span>
                @endif
            </div>
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
