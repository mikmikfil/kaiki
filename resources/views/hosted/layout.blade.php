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

        .trip-image img { width: 100%; height: 100%; object-fit: cover; display: block; }

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
        li.trip .facts { margin: 0 0 1.1rem; color: var(--ink-faint); font-size: .84rem; }

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
        .trips-rail { scrollbar-width: thin; scrollbar-color: color-mix(in srgb, var(--kaiki-primary) 40%, transparent) transparent; }

        .trips-more {
            margin: 3.5rem 0 1.6rem;
            font-size: var(--step-2);
            color: var(--ink-soft);
            font-weight: 600;
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

        .result-count { color: var(--ink-faint); font-size: .88rem; margin: 0 0 1rem; }

        /* The price row is pinned to the bottom of the card, so a row of cards
           has its prices on one line — settled on 4 September, because prices at
           different heights read as a mistake. */
        .party-price { margin: auto 0 0; padding-top: .7rem; display: flex; flex-wrap: wrap; align-items: baseline; gap: .4rem; }
        .party-price strong { font-size: 1.25rem; letter-spacing: -.02em; }
        .party-price .for-party { font-size: .85rem; color: var(--ink-soft); }
        .party-price .vat { flex-basis: 100%; font-size: .78rem; color: var(--ink-faint); }
        .party-price .on-request { font-weight: 600; color: var(--kaiki-primary); }

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

        /* …and that rhythm is for **sections**. The search page's children are
           not sections: a heading, a form, a count and a grid of results are one
           thing, and giving each pair a section-sized gap spread them 114, 129
           and 105 pixels apart — three different large voids inside what a
           visitor reads as a single block, on the page where they are waiting
           for an answer.

           Keyed on the absence of `.block` children rather than on a class the
           search page would have to remember to carry. The form's own bottom
           margin goes with it: it was there to separate the form from what
           follows, and the gap does that now. */
        main .wrap:not(:has(> .block)) { gap: 1.75rem; }
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
            min-height: min(72vh, 40rem);
        }

        .hero.has-image .hero-image,
        .hero.has-image .hero-video {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 0;
            /* Above the section's own background colour, which is the fallback
               while the photograph loads, and below the scrim. */
            z-index: 0;
        }

        /* A scrim weighted to the bottom, where the type is: white on a bright
           noon photograph of a white hull is unreadable otherwise. Built from
           the operator's own primary rather than from black, so the hero still
           looks like their brand. */
        .hero.has-image::after {
            content: '';
            position: absolute;
            inset: 0;
            z-index: 1;
            background: linear-gradient(
                to bottom,
                color-mix(in srgb, var(--kaiki-primary) 25%, transparent) 0%,
                color-mix(in srgb, var(--kaiki-primary) 55%, transparent) 45%,
                color-mix(in srgb, var(--kaiki-primary) 88%, transparent) 100%
            );
        }

        .hero.has-image .hero-copy { padding-block: 5rem 3.5rem; }
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

        /* And the rule that divided them from the price goes with them. A
           hairline is a separator between two things; with the four lines
           hidden it became the first mark in the card, drawn above nothing. */
        .booking:has(.mount > [data-kaiki-widget]) > .price { border-top: 0; padding-top: 0; }

        .story { display: grid; gap: 2rem; align-items: center; }
        .story-image { width: 100%; height: auto; border-radius: 14px; object-fit: cover; aspect-ratio: 4 / 3; }
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
            .story.has-image { grid-template-columns: 2fr 1fr; }
            /* The ratio follows the content, not the position. `order` moves
               the image into the first column, so without this the photograph
               inherits the wide column meant for the prose and the two swap
               sizes as well as sides — the picture becomes the subject and the
               paragraphs are squeezed into a third of the row. */
            .story.side-left.has-image { grid-template-columns: 1fr 2fr; }
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
        .gallery .shots img { width: 100%; height: 100%; border-radius: 10px; object-fit: cover; aspect-ratio: 3 / 2; }

        /* --- product page (#104) ------------------------------------ */

        /* A visible label for a screen reader and nobody else. The booking
           area needs a heading in the outline (A11Y) and does not want one on
           the page, because the four lines below it say what it is. */
        .sr-only {
            position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
            overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0;
        }

        /* The crumbs sit directly above the page and are part of it, not
           another block. Without this they inherit the home page's
           block rhythm — six rems of nothing between a breadcrumb and the
           photograph it belongs to. */
        .crumbs {
            font-size: .85rem; color: var(--ink-faint);
            display: flex; gap: .45rem; align-items: baseline;
            margin-bottom: calc(clamp(3.5rem, 7vw, 6rem) * -1 + 1.25rem);
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

           One column below 60rem, and the booking card comes **first** there —
           on a phone the thing a visitor came to do should not be under the
           whole page. */
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
        ul.facts {
            list-style: none; margin: 0; padding: 0;
            display: flex; flex-wrap: wrap; gap: .4rem .9rem;
            font-size: .86rem; color: var(--ink-faint); letter-spacing: .01em;
        }
        ul.facts li + li::before { content: '·'; margin-right: .9rem; color: var(--rule); }
        ul.facts { font-size: .92rem; }

        .shots { list-style: none; margin: 0; padding: 0; display: grid; gap: 1rem;
                 grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr)); }
        .shots img { width: 100%; height: 100%; border-radius: 14px; object-fit: cover; aspect-ratio: 3 / 2; display: block; }

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

        .price { margin: 0 0 1.3rem; padding-top: 1.1rem; border-top: 1px solid var(--rule); display: flex; flex-wrap: wrap; align-items: baseline; gap: .5rem; }
        .price .from { font-size: .85rem; color: var(--ink-faint); }
        .price strong { font-size: var(--step-3); letter-spacing: -.025em; }
        .price .vat { font-size: .82rem; color: var(--ink-faint); flex-basis: 100%; }

        /* What the card carries besides the four lines and the price: capacity,
           the boat, who pays what, the first of the includes, and the
           cancellation window. All of it is further down the page as well —
           it is here because a visitor deciding whether to press the button is
           asking exactly these questions, and scrolling away from the button to
           answer them is how a booking is abandoned.

           Each row is an icon column and a text column. The icon is fixed at
           `1.15rem` and aligned to the first line rather than centred, so a row
           whose value wraps to three lines keeps its icon beside the label. */
        .booking-extra {
            margin: 1.4rem 0 0; padding-top: 1.35rem;
            border-top: 1px solid var(--rule);
            display: grid; gap: 1.05rem;
            font-size: .92rem;
        }

        .booking-extra .extra { display: grid; grid-template-columns: 1.15rem 1fr; gap: .8rem; align-items: start; }
        .booking-extra .extra > div { display: grid; gap: .22rem; min-width: 0; }

        .booking-extra .icon {
            width: 1.15rem; height: 1.15rem;
            color: var(--kaiki-primary);
            margin-top: .1rem;
        }

        .booking-extra .label {
            font-size: .72rem; font-weight: 600; letter-spacing: .08em; color: var(--ink-faint);
        }

        .booking-extra .muted { color: var(--ink-faint); font-size: .86rem; }

        .booking-extra ul { list-style: none; margin: 0; padding: 0; display: grid; gap: .3rem; }
        .booking-extra ul li { padding-left: 1.2rem; position: relative; color: var(--ink-soft); }
        .booking-extra ul li::before { position: absolute; left: 0; content: '✓'; color: var(--kaiki-primary); }
        .booking-extra .more { color: var(--ink-faint); font-size: .85rem; }

        /* The age bands are a list of pairs, not ticks: the marker in front of
           each one would read as "this is included", which is the opposite of
           what a band that pays nothing and takes no seat means. */
        .booking-extra ul.bands { gap: .35rem; margin-top: .1rem; }
        .booking-extra ul.bands li { padding-left: 0; display: flex; flex-wrap: wrap; gap: .45rem; align-items: baseline; }
        .booking-extra ul.bands li::before { content: none; }
        .booking-extra ul.bands strong { color: var(--kaiki-text); font-weight: 600; }

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
        .block.contact.has-image .contact-actions .button { background: #fff; border-color: #fff; color: var(--kaiki-primary); }
        .block.contact.has-image .contact-actions .button.ghost { background: transparent; border-color: rgba(255, 255, 255, .55); color: #fff; }

        .block.contact.has-image { color: #fff; background: var(--kaiki-primary); }

        .block.contact .contact-image {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            z-index: 0;
        }

        /* Weighted to the left, where the words are, and thinning towards the
           right so the photograph is still visible behind the details. */
        .block.contact.has-image::after {
            content: '';
            position: absolute;
            inset: 0;
            z-index: 1;
            background: linear-gradient(
                100deg,
                color-mix(in srgb, var(--kaiki-primary) 86%, transparent) 0%,
                color-mix(in srgb, var(--kaiki-primary) 66%, transparent) 55%,
                color-mix(in srgb, var(--kaiki-primary) 40%, transparent) 100%
            );
        }

        .block.contact.has-image a,
        .block.contact.has-image .label,
        .block.contact.has-image .instructions { color: #fff; }

        .block.contact.has-image .prose { color: rgba(255, 255, 255, .9); }
        .block.contact.has-image .contact-list { border-color: rgba(255, 255, 255, .28); }
        .block.contact.has-image .contact-list li { border-color: rgba(255, 255, 255, .18); }

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

        .block.contact.has-image .contact-list .label { color: rgba(255, 255, 255, .72); }
        .block.contact.has-image .contact-list .instructions { color: rgba(255, 255, 255, .72); }

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
