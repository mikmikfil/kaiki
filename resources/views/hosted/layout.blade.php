{{--
    The hosted page shell (spec HOS-1, HOS-4, HOS-5, HOS-8, HOS-9, HOS-10).

    ## No script tag, anywhere in this file

    HOS-4: everything renders server-side. The widget arrives in M3's later
    issues and mounts into one named region; until then, and whenever a visitor
    blocks scripts, the page is complete without it.

    ## The brand block carries a nonce

    HOS-8's policy has no `unsafe-inline`, so the operator's colours reach the
    page through a `<style>` element with the per-response nonce the middleware
    minted. That is the whole reason the nonce exists, and why it is on the
    request rather than generated here — two generators would disagree.

    ## No `text-transform: uppercase`

    I18N-2. Greek capitals drop their accents and browsers disagree about the
    final sigma. Small labels use letter-spacing and weight in normal case.

    ## Light only

    Settled on 4 September: guest-facing surfaces are light, and light/dark is a
    dashboard concern. So there is no `prefers-color-scheme` block here — the
    colours are the operator's, and they chose them against white.
--}}
@php
    /** @var \App\Models\Tenant $tenant */
    /** @var array<string, mixed> $brand */
    $colors = $brand['colors'] ?? [];
    $primary = $colors['primary'] ?? '#0B4F4A';
    $accent = $colors['accent'] ?? '#B5511F';
    $text = $colors['text'] ?? '#16211F';
    $radius = $brand['button_radius_px'] ?? 10;
    $fontCss = $brand['font']['css_url'] ?? null;
    $fontFamily = $brand['font']['family'] ?? 'Inter';
    $logo = $brand['logo']['light_url'] ?? null;
@endphp
<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>@yield('title', $tenant->name)</title>
    <meta name="description" content="@yield('description', $tenant->name)">

    {{-- HOS-5: one canonical per locale, and each alternate points at the other.

         The current URL is the right canonical for every page whose only
         address is the one being read. The product page overrides it, because
         `PublicProductQuery::find()` also answers a uuid — two URLs for one
         page, which is the duplicate-content problem this tag exists for. --}}
    <link rel="canonical" href="@yield('canonical', $alternates[$locale])">
    @foreach ($alternates as $alternate => $url)
        <link rel="alternate" hreflang="{{ $alternate }}" href="{{ $url }}">
    @endforeach
    <link rel="alternate" hreflang="x-default" href="{{ $alternates['en'] }}">

    {{-- WGT-10's rule, applied to the page: a third-party request only when the
         operator actually chose a font. The CSP omits the host otherwise, so an
         accidental link here would be blocked rather than silently allowed. --}}
    @if ($fontCss)
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="{{ $fontCss }}">
    @endif

    <style nonce="{{ $nonce }}">
        :root {
            --kaiki-primary: {{ $primary }};
            --kaiki-accent: {{ $accent }};
            --kaiki-text: {{ $text }};
            --kaiki-radius: {{ $radius }}px;
            --kaiki-font: {{ $fontFamily }}, Inter, "Helvetica Neue", Arial, sans-serif;

            --paper: #F7F9F8;
            --surface: #FFFFFF;
            --rule: #E1E9E7;
            --ink-soft: #4A5D5A;
            --ink-faint: #7B8D8A;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--paper);
            color: var(--kaiki-text);
            font-family: var(--kaiki-font);
            font-size: 16px;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }

        /* No underline anywhere, hover included. Colour, weight and position
           carry the affordance instead; the focus ring below is what keeps a
           keyboard user able to see where they are, and it is not optional. */
        a { color: var(--kaiki-primary); text-decoration: none; }
        a:hover { text-decoration: none; }
        a:focus-visible, button:focus-visible { outline: 2px solid var(--kaiki-accent); outline-offset: 2px; }

        /* 78rem rather than 66. The trips grid wants three columns on a laptop
           and got two, and a hero image at 66rem is a postcard on a screen that
           has room for a view. Prose blocks keep their own narrower measure
           below — a 78rem line of text is unreadable, and widening the page is
           not the same as widening the paragraph. */
        /* `100vw` includes the scrollbar, so the full-bleed blocks below would
           overhang the viewport by its width on every page long enough to have
           one — a horizontal scrollbar on a page that has nothing to scroll to.
           Clipping at the root is the fix that does not require knowing the
           scrollbar's width. */
        html { overflow-x: hidden; }

        /* 86rem. The trips grid wants three generous columns and the trip page
           wants a readable main column beside a booking card that is not a
           sliver; 78 gave three narrow ones and a 22rem aside that had to be
           argued with. Prose keeps its own measure below — widening the page is
           not the same as widening the paragraph. */
        .wrap { max-width: 86rem; margin: 0 auto; padding: 0 clamp(1.25rem, 3vw, 2.5rem); }

        /* The reading measure, for blocks that are words rather than layout. */
        .prose, .standfirst { max-width: 44rem; }

        /* --- nav --- */

        header.site {
            background: var(--surface);
            border-bottom: 1px solid var(--rule);
        }

        header.site .wrap {
            display: flex; align-items: center; justify-content: space-between;
            gap: 1rem; padding-block: 1.4rem;
        }

        .brand { display: flex; align-items: center; gap: .7rem; text-decoration: none; color: inherit; }
        .brand img { max-height: 44px; width: auto; }
        .brand .name { font-weight: 800; font-size: 1.15rem; letter-spacing: -.02em; }

        .site-nav { margin-left: auto; margin-right: .35rem; font-size: .9rem; }
        .site-nav a { text-decoration: none; color: var(--ink-soft); }
        .site-nav a:hover { color: var(--kaiki-primary); }

        /* HOS-5 and brand decision 2: a visible switch on every guest surface. */
        .langs { display: flex; gap: .25rem; font-size: .85rem; }
        .langs a {
            text-decoration: none; padding: .3rem .55rem;
            border: 1px solid var(--rule); border-radius: var(--kaiki-radius);
            color: var(--ink-soft);
        }
        .langs a[aria-current="true"] {
            background: var(--kaiki-primary); border-color: var(--kaiki-primary); color: #fff;
        }

        /* --- content --- */

        main { padding-block: 0 4.5rem; }

        /* --- typography ----------------------------------------------
           One scale, declared once, with **visible steps between the levels**.
           The page had h1 at 2.6rem, h2 at 1.35 and h3 at 1.05: two of those are
           nearly the same size, so a section heading and a card heading read as
           the same thing and the page has no shape.

           Weight and colour do the work rather than case. I18N-2 forbids
           uppercasing Greek — the accents are dropped and the final sigma is
           argued about — so a design that leaned on capitals would mangle half
           its audience's names.

           `--step-*` rather than literals, so the ratio between the levels can
           be changed in one place and stays a ratio. */
        :root {
            --step-0: 1rem;
            --step-1: 1.125rem;
            --step-2: clamp(1.25rem, 1.6vw, 1.4rem);
            --step-3: clamp(1.5rem, 2.4vw, 1.9rem);
            --step-4: clamp(2rem, 4.4vw, 2.9rem);
            --step-5: clamp(2.2rem, 5vw, 3.4rem);
        }

        h1 { font-weight: 800; font-size: var(--step-4); line-height: 1.08; letter-spacing: -.03em; margin: 0 0 .7rem; text-wrap: balance; }
        h2 { font-weight: 700; font-size: var(--step-3); line-height: 1.15; letter-spacing: -.025em; margin: 0 0 1.8rem; text-wrap: balance; }
        h3 { font-weight: 700; font-size: var(--step-2); line-height: 1.25; letter-spacing: -.018em; margin: 0 0 .5rem; }
        h4 { font-weight: 600; font-size: var(--step-1); line-height: 1.3; letter-spacing: -.01em; margin: 0 0 .4rem; }

        /* The hero is the one place a bigger step is warranted: it is the only
           h1 on the page and it has a photograph behind it to hold its own
           against. */
        .hero h1 { font-size: var(--step-5); margin-bottom: 1rem; max-width: var(--hero-measure); margin-inline: auto; }

        /* A card's heading is an h3 in the outline and should not shout like a
           section heading. This is the step that was missing. */
        li.trip h3, .faq-item h3 { font-size: var(--step-1); }

        /* A small label above a heading. Letter-spacing and colour, never
           capitals. */
        .eyebrow {
            font-size: .78rem; font-weight: 600; letter-spacing: .12em;
            color: color-mix(in srgb, var(--kaiki-primary) 70%, transparent);
            margin: 0 0 .7rem;
        }

        /* Repeated cards are equal height with their last row pinned to the
           bottom — settled on 4 September, and the reason is that a row of
           cards whose prices sit at different heights reads as a mistake. The
           price row lands here with the product page; the grid is built for it
           now so it does not have to be retrofitted. */
        /* `auto-fit` with a 19rem floor: three across on a laptop, two on a
           tablet, one on a phone — and, crucially, the last row stretches to
           fill rather than leaving a half-width card next to empty space, which
           is what `auto-fill` did. */
        ul.trips {
            list-style: none; margin: 0; padding: 0;
            display: grid; grid-template-columns: repeat(auto-fit, minmax(19rem, 1fr));
            gap: 1.5rem;
        }

        /* No border and no movement on hover. A card that lifts and changes
           colour when the cursor passes over it is a page that flinches; the
           shadow below is a resting state rather than a reaction, and the
           heading's own colour change is all the feedback a link needs. */
        li.trip {
            background: var(--surface);
            border-radius: 16px;
            overflow: hidden;
            display: flex; flex-direction: column;
            box-shadow: 0 1px 2px color-mix(in srgb, var(--kaiki-text) 8%, transparent),
                        0 8px 24px -20px color-mix(in srgb, var(--kaiki-text) 40%, transparent);
        }

        /* A fixed ratio, so a row of cards is a row rather than a staircase.
           `object-fit: cover` means an operator's portrait photograph is cropped
           rather than letterboxed — a band of white above a boat is worse than a
           tighter crop of it. */
        .trip-image {
            display: block;
            aspect-ratio: 3 / 2;
            background: color-mix(in srgb, var(--kaiki-primary) 10%, var(--surface));
            overflow: hidden;
        }

        /* `center center` is `object-fit`'s default and is written out here
           anyway, on every photograph the pages place: it is the one property
           that decides which part of an operator's picture survives the crop,
           and leaving it implicit is how one of these quietly ends up anchored
           somewhere else. Asked for directly. */
        .trip-image img { width: 100%; height: 100%; object-fit: cover; object-position: center center; display: block; }

        /* A trip with no photograph. A tinted panel in the operator's own colour
           rather than a broken box or a stock photograph of somebody else's
           boat — and it is a deliberate-looking state, because a new operator
           sees it on every card until they upload something. */
        .trip-image.is-empty {
            background:
                radial-gradient(120% 100% at 30% 20%, color-mix(in srgb, var(--kaiki-primary) 18%, transparent), transparent 70%),
                color-mix(in srgb, var(--kaiki-primary) 8%, var(--surface));
        }

        .trip-body { display: flex; flex-direction: column; flex: 1; padding: 1.5rem 1.6rem 1.6rem; }

        li.trip h3 { margin: 0 0 .45rem; font-size: 1.12rem; line-height: 1.25; }
        li.trip h3 a { color: inherit; }
        li.trip h3 a:hover { color: var(--kaiki-primary); }
        li.trip .summary { margin: 0 0 .9rem; color: var(--ink-soft); font-size: .94rem; }
        /* Two facts behind two icons, stacked rather than run together with
           dots: at a card width of about 19rem the old single line wrapped
           mid-value, so a port and a boat became four ragged lines with dots
           floating between them. */
        li.trip .facts {
            margin: 0 0 1.1rem; color: var(--ink-faint); font-size: .84rem;
            display: grid; gap: .3rem;
        }

        li.trip .facts span { display: inline-flex; align-items: center; gap: .45rem; min-width: 0; }
        li.trip .facts .icon { inline-size: .95rem; block-size: .95rem; flex: none; color: var(--kaiki-primary); }

        /* Price on its own line, the button on the next one across the card.
           Side by side, the button was as wide as its own two words and sat in
           the corner of a photograph-led card, which reads as a footnote rather
           than the thing the card is asking you to do — and on a narrow rail
           item the price and the button were fighting over the same row.

           `margin-top: auto` still pins the whole foot to the bottom, so a row
           of cards has its prices and its buttons on the same two lines however
           long the summaries above them run (settled 4 September). */
        .trip-foot {
            margin-top: auto; padding-top: .9rem;
            border-top: 1px solid var(--rule);
            display: flex; flex-direction: column; align-items: stretch; gap: .75rem;
            /* The rule **and** the button each have to land on one line, and
               they are two different problems.

               `margin-top: auto` on the foot pins its bottom, so a card with no
               price — a charter sold by quote — had a foot 43px shorter and its
               rule 43px higher up the card than its neighbours'. Reserving the
               height of a priced foot fixes the rule.

               That alone moves the problem rather than solving it: with the box
               now tall enough, a foot with nothing in it but a button puts the
               button at the *top* of the reserved space, 43px above the others.
               So the button takes the slack instead (below), and the two land
               on their own lines independently.

               Both were asked for out loud on 8 September: «ιδιο height και το
               button κατω, στο ιδιο σημειο σε ολα». */
            min-height: 5.75rem;
        }

        .trip-foot .button { margin-top: auto; }

        /* The featured rail. A native horizontal scroller: it swipes on a
           phone, scrolls on a trackpad and answers the arrow keys when focused,
           and it needs no script — which matters here, because HOS-8's policy
           has no `unsafe-inline` and a carousel library would be an exception
           bought for something the browser does already.

           `scroll-padding-inline` keeps a snapped card off the very edge, and
           the negative margin lets the rail bleed to the page gutter so the
           sixth card is visibly cut off rather than looking like the last. */
        .trips-rail {
            grid-auto-flow: column;
            grid-auto-columns: minmax(19rem, 22rem);
            grid-template-columns: none;
            overflow-x: auto;
            overscroll-behavior-x: contain;
            scroll-snap-type: x mandatory;
            scroll-padding-inline: 1.5rem;
            padding-bottom: .75rem;
            margin-inline: -1.5rem;
            padding-inline: 1.5rem;
        }

        .trips-rail > li { scroll-snap-align: start; }

        .trips-rail:focus-visible { outline: 2px solid var(--kaiki-accent); outline-offset: 4px; }

        /* A scrollbar an operator's visitor can see, because an invisible one
           on a desktop is a rail nobody knows scrolls. */
/* No scrollbar under the rail.

           It was `scrollbar-width: thin` in the operator's own colour, on the
           reasoning that a rail nobody knows scrolls is a rail nobody scrolls.
           In practice it drew a grey trough the width of the section directly
           under the cards — furniture, on a page whose whole job is to look
           like a shop. The arrows above say the rail moves, and the cards are
           deliberately cut off at the right edge, which says it louder.

           Scrolling itself is untouched: it still swipes, still answers a
           trackpad, and `tabindex="0"` still makes it keyboard-scrollable. This
           hides the indicator, not the behaviour. */
        .trips-rail { scrollbar-width: none; }
        .trips-rail::-webkit-scrollbar { display: none; }

        /* --- the rail's arrows, and not one line of JavaScript ---------
           `::scroll-button()` is the browser's own: it scrolls the container it
           is generated on, disables itself at each end, and is a real button to
           a screen reader. Where it is not implemented no arrow is drawn and the
           rail behaves exactly as it did before — it already swipes on a phone,
           scrolls on a trackpad and answers the arrow keys once focused.

           This is why it is that pseudo-element and not two buttons and a
           listener: HOS-4 promises these pages carry no JavaScript at all, and
           `HostedPageLocaleTest` asserts it by looking for the opening tag.
           That guarantee is worth more than a pair of arrows.

           (Writing the tag's name in this comment is how that test failed the
           first draft of it: this stylesheet is inlined into the page, so a
           comment quoting the forbidden string *is* the forbidden string. The
           note on `.eyebrow` did the same thing an hour earlier with the
           property that recases text. Comments here are page content.)

           They hang off `.trips-rail-frame`, a wrapper that exists only to be
           a positioned ancestor that does not scroll — the rail itself cannot
           be one, because an absolutely positioned child of a scroll container
           scrolls away with the content.

           Bottom right of the rail rather than up on the heading's line, asked
           for directly. It is also the better place: the arrows now sit at the
           end of the thing they scroll instead of competing with «Δείτε όλες
           τις εκδρομές» for the same corner. */
        .trips-block { position: relative; }

        .trips-rail-frame { position: relative; padding-block-end: 3.5rem; }

        /* The pager under «Όλες οι εκδρομές». Plain links, so it needs no more
           than to look deliberate: the current page is filled, the rest are
           quiet, and the two ends grey out rather than disappearing so the row
           does not change width as somebody walks through it. */
        .pager {
            margin: 2.25rem 0 0;
            display: flex; flex-wrap: wrap; align-items: center;
            justify-content: center; gap: .5rem 1rem;
            font-size: .9rem;
        }

        .pager-step { color: var(--kaiki-primary); text-decoration: none; font-weight: 600; }
        .pager-step:hover { text-decoration: underline; }
        .pager-step.is-off { color: var(--ink-faint); font-weight: 400; }

        .pager-pages { list-style: none; margin: 0; padding: 0; display: flex; gap: .3rem; }

        .pager-pages a,
        .pager-pages .is-current {
            display: grid; place-content: center;
            min-inline-size: 2.1rem; block-size: 2.1rem; padding-inline: .5rem;
            border-radius: 8px; text-decoration: none;
            font-variant-numeric: tabular-nums;
        }

        .pager-pages a { color: var(--kaiki-text); }
        .pager-pages a:hover { background: color-mix(in srgb, var(--kaiki-primary) 10%, transparent); }
        .pager-pages .is-current { background: var(--kaiki-primary); color: #fff; font-weight: 600; }

        /* The block's two headings are peers — «Οι πιο δημοφιλείς εκδρομές» and
           «Όλες οι εκδρομές» are two shelves of the same shop, not a section and
           a subsection — so they are the same size. Scoped to this block rather
           than changed on `h2`, which every other section's heading uses. */
        .trips-block .block-head h2 { font-size: var(--step-2); letter-spacing: -.015em; }

        /* The trip page's section headings — «Για την εκδρομή», «Επόμενες
           αναχωρήσεις», «Πού συναντιόμαστε» — a step down. They are signposts
           between short sections on a page whose `<h1>` is the trip's name; at
           the same size as a home-page section heading they competed with it,
           and there are six of them. */
        .product-main .section > h2,
        .product-main .block > h2,
        .product-main .lists h2 { font-size: var(--step-1); letter-spacing: -.012em; margin-block-end: 1rem; }

        .trips-rail::scroll-button(left),
        .trips-rail::scroll-button(right) {
            position: absolute;
            inset-block-end: 0;
            inline-size: 2.25rem; block-size: 2.25rem;
            display: grid; place-content: center;
            border: 1px solid var(--rule);
            border-radius: 50%;
            background: var(--surface);
            color: var(--kaiki-primary);
            font-size: 1.1rem; line-height: 1;
            cursor: pointer;
            transition: border-color .15s ease;
        }

        .trips-rail::scroll-button(left) { inset-inline-end: 3rem; content: '\2039'; }
        .trips-rail::scroll-button(right) { inset-inline-end: 0; content: '\203A'; }

        .trips-rail::scroll-button(*):hover { border-color: var(--kaiki-primary); }
        .trips-rail::scroll-button(*):focus-visible { outline: 2px solid var(--kaiki-primary); outline-offset: 2px; }

        /* Both ends of a rail that does not scroll, and the left one at the
           start: dimmed rather than gone, so the pair does not jump about. */
        .trips-rail::scroll-button(*):disabled { color: var(--ink-faint); opacity: .45; cursor: default; }

        @media (prefers-reduced-motion: reduce) {
            .trips-rail::scroll-button(left),
            .trips-rail::scroll-button(right) { transition: none; }
        }

/* The link keeps the whole row now. It used to reserve space for the
           arrows and still collide with them; they have moved to the foot of
           the rail, so there is nothing to step aside for. */

        /* The second heading in the block, and it is a heading — not a caption.

           It was `--step-2` in `--ink-soft`: a size below the `<h2>` above it
           and a grey lighter than the body text under it, so the one word that
           divides the recommendations from the whole catalogue read as the
           smallest thing on the page. Same size, same colour and same weight as
           the section heading it sits under; the `<h3>` is what carries the
           hierarchy, which is where hierarchy belongs. */
        .trips-more {
            margin: 3.5rem 0 0;
            font-size: var(--step-2);
            letter-spacing: -.015em;
            color: var(--kaiki-text);
            font-weight: 700;
        }

        /* --- section headings, from the design of 8 September ---------
           A short rule in the operator's own colour under every section
           heading, and on the trips block the way out of it on the same line.

           The rule is `::after` on the heading rather than a border on the
           block, so it is as wide as a hand rather than as wide as the page —
           the point of it is to mark where a section starts, and a full-width
           border does the opposite by drawing a lid over everything below. */
        .block > h2::after,
        .block-head h2::after,
        .story-copy > h2::after,
        .trips-more::after {
            content: '';
            display: block;
            inline-size: 2.6rem;
            block-size: 3px;
            /* Air on **both** sides. The first version set only the space above
               the rule, so it took the heading's own bottom margin as its
               breathing room and the cards ended up hard against it — the rule
               read as an underline on the row below rather than as a mark under
               the heading. */
            margin-block: .7rem 1.6rem;
            border-radius: 2px;
            background: var(--kaiki-primary);
        }

        /* …and the heading itself no longer needs one, or the two add up. */
        .block > h2:has(+ *)::after { margin-block-end: 1.6rem; }
        .block-head h2 { margin-block-end: 0; }

        .block-head {
            display: flex; flex-wrap: wrap; align-items: baseline;
            justify-content: space-between; gap: .6rem 1.5rem;
        }

        .see-all {
            color: var(--kaiki-primary); text-decoration: none;
            font-size: .93rem; font-weight: 600;
            display: inline-flex; align-items: center; gap: .4rem;
            /* WCAG 2.5.8: a link on its own line is a tap target. */
            min-block-size: 2.75rem;
        }

        .see-all::after { content: '→'; transition: transform .15s ease; }
        .see-all:hover::after { transform: translateX(3px); }

        @media (prefers-reduced-motion: reduce) { .see-all::after { transition: none; } }

        /* The operator's name above a block heading: letterspaced, and in
           whatever case they typed it.

           I18N-2 forbids the CSS property that would change the case, and two
           tests assert this stylesheet never names it — Greek capitals drop
           their accents, so a browser asked to shout «Ποιοι είμαστε» produces a
           spelling no Greek would write. Spacing is the whole effect here. */
        .eyebrow {
            margin: 0 0 .5rem;
            font-size: .74rem; font-weight: 600; letter-spacing: .14em;
            color: var(--ink-faint);
        }

        .trip-price { margin: 0; display: flex; align-items: baseline; gap: .35rem; }
        /* A quote product has no price element at all (BKG-24), so the button
           would otherwise slide to the left of the card and break the row. */
        /* Full width of the card's text column — which is inset by the body's
           own padding, so it stops short of the card edge rather than running
           into it. */
        .trip-foot .button { width: 100%; justify-content: center; }
        .trip-price .from { font-size: .8rem; color: var(--ink-faint); }
        .trip-price strong { font-size: 1.2rem; letter-spacing: -.02em; }

        /* --- search (#105) ------------------------------------------ */

        .search-head .standfirst { color: var(--ink-soft); max-width: 42rem; margin: 0 0 1.5rem; }

        .search-form {
            display: grid; gap: 1rem 1.1rem; align-items: end;
            grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
            background: var(--surface);
            border-radius: 18px; padding: 1.4rem 1.5rem 1.55rem; margin-bottom: 2.5rem;
            box-shadow: 0 1px 2px color-mix(in srgb, var(--kaiki-text) 7%, transparent);
        }

        /* The search card keeps its own alignment. Centring the hero copy above
           it centred these labels over their fields, which is wrong for a form:
           a label belongs at the start of the control it names, so the eye can
           run down one edge. Reset here rather than by not centring the hero,
           because the card is a different thing from the words above it. */
        .search-form { text-align: start; }
        .search-form .field { display: grid; gap: .35rem; min-width: 0; }
        .search-form label {
            font-size: .74rem; font-weight: 600; letter-spacing: .07em; color: var(--ink-faint);
        }

        /* Fields big enough to hit with a thumb. A search bar on a boat
           operator's home page is used standing on a quay, one-handed.

           `height`, not `min-height`. A minimum is a floor the browser is free
           to exceed, and it does: `input[type=date]` carries a calendar button
           and `input[type=number]` a pair of spinners, so their intrinsic
           content is taller than a line of text. The row measured 52, 50, 46
           and 46 pixels — four heights in one bar, with the labels above them
           landing on four different baselines because the grid aligns to the
           bottom. Nothing was wrong in any single rule; the row was wrong. */
        .search-form input, .search-form select {
            font: inherit; font-size: 1rem; color: var(--kaiki-text);
            padding: 0 .8rem; border: 1px solid var(--rule);
            border-radius: var(--kaiki-radius); background: #fff; width: 100%;
            height: 2.9rem; box-sizing: border-box;
        }

        .search-form input:focus-visible, .search-form select:focus-visible {
            outline: 2px solid var(--kaiki-primary); outline-offset: 1px; border-color: transparent;
        }

        .search-form .submit { align-self: end; }
        .search-form button {
            border: 0; cursor: pointer; font: inherit; width: 100%;
            justify-content: center; height: 2.9rem; box-sizing: border-box;
        }

        .result-count { color: var(--ink-faint); font-size: .88rem; margin: 0 0 1.25rem; }

        /* The price row is pinned to the bottom of the card, so a row of cards
           has its prices on one line — settled on 4 September, because prices at
           different heights read as a mistake. */
        /* A search result's price, in the same foot as a catalogue card's.
           It says what this party pays rather than what one seat starts at, so
           it carries two more pieces: who the figure is for, and that the VAT
           is already in it. The VAT line takes a row of its own — at a card
           width of 19rem it is the third thing on a line that already holds a
           price and a party size. */
        .trip-price { flex-wrap: wrap; }
        .trip-price .for-party { font-size: .85rem; color: var(--ink-soft); }
        .trip-price .vat { flex-basis: 100%; font-size: .78rem; color: var(--ink-faint); }
        .trip-price .on-request { font-weight: 600; color: var(--kaiki-primary); }

        /* Two lines of price instead of one, so the reserved foot is a line
           taller — and every card in the grid reserves it, including the
           on-request card whose price is one word. Same mechanism as the base
           `min-height` above, and the same reason: a row of cards whose buttons
           sit at different heights reads as a mistake. */
        .trip-foot.is-party-price { min-height: 7.1rem; }

        /* Results are capped rather than stretched.
           `ul.trips` uses `auto-fit` with a `1fr` maximum, which is right on
           the home page — a last row of two never leaves a half-width card next
           to a hole. A search answers two trips *often*, and two cards sharing
           a 66rem row are 33rem each: an image the size of a hero, on a page
           whose whole job is comparing one against the other. A ceiling on the
           track and the row packed from the left keeps a result the size of a
           result, and three still fit across a laptop. */
        .trips.results {
            grid-template-columns: repeat(auto-fit, minmax(19rem, 23rem));
            justify-content: start;
        }

        .empty {
            background: var(--surface); border: 1px solid var(--rule);
            border-radius: 14px; padding: 1.5rem 1.6rem;
        }
        .empty h2 { margin-top: 0; }

        /* HOS-10: the booking area, replaced by a sentence. */
        .read-only {
            background: #FDF2EC; border: 1px solid var(--kaiki-accent); border-left-width: 4px;
            padding: 1rem 1.15rem; border-radius: var(--kaiki-radius); margin: 1.5rem 0;
        }

        /* --- home page blocks (#102) ------------------------------ */

        /* Each block owns its vertical rhythm through the gap on `main`
           rather than through margins, so two adjacent blocks never
           collapse or double their spacing. */
        /* The vertical rhythm of the whole page, in one place. A home page's
           sections need room between them or they read as one long column with
           headings in it — and the sections themselves are big, so the gap has
           to be bigger than the space inside them or the hierarchy inverts. */
        main .wrap { display: flex; flex-direction: column; gap: clamp(3.5rem, 7vw, 6rem); }

        /* …and that rhythm is for **sections** — the blocks an operator
           composed. The other three pages this layout serves are not made of
           sections: the search page is a heading, a form and its results; the
           trip page is a breadcrumb and an article; the legal page is prose
           with its own paragraph spacing. Ninety pixels between each of those
           is a void inside what a visitor reads as one thing — and on the legal
           page it was ninety pixels between every paragraph.

           Keyed on the absence of `.block` children rather than on a class each
           page would have to remember to carry. The search form's own bottom
           margin goes with it: it was there to separate the form from what
           follows, and the gap does that now.

           **Nothing else may hard-code this gap.** The breadcrumb did, and see
           the note on `.crumbs` for what that cost. */
        main .wrap:not(:has(> .block)) {
            gap: 1.75rem;

            /* `main` has no top padding, so that the home page's full-bleed
               hero can sit flush under the header — which it should. Every
               other page's first element is a breadcrumb, a heading or a
               paragraph, and those were touching the header rule. */
            padding-block-start: 2rem;
        }

        main .wrap:not(:has(> .block)) > .search-form { margin-bottom: 0; }

        .block { margin: 0; }
        .block > h2:first-child { margin-top: 0; }

        /* --- the hero ------------------------------------------------
           Full-bleed, because a masthead inside a 78rem column reads as the
           first item in a list rather than as the top of a page. The copy sits
           back inside that column so it lines up with everything below it —
           which is the whole reason it is not centred: a centred hero over a
           left-aligned page is two designs on one screen.

           **Tall on purpose.** A hero the height of its text is a coloured band;
           the point of a photograph of the sea is that you can see some of it. */
        .hero {
            display: grid;
            /* Centred in the band rather than sitting on its floor. With the
               copy centred horizontally too, hanging it from the bottom left a
               tall empty field above it and a crowded strip below. */
            align-items: center;
            margin-inline: calc(50% - 50vw);
            min-height: min(60vh, 32rem);
            padding: 0;
        }

        .hero-copy {
            /* The heading and the paragraph under it share one measure.
               They had two — `24ch` on the h1, which at a 54px display size is
               871px, and `38rem` on the standfirst, which is 608px — so the
               text block was 263px narrower than the line above it and the
               shape read as an accident rather than as a decision. One property,
               used by both, cannot drift apart.

               **The search card reads it too**, so the heading, the paragraph
               and the form share one edge. They did not: the copy was held to a
               measure and the form ran the full width of the hero, which left
               the words floating above a bar half again as wide as they were.
               One measure for all three is what makes the hero one block rather
               than two.

               56rem, settled on 8 September after trying 54, 36 and 48. The
               number is a compromise between two opposite pulls — a paragraph
               wants about 60 characters and a four-field form wants room — and
               it is the form that decides the floor. */
            --hero-measure: min(56rem, 100%);
            width: 100%;
            max-width: 86rem;
            margin: 0 auto;
            padding: 3.5rem clamp(1.25rem, 3vw, 2.5rem);
            position: relative;
            z-index: 2;
            /* Centred, asked for on 8 September.
               The note that stood here said the opposite — that a centred hero
               over a left-aligned page is two designs on one screen — and that
               argument still applies to everything **below** the hero, which
               stays left-aligned. What changed is the measure: at 36rem the
               heading and its paragraph are a block rather than a band, and a
               narrow block reads better centred over a photograph than pushed
               against one edge of it. */
            text-align: center;
        }

        .hero.has-image {
            position: relative;
            color: #fff;
            background: var(--kaiki-primary);
            isolation: isolate;
            min-height: min(86vh, 48rem);
        }

        .hero.has-image .hero-image,
        .hero.has-image .hero-video {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center center;
            border-radius: 0;
            /* Above the section's own background colour, which is the fallback
               while the photograph loads, and below the scrim. */
            z-index: 0;
        }

/* The scrim, rebuilt — it used to be the reason the hero looked dead.

           It was the operator's primary at 25% → 55% → 88%, top to bottom,
           which is a teal wash over the whole frame: the sky went teal, the sea
           went teal, the white hull the photograph is *of* went teal. A
           photograph you have tinted end to end is not a photograph any more,
           it is a coloured panel with some shapes in it.

           Two layers instead, and neither touches the top half:

           1. A near-black foot that is fully transparent until 40% and only
              arrives where the type actually sits. Neutral rather than branded,
              because a colour cast is what killed the last one — this darkens
              the picture without recolouring it.
           2. A thin wash of the operator's primary in the bottom third alone,
              so the hero still reads as their brand where it meets the page
              below, and nowhere else.

           The result keeps the sky, the sea and the hull their own colours, and
           still puts white type on something dark enough to read. */
        .hero.has-image::after {
            content: '';
            position: absolute;
            inset: 0;
            z-index: 1;
            background:
                /* An even, neutral exposure drop across the whole frame. This
                   is the layer that makes white type readable, and it is flat
                   on purpose: lowering the exposure keeps every colour in the
                   photograph in its right relationship to the others, which a
                   gradient tint does not. A bright noon shot of a white hull
                   under a pale sky has nowhere dark for a heading to sit, and
                   no amount of bottom-weighting fixes type in the middle. */
                linear-gradient(rgba(6, 16, 20, .34), rgba(6, 16, 20, .34)),
                /* Then a foot, so the type has more under it than the sky does
                   and the section has an edge to meet the page on. */
                linear-gradient(
                    to bottom,
                    rgba(6, 16, 20, 0) 30%,
                    rgba(6, 16, 20, .34) 78%,
                    rgba(6, 16, 20, .58) 100%
                ),
                /* And a whisper of the operator's own colour where it lands on
                   the page below, so the hero still belongs to their brand
                   without the brand being painted over the picture. */
                linear-gradient(
                    to bottom,
                    transparent 68%,
                    color-mix(in srgb, var(--kaiki-primary) 30%, transparent) 100%
                );
        }

        /* The type gets its own small shadow rather than a heavier scrim. One
           more stop of darkening over the whole frame costs the photograph far
           more than it buys the heading. */
        .hero.has-image h1,
        .hero.has-image .standfirst { text-shadow: 0 1px 24px rgba(6, 16, 20, .45); }

        .hero.has-image .hero-copy { padding-block: 7rem 4rem; }
        .hero.has-image h1 { color: #fff; }
        .hero.has-image .eyebrow { color: rgba(255, 255, 255, .78); }
        .hero.has-image .standfirst { color: rgba(255, 255, 255, .92); }
        .hero.has-image .cta .button { background: #fff; border-color: #fff; color: var(--kaiki-primary); }
        .hero.has-image .cta .button:hover { background: rgba(255, 255, 255, .88); }

        .hero-copy .standfirst { color: var(--ink-soft); font-size: clamp(1.1rem, 1.5vw, 1.3rem); line-height: 1.5; max-width: var(--hero-measure); margin-inline: auto; }
        .hero-copy .standfirst p { margin: 0 0 .7rem; }
        .hero-copy .standfirst p:last-child { margin-bottom: 0; }

        @media (max-width: 40rem) {
            .hero, .hero.has-image { min-height: 0; }
            .hero.has-image .hero-copy { padding-block: 3.5rem 2.5rem; }
        }

        .cta { margin: 1.5rem 0 0; }

        /* The search a visitor came here to use, sitting under the masthead
           copy. On the hero's photograph it is a solid panel rather than a
           translucent one: a date field with a photograph showing through it is
           a date field nobody can read. */
        /* The masthead's copy of the search form. A solid panel rather than a
           translucent one: a date field with a photograph showing through it is
           a date field nobody can read.

           It carries **every** field the search page does. A hero form with two
           of the six teaches a visitor something untrue about the site, and
           they find out on the next page. */
        .hero-search {
            /* The card carries the measure, not the form inside it. Putting it
               on `.search-form` shrank the four fields and left the white box
               they sit in running the full width of the hero — the constraint
               applied to the wrong box, which is only visible by looking. */
            max-width: var(--hero-measure);
            margin: 2rem auto 0;
            background: var(--surface);
            border-radius: 18px;
            padding: 1.6rem 1.7rem 1.7rem;
            box-shadow: 0 14px 40px -26px color-mix(in srgb, var(--kaiki-text) 75%, transparent);
        }

        .hero-search .search-form {
            background: transparent;
            border: 0;
            border-radius: 0;
            padding: 0;
            margin: 0;
            box-shadow: none;
        }

        /* **One row on a wide screen, and only there.** `auto-fit` wrapped the
           six fields onto two lines the moment they stopped fitting, which on a
           masthead reads as a form that has fallen over. Above 64rem every field
           shares the row and shrinks together; below it they stack, which is the
           right answer on a phone and the only one that stays legible.

           `minmax(0, 1fr)` rather than `1fr`: a grid item's default minimum is
           its content, so a long port name would push the row wider than the
           panel and the last field would hang off the edge. */
        @media (min-width: 64rem) {
            .hero-search .search-form {
                display: flex;
                flex-wrap: nowrap;
                align-items: end;
                gap: .85rem;
            }

            .hero-search .search-form .field { flex: 1 1 0; min-width: 0; }

            /* The button is the one thing that should not shrink to fit. */
            .hero-search .search-form .submit { flex: 0 0 auto; }
            .hero-search .search-form button { width: auto; padding-inline: 1.6rem; }
        }
        /* The icon beside each field label. Muted rather than in the brand
           colour: six accent-coloured marks above six fields would read as six
           things needing attention, which is the opposite of what a form full
           of optional filters should look like. */
        .search-form label {
            display: inline-flex; align-items: center; gap: .4rem;
        }

        .search-form label .icon {
            inline-size: .95rem; block-size: .95rem; flex: none;
            color: var(--ink-faint);
        }

        .button {
            display: inline-flex; align-items: center; gap: .5rem;
            text-decoration: none;
            background: var(--kaiki-primary); color: #fff;
            border: 1px solid var(--kaiki-primary);
            padding: .8rem 1.5rem; border-radius: var(--kaiki-radius);
            font-weight: 600; font-size: .97rem; line-height: 1;
            transition: background-color .15s ease;
        }
        .button:hover { background: color-mix(in srgb, var(--kaiki-primary) 90%, var(--kaiki-text)); border-color: transparent; }
        .button-small { padding: .55rem 1rem; font-size: .9rem; }

        /* An arrow on the buttons that go somewhere, and only those. A button
           that submits a form or opens a mail client is not a way forward
           through the page, and an arrow on it would be a lie about where it
           leads. `::after` rather than a character in the string, so no
           translator has to carry punctuation in a translation file. */
        .button.arrow::after { content: '→'; transition: transform .15s ease; }
        .button.arrow:hover::after { transform: translateX(3px); }

        @media (prefers-reduced-motion: reduce) { .button.arrow::after { transition: none; } }

        /* The magnifier inside «Αναζήτηση». It is `1em`, so it tracks the
           button's own font size rather than needing a second number when the
           button changes size on a phone. */
        .button .icon { inline-size: 1.05em; block-size: 1.05em; flex: none; }

        @media (prefers-reduced-motion: reduce) {
            .button { transition: none; }
        }

        /* Centred rather than top-aligned: the photograph is smaller than the
           column of prose beside it, and hanging it from the top leaves a
           wedge of empty space under it that reads as a layout mistake. */
        /* Once the widget has drawn itself, the no-JavaScript answer above it
           is noise — but it must stay in the markup until then, for a crawler,
           a blocked script and a bad connection. `:has()` does that with no
           second script, which matters because HOS-8's policy has no
           `unsafe-inline` and the alternative was a nonced inline block whose
           only job was to hide two paragraphs.

           A browser without `:has()` shows the fallback beneath a working
           widget: untidy, and still bookable. That is the right way round. */
        .mount:has(> [data-kaiki-widget]) > .no-js,
        .mount:has(> [data-kaiki-widget]) > .contact-cta { display: none; }

        /* And the four lines, for the same reason and a longer one.
           Brand decision 3 (4 September) puts **title, duration, port and
           vessel above the widget's date picker** — the widget carries them
           because it also runs on somebody else's website, where there is no
           surrounding page to say what is being booked.

           This page renders its own copy so that a guest with no JavaScript
           still learns those four facts. Once the widget draws, both copies are
           on screen at once, in the same column, about 470px apart: the same
           four rows twice, which reads as a rendering fault rather than as
           emphasis. Found by looking at the page — the second copy lives in the
           widget's shadow root, so nothing that inspects this document can see
           the duplication. */
        .booking:has(.mount > [data-kaiki-widget]) > .four-lines { display: none; }

        /* And the widget's own frame comes off, because this card is already
           one. Left on, the trip page drew a white bordered box inside a white
           bordered box — two radii, two paddings and two backgrounds, with the
           date picker at the centre of the onion.

           These four custom properties are the only thing about this page that
           reaches into the widget's shadow root, and they reach it the way
           custom properties reach anything: by inheriting. The widget declares
           them with the frame as the default, so it keeps the frame on every
           other website — where it is a stranger in somebody else's page and
           does have to say where it begins. */
        .mount > [data-kaiki-widget] {
            --kaiki-surface-background: transparent;
            --kaiki-surface-border: 0;
            --kaiki-surface-radius: 0;
            --kaiki-surface-padding: 0;
        }

        .story { display: grid; gap: 2rem; align-items: center; }
        /* Squarer and larger. At 4/3 in the narrow column this was a
           postcard beside four paragraphs — the one photograph on the page of
           the people whose boat it is, printed smaller than a trip card. */
        .story-image { width: 100%; height: auto; border-radius: 16px; object-fit: cover; object-position: center center; aspect-ratio: 1 / 1; }
        /* Breathing room on both sides of the prose, on top of the grid gap.
           A paragraph that runs to the very edge of its column reads as though
           it has been cropped rather than laid out, and the block is the one
           place on the page where somebody is asked to read more than a line. */
        .story-copy { padding-inline: clamp(0rem, 3vw, 2.5rem); }
        .prose p { margin: 0 0 .8rem; }
        .prose p:last-child { margin-bottom: 0; }

        @media (min-width: 46rem) {
            /* Not an even split. The block is prose with a photograph beside
               it, not a photograph with a caption — half the row for the image
               makes the picture the subject and squeezes the paragraphs into a
               narrow column that is harder to read. */
            /* Was `2fr 1fr` — the copy took two thirds and the picture got
               what was left. Even columns: the photograph is half the point of
               the block. */
            .story.has-image { grid-template-columns: 1fr 1fr; gap: clamp(2rem, 5vw, 4rem); }
            /* The ratio follows the content, not the position. `order` moves
               the image into the first column, so without this the photograph
               inherits the wide column meant for the prose and the two swap
               sizes as well as sides — the picture becomes the subject and the
               paragraphs are squeezed into a third of the row. */
            .story.side-left.has-image { grid-template-columns: 1fr 1fr; }
            /* A class rather than an inline style: HOS-8's policy has no
               `unsafe-inline`, so a `style` attribute would be dropped and
               the operator's choice would silently do nothing.

               The copy is first in the DOM, so image-right is the default and
               this is the rule that moves it. With the image first — as it was
               until this was noticed — `order: -1` put it where it already was
               and `image_side` did nothing at all. */
            .story.side-left.has-image .story-image { order: -1; }
        }

        .gallery .shots {
            list-style: none; margin: 1rem 0 0; padding: 0;
            display: grid; gap: .8rem;
            grid-template-columns: repeat(auto-fill, minmax(13rem, 1fr));
        }
        .gallery.cols-2 .shots { grid-template-columns: repeat(auto-fill, minmax(19rem, 1fr)); }
        .gallery.cols-4 .shots { grid-template-columns: repeat(auto-fill, minmax(10rem, 1fr)); }
        .gallery .shots img { width: 100%; height: 100%; border-radius: 10px; object-fit: cover; object-position: center center; aspect-ratio: 3 / 2; }

        /* --- product page (#104) ------------------------------------ */

        /* A visible label for a screen reader and nobody else. The booking
           area needs a heading in the outline (A11Y) and does not want one on
           the page, because the four lines below it say what it is. */
        .sr-only {
            position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
            overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0;
        }

        /* The crumbs sit directly above the page and are part of it, not
           another block: a breadcrumb six rems above the photograph it belongs
           to reads as a separate thing.

           This used to say `margin-bottom: calc(clamp(3.5rem, 7vw, 6rem) * -1 +
           1.25rem)` — cancel the container's gap, then add back the 20px it
           actually wants. That worked for exactly as long as the container had
           one gap. The moment the rule below gave app-drawn pages a tighter
           one, the compensator was still subtracting the old ninety and the
           whole trip page — breadcrumb, photograph and all — was dragged
           twenty pixels **above the bottom of the site header** and printed
           over it.

           The gap belongs to the container. A child that hard-codes it is a
           second copy of a number, and this is what happens when the two stop
           agreeing. */
        .crumbs {
            font-size: .85rem; color: var(--ink-faint);
            display: flex; gap: .45rem; align-items: baseline;
        }
        .crumbs a { color: var(--ink-soft); text-decoration: none; }
        .crumbs a:hover { color: var(--kaiki-primary); }

        .product { display: flex; flex-direction: column; gap: clamp(2rem, 4vw, 3rem); }

        /* The lead photograph. Full width of the column and letterboxed, so the
           page opens with the view rather than with a heading over a grid of
           four thumbnails. */
        .product-lead {
            border-radius: 20px;
            overflow: hidden;
            background: color-mix(in srgb, var(--kaiki-primary) 8%, var(--surface));
        }

        .product-lead img {
            display: block; width: 100%; height: 100%;
            aspect-ratio: 21 / 9; object-fit: cover;
        }

        .product-head h1 { margin-bottom: .7rem; }
        .product-head .standfirst { color: var(--ink-soft); font-size: var(--step-1); max-width: 44rem; margin: 0 0 1.2rem; }

        /* --- the two-column body -------------------------------------
           What the trip is on the left, how to book it on the right, and the
           booking card sticky so it is still on screen at the bottom of the
           itinerary. A booking form below three screens of prose is a booking
           form nobody scrolls back up to.

           One column below 60rem, in source order — so on a phone the booking
           card is **last**, under the itinerary and the FAQ. This comment used
           to claim the opposite and no rule ever did it: `order` is set only
           inside the media query below, which is the desktop case.

           Hoisting it is not a one-line change and should not be pretended to
           be. `.product-main` opens with the title and the standfirst, so an
           `order: -1` on the aside puts a price and a date picker above the
           name of the trip they belong to. Doing it properly means lifting
           `.product-head` out of the left column so that a phone reads
           photograph, title, card, and then the page — which is a markup change
           to the trip page, not a rule here. Written down rather than left as a
           comment that lies about the layout. */
        .product-body { display: grid; gap: clamp(2rem, 4vw, 3.5rem); }

        .product-main { display: flex; flex-direction: column; gap: clamp(2rem, 4vw, 3rem); min-width: 0; }

        @media (min-width: 60rem) {
            .product-body {
                grid-template-columns: minmax(0, 1fr) 27rem;
                align-items: start;
            }

            .product-aside { order: 2; position: sticky; top: 1.5rem; }
            .product-main { order: 1; }
        }

        /* Normal case with letter-spacing doing the emphasis. I18N-2 forbids
           the CSS property that would change it — Greek capitals drop their
           accents — and two tests assert this stylesheet never names it. */
        /* The five facts under the title, each under its own icon.

           They were one row of values with dots between them — a sentence a
           visitor has to read through to find the one thing they came for. The
           dots are gone with the run-on line; what separates the facts now is
           the space between them. */
        /* The row sits **above** the title, and the title is still the first
           thing in the markup.

           `order` rather than moving the `<ul>` in the template: a page whose
           source begins "480 minutes, day charter, Zea Marina" announces four
           details to a screen reader and to a crawler before it says what the
           trip is called. Nothing in the row is focusable, so the usual reason
           to refuse a visual reorder — a tab sequence that jumps about — does
           not apply here. */
        .product-head { display: flex; flex-direction: column; }
        .product-head h1 { order: 2; }
        .product-head .standfirst { order: 3; }
        ul.facts { order: 1; }

        ul.facts {
            list-style: none; margin: 0 0 1.5rem; padding: 0;
            display: flex; flex-wrap: wrap; gap: 1.1rem 2.2rem;
            font-size: .92rem; color: var(--ink-soft); letter-spacing: .01em;
        }

        ul.facts li { display: grid; gap: .4rem; justify-items: start; }
        ul.facts .icon { inline-size: 1.15rem; block-size: 1.15rem; color: var(--kaiki-primary); }

        /* --- the trip page's gallery: masonry, and a lightbox ----------

           `columns` rather than a grid, because masonry is the point: each
           photograph keeps its own proportions and the columns fill unevenly,
           which is what makes a wall of pictures read as a wall of pictures
           rather than as a contact sheet of identical crops. The seeder stores
           these at their natural size for exactly this reason.

           Three columns down to two and then one, so a phone gets one column of
           full-width photographs rather than three thumbnails. */
/* `ul.` again, and for the same reason the image rule needs it: `.shots`
           sets `display: grid`, it sits later in this stylesheet, and it has
           exactly the same specificity — so it won on order, `columns` was
           ignored on a grid container, and the "masonry" was a plain
           three-column grid with the photographs letterboxed into it. Two rules
           in this file have now been caught by the same tie; if a third appears,
           the `.shots` block should move below these instead. */
        ul.shots-masonry {
            display: block;
            columns: 3;
            column-gap: 1rem;
        }

        ul.shots-masonry li { break-inside: avoid; margin: 0 0 1rem; }

        /* `ul.` on purpose. `.shots img` sets a 3/2 ratio and `height: 100%`,
           it sits later in this stylesheet, and it has exactly the same
           specificity — so it won on order and the masonry was a grid of
           identical crops with the photographs squashed inside it. The type
           selector breaks the tie without moving either rule. */
        ul.shots-masonry img { aspect-ratio: auto; height: auto; }
        .shots-masonry .shot-open { display: block; border-radius: 14px; overflow: hidden; }
        .shots-masonry .shot-open:focus-visible { outline: 2px solid var(--kaiki-primary); outline-offset: 3px; }

        @media (max-width: 60rem) { ul.shots-masonry { columns: 2; } }
        @media (max-width: 34rem) { ul.shots-masonry { columns: 1; } }

        /* The lightbox. Open when the URL names it, and nothing else.
           `display` rather than opacity, so a closed panel is out of the
           accessibility tree instead of merely invisible. */
        .lightbox { display: none; }

        .lightbox:target {
            position: fixed; inset: 0; z-index: 60;
            display: grid; place-items: center;
            padding: clamp(1rem, 4vw, 3rem);
        }

        .lightbox-scrim { position: absolute; inset: 0; background: rgba(6, 16, 20, .88); }

        /* The figure is the grid item being centred, so it must not stretch:
           `place-items: center` on the panel sizes it to its content, and the
           image inside is then centred both ways against the viewport. */
        .lightbox figure {
            position: relative; z-index: 1; margin: 0;
            max-inline-size: min(94vw, 68rem);
            max-block-size: 88vh;
            display: flex; align-items: center; justify-content: center;
        }

        .lightbox figure img {
            max-inline-size: 100%; max-block-size: 88vh;
            inline-size: auto; block-size: auto;
            object-fit: contain; border-radius: 10px; display: block;
        }

        .lightbox-close {
            position: absolute; z-index: 2;
            inset-block-start: clamp(.75rem, 3vw, 1.5rem); inset-inline-end: clamp(.75rem, 3vw, 1.5rem);
            color: #fff; text-decoration: none; font-weight: 600; font-size: .9rem;
            padding: .45rem .8rem; border: 1px solid rgba(255, 255, 255, .5); border-radius: 999px;
        }

        .lightbox-close:hover { background: rgba(255, 255, 255, .14); }

        .lightbox-step a {
            position: absolute; z-index: 2; inset-block-start: 50%; translate: 0 -50%;
            color: #fff; text-decoration: none; font-size: 2rem; line-height: 1;
            padding: .6rem .9rem; border-radius: 999px;
        }

        .lightbox-step a:hover { background: rgba(255, 255, 255, .14); }
        .lightbox-step .prev { inset-inline-start: clamp(.25rem, 2vw, 1.5rem); }
        .lightbox-step .next { inset-inline-end: clamp(.25rem, 2vw, 1.5rem); }

        /* --- the trip page's tabs, with no script ----------------------

           Radio inputs carry the state: one name, one checked, and the panel
           whose sibling input is checked is the one displayed. The inputs sit
           before both the labels and the panels so `~` can reach each of them,
           and they are off-screen rather than `display: none`, because a hidden
           input cannot be focused and the tabs would stop answering the
           keyboard entirely. */
        .tab-radio {
            position: absolute; width: 1px; height: 1px;
            margin: -1px; padding: 0; border: 0;
            clip-path: inset(50%); overflow: hidden; white-space: nowrap;
        }

        .tablist {
            display: flex; flex-wrap: wrap; gap: .3rem;
            justify-content: flex-start; text-align: left;
            border-block-end: 1px solid var(--rule);
            margin-block-end: 1.5rem;
        }

        .tab-label {
            padding: .7rem 1.1rem; cursor: pointer;
            font-size: .95rem; font-weight: 600; color: var(--ink-soft);
            border-block-end: 2px solid transparent;
            margin-block-end: -1px;
            white-space: nowrap;
        }

        .tab-label:hover { color: var(--kaiki-text); }

        .tabpanel { display: none; }

        /* One pair per tab. There are three, so writing them out is shorter and
           clearer than anything that would generate them. */
        #tab-departures:checked ~ .tablist label[for="tab-departures"],
        #tab-meeting:checked ~ .tablist label[for="tab-meeting"],
        #tab-vessel:checked ~ .tablist label[for="tab-vessel"] {
            color: var(--kaiki-primary); border-block-end-color: var(--kaiki-primary);
        }

        #tab-departures:focus-visible ~ .tablist label[for="tab-departures"],
        #tab-meeting:focus-visible ~ .tablist label[for="tab-meeting"],
        #tab-vessel:focus-visible ~ .tablist label[for="tab-vessel"] {
            outline: 2px solid var(--kaiki-primary); outline-offset: 2px; border-radius: 6px;
        }

        #tab-departures:checked ~ .tabpanels .tabpanel-departures,
        #tab-meeting:checked ~ .tabpanels .tabpanel-meeting,
        #tab-vessel:checked ~ .tabpanels .tabpanel-vessel { display: block; }

        /* Printed, the tabs are meaningless — show every panel. */
        @media print { .tabpanel { display: block !important; } .tablist { display: none; } }

        /* The boat's facts. A definition list because that is what it is, laid
           out in columns so six short rows do not become six long ones. */
        .boat-name { margin: 0 0 1rem; font-size: var(--step-1); }

        .boat-facts {
            margin: 0 0 1.5rem; padding: 0;
            display: grid; gap: .9rem 2rem;
            grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
        }

        .boat-facts > div { display: grid; gap: .15rem; }
        .boat-facts dt { font-size: .72rem; font-weight: 600; letter-spacing: .08em; color: var(--ink-faint); }
        .boat-facts dd { margin: 0; font-size: .98rem; font-weight: 600; }

        .boat-shots { margin-top: 1.25rem; grid-template-columns: repeat(auto-fill, minmax(12rem, 1fr)); }

        .shots { list-style: none; margin: 0; padding: 0; display: grid; gap: 1rem;
                 grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr)); }
        .shots img { width: 100%; height: 100%; border-radius: 14px; object-fit: cover; object-position: center center; aspect-ratio: 3 / 2; display: block; }

        /* The booking area. Four lines above the mount, and nothing else —
           brand decision 3 of 2026-09-04. */
        .booking {
            background: var(--surface);
            border-radius: 18px;
            padding: clamp(1.75rem, 2.2vw, 2.35rem);
            box-shadow: 0 1px 2px color-mix(in srgb, var(--kaiki-text) 8%, transparent),
                        0 14px 40px -28px color-mix(in srgb, var(--kaiki-text) 60%, transparent);
        }

        .four-lines { display: grid; gap: .55rem; margin: 0 0 1.1rem; }
        .four-lines > div { display: grid; grid-template-columns: 8.5rem 1fr; gap: .9rem; align-items: baseline; }
        .four-lines dt {
            font-size: .74rem; font-weight: 600; letter-spacing: .07em;
            color: var(--ink-faint); margin: 0;
        }
        .four-lines dd { margin: 0; font-weight: 600; }

        /* No rule above it any more: it is the first thing in the card. */
        .price { margin: 0 0 1.3rem; display: flex; flex-wrap: wrap; align-items: baseline; gap: .5rem; }
        .price .from { font-size: .85rem; color: var(--ink-faint); }
        .price strong { font-size: var(--step-3); letter-spacing: -.025em; }
        .price .vat { font-size: .82rem; color: var(--ink-faint); flex-basis: 100%; }

        /* What the card carries besides the price and the form: capacity, the
           boat, who pays what, the first of the includes, and the cancellation
           window. A visitor deciding whether to press the button is asking
           exactly these, and scrolling away from the button to answer them is
           how a booking is abandoned — so they stay in the card.

           **Folded, and below the form.** Open, the five of them made the card
           816 pixels tall with the date picker at the bottom of it: on a laptop
           the button was below the fold, on a card whose entire purpose is to
           keep the button in view. Three disclosures put the card at about a
           third of that and cost one click to the guest who wants an answer.

           `<details>` and nothing else. It opens without JavaScript, it is a
           disclosure to a screen reader with no attribute to remember, and
           find-in-page opens it — none of which is true of a scripted panel,
           and HOS-8 took `unsafe-inline` out of the policy. */
        .booking-more {
            margin: 1.4rem 0 0; padding-top: .35rem;
            border-top: 1px solid var(--rule);
            font-size: .92rem;
        }

        .fold { border-bottom: 1px solid var(--rule-soft, var(--rule)); }
        .fold:last-child { border-bottom: 0; }

        .fold > summary {
            list-style: none; cursor: pointer;
            display: flex; align-items: center; justify-content: space-between; gap: 1rem;
            padding: .85rem 0;
            font-weight: 600; color: var(--kaiki-text);
            /* WCAG 2.5.8 — the whole row is the target, not the words. */
            min-block-size: 2.75rem;
        }

        .fold > summary::-webkit-details-marker { display: none; }
        .fold > summary::marker { content: ''; }
        .fold > summary:hover { color: var(--kaiki-primary); }
        .fold > summary:focus-visible { outline: 2px solid var(--kaiki-primary); outline-offset: 3px; border-radius: 4px; }

        /* The chevron, drawn rather than fetched: two borders and a rotation.
           It points down when the fold is shut and up when it is open, which is
           the only signal on the row that it does anything at all. */
        .fold > summary::after {
            content: ''; flex: none;
            inline-size: .5rem; block-size: .5rem;
            border-right: 2px solid var(--ink-faint);
            border-bottom: 2px solid var(--ink-faint);
            transform: rotate(45deg) translate(-2px, -2px);
            transition: transform .15s ease;
        }

        .fold[open] > summary::after { transform: rotate(-135deg) translate(-2px, -2px); }

        @media (prefers-reduced-motion: reduce) {
            .fold > summary::after { transition: none; }
        }

        .fold-body { padding: 0 0 1.1rem; display: grid; gap: 1.05rem; }

        .booking-more .extra { display: grid; grid-template-columns: 1.15rem 1fr; gap: .8rem; align-items: start; }
        .booking-more .extra > div { display: grid; gap: .22rem; min-width: 0; }

        .booking-more .icon {
            width: 1.15rem; height: 1.15rem;
            color: var(--kaiki-primary);
            margin-top: .1rem;
        }

        .booking-more .label {
            font-size: .72rem; font-weight: 600; letter-spacing: .08em; color: var(--ink-faint);
        }

        .booking-more .muted { color: var(--ink-faint); font-size: .86rem; }

        .booking-more ul { list-style: none; margin: 0; padding: 0; display: grid; gap: .3rem; }
        .booking-more ul li { padding-left: 1.2rem; position: relative; color: var(--ink-soft); }
        .booking-more ul li::before { position: absolute; left: 0; content: '✓'; color: var(--kaiki-primary); }
        .booking-more .more { color: var(--ink-faint); font-size: .85rem; }

        /* The age bands are a list of pairs, not ticks: the marker in front of
           each one would read as "this is included", which is the opposite of
           what a band that pays nothing and takes no seat means. */
        .booking-more ul.bands { gap: .35rem; margin-top: .1rem; }
        .booking-more ul.bands li { padding-left: 0; display: flex; flex-wrap: wrap; gap: .45rem; align-items: baseline; }
        .booking-more ul.bands li::before { content: none; }
        .booking-more ul.bands strong { color: var(--kaiki-text); font-weight: 600; }

        .mount .no-js { margin: 0 0 .9rem; color: var(--ink-soft); font-size: .93rem; }
        .contact-cta { margin: 0; display: flex; flex-wrap: wrap; gap: .6rem; }
        .button.ghost { background: transparent; color: var(--kaiki-primary); border: 1px solid var(--kaiki-primary); }

        .section > h2:first-child { margin-top: 0; }
        .section { }
        .section .prose { max-width: 44rem; }
        .muted { color: var(--ink-faint); font-size: .9rem; }

        /* The meeting-point map. A fixed aspect ratio rather than a fixed
           height, so it is the same shape on a phone as on a laptop and
           never a letterbox on either. The border matches the cards so a
           third-party frame does not read as a hole in the page. */
        .map-embed {
            margin-top: 1.25rem;
            border: 1px solid var(--rule);
            border-radius: var(--radius-card, 14px);
            overflow: hidden;
            aspect-ratio: 16 / 9;
            max-width: 44rem;
            background: var(--surface-sunk, #eef1ef);
        }
        .map-embed iframe { width: 100%; height: 100%; border: 0; display: block; }
        @media (max-width: 40rem) { .map-embed { aspect-ratio: 4 / 3; } }


        .lists { display: grid; gap: 1.75rem; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); align-items: start; }
        ul.ticks { list-style: none; margin: 0; padding: 0; display: grid; gap: .35rem; font-size: .95rem; }
        ul.ticks li { padding-left: 1.35rem; position: relative; }
        ul.ticks li::before { position: absolute; left: 0; content: '✓'; color: var(--kaiki-primary); }
        ul.ticks.excludes li::before { content: '×'; color: var(--kaiki-accent); }
        ul.ticks.what_to_bring li::before { content: '·'; color: var(--ink-faint); }

        ol.itinerary { margin: 1rem 0 0; padding-left: 1.2rem; display: grid; gap: 1rem; }
        ol.itinerary h3 { margin: 0 0 .2rem; font-size: 1rem; font-weight: 700; }
        ol.itinerary p { margin: 0; color: var(--ink-soft); font-size: .93rem; }

        ul.tiers { list-style: none; margin: 1rem 0 0; padding: 0; display: grid; gap: .45rem; font-size: .95rem; }

        /* Departures as chips rather than a column. Twenty dates stacked one to
           a line is a wall a visitor scrolls past; the same twenty in a wrapped
           row can be read at a glance, which is the actual question — "is there
           one on Saturday". */
        ul.departures {
            list-style: none; margin: 1rem 0 0; padding: 0;
            display: flex; flex-wrap: wrap; gap: .5rem;
            font-size: .9rem;
        }

        /* The availability dot, the convention every booking site uses.

           `currentColor` on a per-state colour, so the dot and the words beside
           it cannot drift apart — and the states are set on the `<li>` rather
           than the dot, so a chip can be styled as a whole later without
           re-deciding what colour it is. */
        ul.departures .dot {
            inline-size: .6rem; block-size: .6rem; border-radius: 50%;
            background: currentColor; flex: none; align-self: center;
            /* A soft halo in the same colour. At 8px on white the dot was
               technically present and practically invisible; the ring gives it
               the weight of the thing it is standing for without making the
               chip look like a status badge. */
            box-shadow: 0 0 0 3px color-mix(in srgb, currentColor 18%, transparent);
        }

        ul.departures li.is-open { color: #12A06E; }
        ul.departures li.is-few { color: #C4820A; }
        ul.departures li.is-out { color: #C0392B; }

        /* Only the dot and the state label take the state colour. The date
           itself stays the page's ordinary ink, or a full row of chips becomes
           a row of coloured text. */
        ul.departures .when { color: var(--kaiki-text); }
        ul.departures li.is-out .when { color: var(--ink-faint); text-decoration: line-through; text-decoration-thickness: 1px; }
        ul.departures .few-left { font-size: .8rem; font-weight: 600; }

        ul.departures li {
            display: flex; gap: .5rem; align-items: baseline;
            background: var(--surface);
            border: 1px solid var(--rule);
            border-radius: 999px;
            padding: .4rem .85rem;
        }

        .departures .when { font-variant-numeric: tabular-nums; }
        .departures .sold-out { font-size: .78rem; color: var(--kaiki-accent); letter-spacing: .04em; }

        /* --- FAQ (#103) --------------------------------------------- */

        .faq-list { display: grid; gap: .8rem; }

        .faq-item {
            background: var(--surface); border: 1px solid var(--rule);
            border-radius: var(--kaiki-radius); padding: .9rem 1.1rem;
        }

        /* The whole question is the control, so the tap target is the width of
           the card rather than the width of the words. */
        .faq-item summary {
            cursor: pointer; font-weight: 600; letter-spacing: -.005em;
            list-style: none; display: flex; gap: .8rem; align-items: baseline;
            justify-content: space-between;
        }

        /* Safari draws its own triangle through a pseudo-element the standard
           `list-style: none` above does not reach. */
        .faq-item summary::-webkit-details-marker { display: none; }

        /* A sign that turns into a minus. A glyph rather than the words "open"
           and "close", which would need a case rule I18N-2 forbids on Greek —
           and which the tests assert this stylesheet does not contain. */
        .faq-item summary::after {
            content: '+'; color: var(--kaiki-primary); font-weight: 700;
            font-size: 1.15rem; line-height: 1;
        }
        .faq-item[open] summary::after { content: '−'; }

        .faq-item .prose { margin-top: .7rem; color: var(--ink-soft); }

        /* --- the contact block, as a banner ---------------------------
           Full-bleed like the hero, and for the same reason: it is the end of
           the page, and a bordered card there reads as one more item rather
           than as a close.

           Two columns on a wide screen — what to say on the left, how to reach
           them on the right — because a single column of four short facts at the
           bottom of a 78rem page reads as a leftover list. */
        .block.contact {
            /* A contained, rounded panel rather than a full-bleed band. The
               hero is the page's one edge-to-edge element; a second one at the
               bottom made the page read as two banners with the content
               squeezed between them.

               Generous vertical room on purpose: this is the end of the page and
               the last thing a visitor reads before deciding to ring somebody.
               A panel with tight padding reads as a form; one with space reads
               as an invitation. */
            padding: clamp(2.5rem, 6vw, 5rem) clamp(1.4rem, 4vw, 3.5rem);
            border-radius: 24px;
            overflow: hidden;
            position: relative;
            isolation: isolate;
            background: color-mix(in srgb, var(--kaiki-primary) 6%, var(--surface));
        }

        .block.contact > *:not(.contact-image) {
            position: relative;
            z-index: 2;
        }

        @media (max-width: 40rem) {
            .block.contact { border-radius: 18px; }
        }

        .block.contact h2 { max-width: 18ch; margin-bottom: 1rem; font-size: var(--step-3); }
        .block.contact .prose { max-width: 32rem; margin-bottom: 0; }

        /* A way to act, not only an address. The panel is the last thing on the
           page and a visitor who has read this far wants to press something. */
        .contact-actions { margin: 1.75rem 0 0; display: flex; flex-wrap: wrap; gap: .75rem; }
        /* No white-on-photograph overrides any more — the words sit on the
           light half of the panel and inherit the page's ordinary colours. */

/* --- the contact panel, rebuilt -------------------------------
           It was words over a photograph behind an 86% wash of the operator's
           primary — the same mistake as the hero, in a smaller box and worse,
           because a panel this size has no room for a gradient to recover in.
           The picture of the quay a guest is being asked to walk to was a teal
           smear behind an address.

           It is a split panel now: the photograph on one side at full strength,
           the words on the other on a plain tinted surface. Nothing overlaps, so
           nothing needs a scrim, so the photograph keeps its own colours and the
           text sits on a background chosen for contrast rather than negotiated
           against a photograph nobody controls.

           One column below 52rem, picture first — on a phone the panel is the
           height of the screen either way, and a picture that has been squeezed
           into a 6rem strip is worth less than the space it costs. */
        .block.contact.has-image {
            display: grid;
            grid-template-columns: 1fr;
            gap: clamp(1.5rem, 4vw, 3rem);
            padding: 0;
            background: color-mix(in srgb, var(--kaiki-primary) 7%, var(--surface));
            color: var(--kaiki-text);
        }

        .block.contact.has-image > *:not(.contact-image) {
            padding-inline: clamp(1.4rem, 4vw, 3.5rem);
        }

        .block.contact.has-image > *:not(.contact-image):first-of-type { padding-block-start: clamp(2rem, 5vw, 3.5rem); }
        .block.contact.has-image > *:not(.contact-image):last-child { padding-block-end: clamp(2rem, 5vw, 3.5rem); }

        .block.contact.has-image .contact-image {
            grid-row: 1;
            width: 100%;
            height: 100%;
            min-height: 14rem;
            object-fit: cover;
            object-position: center center;
            display: block;
        }

        @media (min-width: 52rem) {
            .block.contact.has-image {
                grid-template-columns: 1fr 1fr;
                align-items: center;
            }

            /* The picture is the full height of the panel on its own side, and
               the words keep their own padding on theirs. */
            .block.contact.has-image .contact-image {
                grid-column: 2;
                grid-row: 1 / -1;
                align-self: stretch;
                min-height: 100%;
            }

            .block.contact.has-image > *:not(.contact-image) { grid-column: 1; }
            .block.contact.has-image > *:not(.contact-image):first-of-type { padding-block-start: clamp(2.5rem, 5vw, 4rem); }
            .block.contact.has-image > *:not(.contact-image):last-child { padding-block-end: clamp(2.5rem, 5vw, 4rem); }
        }

        /* The white-on-photograph overrides that used to live here are gone
           with the scrim they were written for: the words are on the light half
           of the panel now and inherit the page's own colours, which is the
           point of splitting it. */

        .contact-list {
            list-style: none; margin: 2rem 0 0; padding: 0;
            display: grid; gap: 1.5rem;
        }

        /* No rules between the rows. Four labelled facts with air around them
           read as an address card; the same four in a ruled table read as a
           settings screen. */
        .contact-list li { display: grid; gap: .2rem; }

        .contact-list .label {
            font-size: .72rem; font-weight: 600; letter-spacing: .1em;
            color: var(--ink-faint);
        }

        .contact-list li > span:not(.label),
        .contact-list a { font-size: var(--step-1); font-weight: 600; line-height: 1.3; }

        .contact-list .instructions { font-size: .88rem; font-weight: 400; color: var(--ink-faint); }

        /* The two columns. Below this width they stack, and the list keeps its
           rules — a phone reads a list of labelled rows perfectly well. */
        @media (min-width: 52rem) {
            .block.contact .contact-inner {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 3rem;
                align-items: start;
            }

            .block.contact .contact-inner > * { min-width: 0; }
            .contact-list { margin-top: 0; }

            /* …but not when the photograph has already taken half the panel.
               Two columns inside one half is four columns of prose across a
               panel, and «Πού θα μας βρείτε» came out two words to a line with
               an email address wrapped mid-domain beside it. With a picture,
               the words stack. */
            .block.contact.has-image .contact-inner {
                grid-template-columns: 1fr;
                gap: 1.75rem;
            }

            .block.contact.has-image h2 { max-width: none; }
        }


        /* --- footer --- */

        footer.site {
            background: var(--surface); border-top: 1px solid var(--rule);
            padding-block: 2rem 2.5rem; font-size: .88rem; color: var(--ink-soft);
        }

        footer.site .cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); gap: 1.5rem; }
        footer.site h3 { font-size: .74rem; font-weight: 600; letter-spacing: .09em; color: var(--ink-faint); margin: 0 0 .5rem; }
        footer.site p { margin: 0 0 .3rem; }
        footer.site ul { margin: 0; padding: 0; list-style: none; }
        footer.site li { margin-bottom: .3rem; }

        .powered {
            margin-top: 1.75rem; padding-top: 1rem; border-top: 1px solid var(--rule);
            font-size: .8rem; color: var(--ink-faint);
        }
    </style>
</head>
<body>

<header class="site">
    <div class="wrap">
        <a class="brand" href="{{ route('hosted.index', ['operator' => $tenant->slug, 'lang' => $locale]) }}">
            @if ($logo)
                <img src="{{ $logo }}" alt="{{ $tenant->name }}">
            @else
                <span class="name">{{ $tenant->name }}</span>
            @endif
        </a>

        {{-- The search of #105, reachable from every hosted page. A feature a
             guest cannot find is a feature the operator paid for and nobody
             uses. --}}
        <nav class="site-nav">
            <a href="{{ route('hosted.search', ['operator' => $tenant->slug, 'lang' => $locale]) }}">{{ __('hosted.search.nav') }}</a>
        </nav>

        <nav class="langs" aria-label="{{ __('hosted.nav.language') }}">
            @foreach (['el' => 'ΕΛ', 'en' => 'EN'] as $code => $label)
                <a href="{{ $alternates[$code] }}"
                   hreflang="{{ $code }}"
                   @if ($code === $locale) aria-current="true" @endif>{{ $label }}</a>
            @endforeach
        </nav>
    </div>
</header>

<main>
    <div class="wrap">
        @yield('content')
    </div>
</main>

<footer class="site">
    <div class="wrap">
        <div class="cols">
            {{-- HOS-9: the operator's legal identity, in both locales. --}}
            <div>
                <h3>{{ __('hosted.footer.operator') }}</h3>
                <p><strong>{{ $tenant->legal_name ?? $tenant->name }}</strong></p>
                @if ($tenant->address_line1)
                    <p>{{ $tenant->address_line1 }}</p>
                @endif
                @if ($tenant->city || $tenant->postcode)
                    <p>{{ trim($tenant->postcode . ' ' . $tenant->city) }}</p>
                @endif
                @if ($tenant->vat_number)
                    <p>{{ __('hosted.footer.vat_number') }}: {{ $tenant->vat_number }}</p>
                @endif
                @if ($tenant->tax_office)
                    {{-- No label of its own: `taxOfficeName()` already reads «ΔΟΥ Πειραιά», and the footer was printing «ΔΟΥ: ΔΟΥ Πειραιά». --}}
                    <p>{{ $tenant->taxOfficeName() }}</p>
                @endif
            </div>

            <div>
                <h3>{{ __('hosted.footer.contact') }}</h3>
                <p><a href="mailto:{{ $tenant->email }}">{{ $tenant->email }}</a></p>
                @if ($tenant->phone)
                    <p><a href="tel:{{ $tenant->phone }}">{{ $tenant->phone }}</a></p>
                @endif
            </div>

            <div>
                <h3>{{ __('hosted.footer.legal') }}</h3>
                <ul>
                    <li><a href="{{ route('hosted.legal', ['operator' => $tenant->slug, 'lang' => $locale]) }}">{{ __('hosted.footer.terms') }}</a></li>
                    <li><a href="{{ route('hosted.legal', ['operator' => $tenant->slug, 'lang' => $locale]) }}#privacy">{{ __('hosted.footer.privacy') }}</a></li>
                    <li><a href="{{ route('hosted.legal', ['operator' => $tenant->slug, 'lang' => $locale]) }}#cancellation">{{ __('hosted.footer.cancellation') }}</a></li>
                </ul>
            </div>
        </div>

        @if ($poweredBy)
            {{-- Brand decision 6: always, custom domains included. From a flag,
                 so a white-label tier is a config change rather than an edit. --}}
            <p class="powered">{{ __('hosted.footer.powered_by') }}</p>
        @endif
    </div>
</footer>

</body>
</html>
