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
    $primary = $colors['primary'] ?? '#123A5E';
    $accent = $colors['accent'] ?? '#B5511F';
    $text = $colors['text'] ?? '#131A22';
    // The other two of WGT-9's five. The page had no use for them itself —
    // its own surfaces are `--surface` and `--paper` — so it never printed
    // them, and that was harmless until `data-branding="inherit"` made the
    // widget read its colours from here instead of fetching them. Missing,
    // `--kaiki-background` left every surface inside the widget transparent.
    $secondary = $colors['secondary'] ?? $primary;
    $background = $colors['background'] ?? '#FFFFFF';
    $radius = $brand['button_radius_px'] ?? 10;
    $fontCss = $brand['font']['css_url'] ?? null;
    $fontFamily = $brand['font']['family'] ?? 'Inter';
    $logo = $brand['logo']['light_url'] ?? null;
    // The footer is dark: the logo made for a dark background when there is
    // one («Λογότυπο για σκούρο φόντο», the light-coloured version), else the
    // same one as the header (Mike, 25/9: the footer showed the dark logo).
    $footLogo = ($brand['logo']['dark_url'] ?? null) ?: $logo;
@endphp
<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- The operator's own icon from «Εμφάνιση», else Kaiki's. The page had
         none, so browsers showed whatever `/favicon.ico` held (2026-09-17). --}}
    <link rel="icon" href="{{ $brand['logo']['favicon_url'] ?? null ?: asset('favicon.svg') }}">

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
    @else
        {{-- Inter is ours and same-origin (2026-09-24): the two scripts every
             Greek page needs, asked for before the stylesheet is parsed. --}}
        <link rel="preload" href="/fonts/inter/inter-greek-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
        <link rel="preload" href="/fonts/inter/inter-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
    @endif

    <style nonce="{{ $nonce }}">
        @include('partials.inter-font-face')

        :root {
            --kaiki-primary: {{ $primary }};
            --kaiki-secondary: {{ $secondary }};
            --kaiki-accent: {{ $accent }};
            --kaiki-background: {{ $background }};
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
        /* 1400px of content, the gutters on top (Mike, 2026-09-24; it was 86rem,
           then 92, then 1450px). Every hosted page shares it. */
        .wrap { max-width: calc(1400px + 5rem); margin: 0 auto; padding: 0 clamp(1.25rem, 3vw, 2.5rem); }

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

        .site-nav {
            margin-left: auto; margin-right: .35rem; font-size: .9rem;
            display: flex; gap: 1.15rem;
        }
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

        /* --- the burger -----------------------------------------------
           The header on a phone, 14 September. One flex row with four things in
           it had nowhere to go at 390px: the operator's name broke onto three
           lines, «Βρείτε εκδρομή» onto two, and the header took 310px — a third
           of the screen — before the page had said anything. An operator with a
           longer name than this one fared worse, and most of them have one.

           **The language switch stays out of the burger.** Brand decision 2 of
           2026-09-04 asks for a visible ΕΛ/EN on every guest surface, and a
           switch folded behind a menu is not visible. It is also the control a
           visitor reaches for in the first two seconds, before they want a menu
           at all. So the row on a phone is: name, ΕΛ/EN, burger.

           Each of the two navigations is `display: none` where it does not
           belong, which is what keeps the copy that is not on screen out of the
           tab order and out of the accessibility tree. See
           `partials/nav-links.blade.php` for why there are two. */

        details.menu { display: none; }

        @media (max-width: 40rem) {
            header.site .wrap {
                gap: .65rem;
                padding-block: .85rem;
            }

            .site-nav { display: none; }

            /* The name takes the slack and gives it back: it may shrink below
               its content, and it ellipses rather than wrapping. A masthead
               three lines deep is worse than a truncated one, and a logo — the
               usual case — is unaffected either way. */
            .brand { min-width: 0; flex: 1 1 auto; }
            .brand .name { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

            .langs { flex: 0 0 auto; margin-left: auto; }

            /* `position: relative` is on the header rather than here so the
               panel can span the full width of the screen instead of the width
               of a 44px button. */
            details.menu { display: block; flex: 0 0 auto; }

            details.menu > summary {
                display: grid;
                place-items: center;
                /* 44px: the smallest target a thumb hits reliably, and the size
                   the language switch beside it already is. */
                inline-size: 2.75rem;
                block-size: 2.75rem;
                border: 1px solid var(--rule);
                border-radius: var(--kaiki-radius);
                color: var(--ink-soft);
                cursor: pointer;
                /* The disclosure triangle. `list-style` covers Firefox and
                   Chrome, `::-webkit-details-marker` the Safari that ignores
                   it. */
                list-style: none;
            }
            details.menu > summary::-webkit-details-marker { display: none; }
            details.menu > summary:focus-visible {
                outline: 2px solid var(--kaiki-primary);
                outline-offset: 2px;
            }
            details.menu > summary .icon { inline-size: 1.35rem; block-size: 1.35rem; }

            /* Three bars into a cross. The middle bar fades, the outer two
               rotate onto each other — and the transition is declared only where
               motion is welcome. */
            details.menu .bar { transform-origin: center; }
            @media (prefers-reduced-motion: no-preference) {
                details.menu .bar { transition: transform .18s ease, opacity .18s ease; }
            }
            details.menu[open] > summary {
                background: var(--kaiki-primary);
                border-color: var(--kaiki-primary);
                color: #fff;
            }
            details.menu[open] .bar.mid { opacity: 0; }
            details.menu[open] .bar.top { transform: translateY(6px) rotate(45deg); }
            details.menu[open] .bar.bot { transform: translateY(-6px) rotate(-45deg); }

            /* The panel. Absolute rather than in the flow, so opening it moves
               the page's content down by nothing — a header that changes height
               when a menu opens pushes the thing the visitor was reading off the
               screen. */
            header.site { position: relative; z-index: 30; }

            .menu-panel {
                position: absolute;
                inset-inline: 0;
                top: 100%;
                display: flex;
                flex-direction: column;
                font-size: 1rem;
                background: var(--surface);
                border-bottom: 1px solid var(--rule);
                box-shadow: 0 12px 28px rgba(6, 16, 20, .12);
                padding: .35rem clamp(1.25rem, 3vw, 2.5rem) .85rem;
            }

            /* Full-width rows, not a stack of short links: the whole row is the
               target, which is what a thumb expects of a menu. */
            .menu-panel a {
                padding-block: .85rem;
                border-top: 1px solid var(--rule);
                text-decoration: none;
                color: var(--ink);
            }
            .menu-panel a:first-child { border-top: 0; }
            .menu-panel a:hover { color: var(--kaiki-primary); }
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

        /* --- a light fade as things arrive ------------------------------

           Scroll-driven CSS, not a script: `animation-timeline: view()` ties
           the animation to the element's own position in the viewport, so the
           browser runs it off the main thread and these pages keep their
           no-JavaScript guarantee (HOS-4).

           Three guards, and each one matters:

           - `@supports` — where the timeline is not implemented no rule is
             applied at all, so the content is simply *there*. It must never be
             possible for an unsupported browser to be left holding
             `opacity: 0`, which is how this effect usually breaks.
           - `prefers-reduced-motion` — movement on scroll is a vestibular
             trigger, and the operating system already knows the answer.
           - `animation-range: entry` — an element already on screen at load
             starts at the end of its own animation rather than fading in after
             the page has settled, so the hero and the first cards do not
             flicker.

           It is deliberately small: 10px and a fade. A page of boat trips
           should feel calm, and anything larger reads as a template. */
        @keyframes kaiki-rise {
            from { opacity: 0; translate: 0 10px; }
            to { opacity: 1; translate: 0 0; }
        }

        @supports (animation-timeline: view()) {
            @media (prefers-reduced-motion: no-preference) {
                .block > h2,
                .block > .block-head,
                li.trip,
                .story-image,
                .story-copy,
                .faq-item,
                .contact-inner > * {
                    animation: kaiki-rise linear both;
                    animation-timeline: view();
                    animation-range: entry 0% entry 32%;
                }
            }
        }

        /* «Έχετε απορίες;» — direction Α of the 22 September mockup: one more
           row of the FAQ list rather than a card that was moved into a column
           it was not drawn for.

           It takes the list's own width, corner and hairline, and keeps two
           differences, both of which mean something: it is filled rather than
           white, and its mark is a solid disc rather than the «+» of a
           question. It is the last line of the list — the one that is not a
           question but the answer to all the ones the operator did not write.

           What it stopped being: a shadowed card at the width of the booking
           column, sitting in a 46rem column of prose with its buttons stranded
           on the left. Position is what separates it from its neighbours now,
           not weight. */
        .ask {
            margin-block-start: .6rem;
            display: grid;
            grid-template-columns: auto 1fr;
            gap: .1rem 1rem;
            align-items: center;
            /* Taller than a question's row on purpose (Mike, 2026-09-22): it
               carries three things where they carry one, and the row it closes
               the list with should not read as cramped. */
            padding: 1.6rem 1.35rem;
            border: 1px solid var(--line, var(--rule));
            border-radius: 16px;
            background: var(--mist, var(--surface));
        }

        /* Under the address on its own line, and small. It was briefly beside
           it — the row read tidily and put the one action on this tab up
           against the right edge of a column whose every other line starts on
           the left, so it came back down. The size stayed. */
        .map-open { margin-block-start: .9rem; }

        .button.small {
            padding-block: .4rem; padding-inline: .75rem;
            font-size: .8rem; gap: .4rem;
        }

        .button.small .icon { inline-size: .9rem; block-size: .9rem; }

        /* The disc, where a question in this list has its «+». It spans the
           three rows beside it, so the heading, the sentence and the buttons
           all start on one line down the column. */
        .ask > .icon {
            grid-row: span 3;
            /* Level with the heading rather than with the middle of the block:
               centred against three rows it sat beside the sentence, pointing
               at nothing. */
            align-self: start;
            margin-block-start: .05rem;
            box-sizing: border-box;
            inline-size: 1.75rem; block-size: 1.75rem; padding: .4rem;
            border-radius: 50%;
            background: var(--kaiki-primary); color: #fff;
            display: block; margin: 0;
        }

        .ask h2 { font-size: 1rem; margin: 0; color: var(--deep, var(--kaiki-text)); }
        .ask p { grid-column: 2; margin: .1rem 0 0; color: var(--ink-soft); font-size: .88rem; line-height: 1.45; }

        .ask-actions {
            grid-column: 2;
            margin-block-start: .85rem !important;
            display: flex; flex-wrap: wrap; gap: .5rem;
        }

        /* Not `flex: 1 1 auto` any more: in a 23rem column the two buttons
           filling the row was the tidy answer, and in a column of prose it
           stretched «Πάρτε τηλέφωνο» to twenty-three centimetres. */
        .ask-actions .button {
            padding-block: .5rem; padding-inline: .9rem; font-size: .84rem;
        }

        /* On a phone the disc leaves the row: 1.75rem of it plus a gap is a
           sixth of the screen taken off every line of the sentence beside it. */
        @media (max-width: 30rem) {
            .ask { grid-template-columns: 1fr; gap: .1rem; }
            .ask > .icon { grid-row: auto; margin-block-end: .55rem; }
            .ask p, .ask-actions { grid-column: 1; }
            .ask-actions .button { flex: 1 1 auto; justify-content: center; }
        }

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
        .pager-step:hover { color: color-mix(in srgb, var(--kaiki-primary) 80%, var(--ink)); }
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
        /* The trip page's own sections get the same mark. «Για την εκδρομή»,
           «Φωτογραφίες» and the rest were the only headings on the site without
           one, so the FAQ block sitting among them looked like a different
           kind of section rather than one more of the same. */
        .product-main .section > h2::after,
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
            /* Raised from 60vh/32rem on 14 September. Kept a step below the
               photographed hero below, so a page with no picture still reads as
               the shorter of the two rather than as a taller empty band. */
            min-height: min(68vh, 38rem);
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
            max-width: calc(1400px + 5rem);
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
            /* 86vh originally, then 74vh, and 84vh from 14 September — asked
               for, with the cost stated rather than discovered later: at 74vh
               the trips below started at 841px and a 900px laptop showed their
               top edge; at 84vh they start at 931px and the first scroll is the
               one that reveals them. The search card in the hero is what makes
               that trade payable — a visitor with a date in mind never needs to
               reach the cards at all. */
            min-height: min(84vh, 50rem);
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

        /* A video framed from YouTube or Vimeo, made to behave like the two
           above it. Same layer: over the operator's colour and the photograph
           that stands in for it, under the scrim. */
        .hero.has-image .hero-embed {
            position: absolute;
            inset: 0;
            z-index: 0;
            overflow: hidden;
            /* It has no controls and is not in the tab order, so it should not
               swallow a drag-scroll on a phone either. */
            pointer-events: none;
        }

        /* `object-fit: cover` does nothing to an iframe — the player is not a
           replaced image, it letterboxes itself inside whatever box it is
           given. So the box is made bigger than the hero in whichever direction
           is short of sixteen by nine and centred, and the overflow is clipped:
           the same crop the photograph gets, arrived at by other means. */
        .hero.has-image .hero-embed iframe {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: max(100%, 177.78vh);
            height: max(100%, 56.25vw);
            border: 0;
        }

        /* The one lever an iframe leaves. An uploaded file can be given or
           refused its autoplay attribute in the markup; a framed player cannot,
           so for a visitor who asked for less motion the frame is not shown at
           all and the photograph underneath is the hero. */
        @media (prefers-reduced-motion: reduce) {
            .hero.has-image .hero-embed { display: none; }
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

        .hero.has-image .hero-copy { padding-block: 6rem 3.5rem; }
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
            /* The accent, on every solid button (Mike, 2026-09-24: «αυτό το
               χρώμα για τα buttons παντού»). It was the primary navy, with the
               accent kept for the two or three calls to book. */
            background: var(--kaiki-accent); color: #fff;
            border: 1px solid var(--kaiki-accent);
            padding: .8rem 1.5rem; border-radius: var(--kaiki-radius);
            font-weight: 600; font-size: .97rem; line-height: 1;
            transition: background-color .15s ease, border-color .15s ease, color .15s ease;
        }

        /* Hover deepens the button rather than recolouring it. The brand navy
           stays the brand navy: a button that turns a different colour under
           the pointer reads as a warning on a page where the accent is used for
           «sold out» and «not included». */
        .button:hover, .button:focus-visible {
            background: color-mix(in srgb, var(--kaiki-accent) 86%, #000);
            border-color: transparent;
        }

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
        .booking:has(.mount > [data-kaiki-widget]) > .card-facts { display: none; }

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

        /* --- the trip hero ---------------------------------------------
           A way back, the title, the standfirst, the facts as chips and the
           photographs as one mosaic, full width above the two columns. The same
           structure as the WordPress plugin's single-trip template, so a trip
           reads the same on both.

           The gaps are the hero's own, set on the hero: see the note on
           `.crumbs` for what a child hard-coding its container's gap costs. */
        .trip-hero { display: flex; flex-direction: column; min-width: 0; }
        .trip-hero .crumbs { margin-block-end: 1rem; }

        /* **Μικρότερος, και ακριβώς πάνω από τις φωτογραφίες** (Mike,
           2026-09-23). Ο τίτλος της σελίδας δεν χρειάζεται το μέγεθος του
           `--step-4` όταν αμέσως από κάτω του υπάρχει μια φωτογραφία που
           τραβάει ούτως ή άλλως το μάτι — και ο τίτλος μιας εκδρομής είναι
           συχνά μακρύς, οπότε στο `--step-4` έπιανε τρεις γραμμές σε τηλέφωνο
           και έσπρωχνε τα πάντα κάτω από τη μέση. */
        .trip-hero h1 { font-size: var(--step-3); font-weight: 800; letter-spacing: -.028em; margin: 0; }

        /* Ο υπότιτλος και τα εικονίδια κατέβηκαν κάτω από το μωσαϊκό **και
           μέσα στην αριστερή στήλη** — γι' αυτό ο επιλογέας δεν είναι πια
           `.trip-hero`. Εκεί είναι που ανεβαίνει το κουτί κράτησης: οι δύο
           στήλες ξεκινούν μόλις τελειώσουν οι φωτογραφίες. */
        .trip-intro { display: flex; flex-direction: column; gap: .9rem; align-items: flex-start; }
        .trip-intro .standfirst { color: var(--ink-soft); font-size: var(--step-1); max-width: 44rem; margin: 0; }

        /* The mosaic. One photograph large on the left across both rows, up to
           four beside it in a two-by-two block; with three photographs, one
           large and two stacked. Every tile is a crop (`object-fit: cover`) of a
           photograph whose whole frame is one tap away in the lightbox. */
        .mosaic { position: relative; margin-block-start: clamp(1.5rem, 3vw, 2rem); }

        .mosaic ul {
            list-style: none; margin: 0; padding: 0;
            display: grid; gap: 10px;
            block-size: clamp(300px, 44vw, 520px);
            grid-template-rows: 1fr 1fr;
        }

        .mosaic-5 ul { grid-template-columns: 2fr 1fr 1fr; }
        .mosaic-3 ul { grid-template-columns: 2fr 1fr; }
        .mosaic-2 ul { grid-template-columns: 2fr 1fr; grid-template-rows: 1fr; }
        .mosaic-1 ul { grid-template-columns: 1fr; grid-template-rows: 1fr; }

        .mosaic li { min-width: 0; min-height: 0; }
        .mosaic li:first-child { grid-row: 1 / -1; }

        .mosaic .shot-open {
            display: block; inline-size: 100%; block-size: 100%;
            border-radius: 14px; overflow: hidden;
            background: color-mix(in srgb, var(--kaiki-primary) 8%, var(--surface));
        }

        .mosaic .shot-open:focus-visible { outline: 2px solid var(--kaiki-primary); outline-offset: 3px; }

        .mosaic img {
            display: block; inline-size: 100%; block-size: 100%;
            object-fit: cover;
            transition: scale .4s ease;
        }

        @media (hover: hover) and (prefers-reduced-motion: no-preference) {
            .mosaic .shot-open:hover img { scale: 1.03; }
        }

        /* «All photographs (N)», over the last tile. A control, so a control's
           radius; on the photograph, so a solid white ground rather than a
           tint the picture behind could swallow. */
        .mosaic-all {
            position: absolute; inset-block-end: 14px; inset-inline-end: 14px;
            padding: .55rem .95rem; border-radius: 10px;
            background: var(--surface); color: var(--kaiki-text);
            font-size: .875rem; font-weight: 600; text-decoration: none;
            box-shadow: 0 2px 12px rgba(6, 16, 20, .18);
        }

        .mosaic-all:hover { color: var(--kaiki-primary); }
        .mosaic-all:focus-visible { outline: 2px solid var(--kaiki-primary); outline-offset: 2px; }

        /* Six photographs or more need the button everywhere; four or five only
           where the phone hides the tiles they are on. */
        @media (min-width: 47.5rem) { .mosaic-all-narrow { display: none; } }

        /* A phone: the large photograph full width on top, two small ones
           side by side under it, and the rest in the lightbox. */
        @media (max-width: 47.49rem) {
            .mosaic ul,
            .mosaic-5 ul,
            .mosaic-3 ul,
            .mosaic-2 ul,
            .mosaic-1 ul {
                block-size: auto;
                grid-template-columns: 1fr 1fr;
                grid-template-rows: none;
                gap: 8px;
            }

            .mosaic li:first-child { grid-row: auto; grid-column: 1 / -1; aspect-ratio: 4 / 3; }
            .mosaic li { aspect-ratio: 1 / 1; }
            .mosaic-2 li:nth-child(2) { grid-column: 1 / -1; aspect-ratio: 16 / 9; }
            .mosaic li:nth-child(n+4) { display: none; }
            .mosaic .shot-open { border-radius: 12px; }
        }

        /* --- the two-column body -------------------------------------
           What the trip is on the left, how to book it on the right, and the
           booking card sticky so it is still on screen at the bottom of the
           itinerary. A booking form below three screens of prose is a booking
           form nobody scrolls back up to.

           One column below 60rem, in source order — so on a phone the booking
           card is **last**, under the itinerary and the FAQ. This comment used
           to claim the opposite and no rule ever did it: `order` is set only
           inside the media query below, which is the desktop case.

           The title, the standfirst, the facts and the photographs now sit in
           `.trip-hero` above both columns (2026-09-16), so the columns start at
           the first section. The card is still last in source order on a
           phone, where the bottom sheet (ADR-0033) is what puts booking on
           screen. */
        /* ---- when the widget becomes a bottom sheet (ADR-0033) ----------

           The widget decides at runtime whether it can pin itself to the
           viewport — narrow enough, and no ancestor trapping `position: fixed`
           (WGT-22) — and writes the answer onto its own host element as
           `data-kaiki-sheet`. The page cannot work that out for itself, and a
           media query here would be a second opinion free to disagree with the
           one that matters.

           What follows is only about not saying everything twice. The bar
           carries the price and the trip's four lines, so the copies above the
           mount come off; the details, the «who pays what» and the operator's
           contact card stay, because the bar carries none of those. */
        .booking:has([data-kaiki-sheet="true"]) > .price,
        .booking:has([data-kaiki-sheet="true"]) > .card-facts { display: none; }

        /* ---- the page's own booking bar ---------------------------------

           Printed in the HTML so it is on screen at first paint, instead of
           after 80 KB of script and two API calls. It is a link to `#book` and
           nothing more: no state, no price that can go stale beyond the
           from-price already on the page, and no JavaScript, so it survives a
           blocked bundle (WGT-23) and a host that traps `position: fixed`
           (WGT-22) — the two cases where the sheet never arrives at all.

           It is the floor. The widget's sheet is the upgrade, and the moment
           the widget says it has pinned itself this one leaves. */
        .book-bar {
            position: fixed;
            inset-inline: 0;
            inset-block-end: 0;
            z-index: 40;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            /* Matched to the widget's peek bar — 5.5rem of it, the same
               padding, the same two lines in the same sizes. The handover has
               to be invisible, and the only way for it to be invisible is for
               the two to be the same shape.

               The side padding is a flat 1rem because `.kaiki-peek` is a flat
               1rem. It used to be `clamp(1rem, 4vw, 1.5rem)`, the page's own
               gutter, which agrees with the widget only on a narrow phone: at
               600px the two are 8px apart, so the price and the button slid
               sideways at the moment of the handover. The page gutter is the
               wrong thing to follow here — this bar is a stand-in for the
               widget, not part of the page's grid. */
            min-block-size: 5.5rem;
            padding: .7rem 1rem .85rem;
            padding-block-end: calc(.85rem + env(safe-area-inset-bottom, 0px));
            background: var(--surface);
            border-block-start: 1px solid var(--rule);
            box-shadow: 0 -8px 24px -14px color-mix(in srgb, var(--kaiki-text) 40%, transparent);

            /* It waits before it shows itself.

               Matching the widget's peek word for word and pixel for pixel got
               the handover down to a flicker, and a flicker is still something
               a visitor sees: the bar paints at first paint, the widget mounts
               around half a second later, and for that half second there is a
               bar on screen that is about to be replaced by another one. Even
               when the two agree exactly, the swap catches the eye.

               So this one holds for 900ms. If the widget mounts first — which
               on anything but a cold cache it does — the rule below takes this
               bar off the page before it was ever painted, and the guest sees
               one bar arrive once, already the real one. If the widget never
               comes (no JavaScript, a blocked bundle, a host that traps fixed
               positioning — ADR-0033's whole reason for existing) this appears
               at 900ms and does its job.

               An animation rather than a transition, because nothing changes
               it: it runs once on its own, needs no class flipped by script,
               and so still runs with JavaScript off. `backwards` holds the
               from-state during the delay. */
            animation: book-bar-in .18s ease-out .9s backwards;
        }

        @keyframes book-bar-in {
            from { opacity: 0; transform: translateY(100%); }
            to { opacity: 1; transform: none; }
        }

        /* The delay is a reveal, not motion, for anybody who asked for less of
           it: still late, still no slide. */
        @media (prefers-reduced-motion: reduce) {
            .book-bar { animation: book-bar-appear 0s linear .9s backwards; }
            @keyframes book-bar-appear { from { opacity: 0; } to { opacity: 1; } }
        }

        @media (min-width: 60rem) { .book-bar { display: none; } }

        .book-bar-text { flex: 1; min-width: 0; }

        .book-bar-price { display: flex; align-items: baseline; gap: .35rem; line-height: 1.2; }
        .book-bar-price .from { font-size: .8rem; font-weight: 400; color: var(--ink-soft); }
        .book-bar-price strong { font-size: 1.22rem; font-weight: 700; letter-spacing: -.02em; }

        .book-bar-summary {
            display: block;
            margin-block-start: .05rem;
            font-size: .82rem;
            color: var(--ink-soft);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* The widget's `.kaiki-peek-tab`, copied to the pixel: same size, same
           offset, same surface and hairline, so the handover leaves it where it
           was. It never turns here — this bar does not open. */
        .book-bar-tab {
            position: absolute;
            inset-block-end: 100%;
            inset-inline-start: 0;
            margin-block-end: -1px;

            display: flex;
            align-items: center;
            justify-content: center;
            padding: .34rem 1rem .4rem;

            background: var(--surface);
            border: 1px solid var(--rule);
            border-block-end: 0;
            border-inline-start: 0;
            border-radius: 0 .4rem 0 0;
            color: var(--kaiki-primary);
        }

        .book-bar-tab svg { display: block; inline-size: 1.2rem; block-size: .7rem; overflow: visible; }

        /* The pill is the widget's `.kaiki-peek-action`, not the page's small
           button: `.62rem` of padding against `.55rem`, and the shell's 1.5
           line-height against the page button's 1. Left alone, the two agree on
           their right edge and their middle and differ by 7px in height, which
           is the pill changing size under a bar that has not moved. The `- 1px`
           is this button's border, which the widget's span does not have. */
        .book-bar .button {
            flex: none;
            padding-block: calc(.62rem - 1px);
            padding-inline: calc(1rem - 1px);
            line-height: 1.5;
        }

        /* Gone the moment the real thing exists — otherwise two bars stack. */
        body:has([data-kaiki-sheet="true"]) .book-bar { display: none; }

        /* Either bar occupies the bottom of the screen for good, so the page
           owes it that much clearance — otherwise the footer and the last thing
           in the aside sit underneath it and can never be read. */
        body:has([data-kaiki-sheet="true"]),
        body:has(.book-bar) {
            padding-block-end: calc(6.75rem + env(safe-area-inset-bottom, 0px));
        }

        @media (min-width: 60rem) {
            body:has(.book-bar):not(:has([data-kaiki-sheet="true"])) { padding-block-end: 0; }
        }

        .product-body { display: grid; gap: clamp(2rem, 4vw, 3.5rem); }

        .product-main { display: flex; flex-direction: column; gap: clamp(2rem, 4vw, 3rem); min-width: 0; }

        @media (min-width: 60rem) {
            .product-body {
                grid-template-columns: minmax(0, 1fr) 27rem;
                align-items: start;
            }

            .product-aside { order: 2; }
            .product-main { order: 1; }

            /* **The column sticks, and scrolls inside itself when it is tall.**

               Two failures, and this is the arrangement that has neither.

               The column was sticky with no height cap, and a sticky box taller
               than the viewport is a trap: it pins at its offset and everything
               below the fold inside it can never be scrolled into view. The
               aside runs 200px past the screen on a 1440x900 desktop and 440px
               past it on a short laptop, so «Έχετε απορίες;» was unreachable at
               every desktop size.

               Sticking the *card* instead fixed reachability and broke
               something else: a sticky element stays put while its siblings keep
               scrolling, so the cards below it slid underneath and the contact
               card disappeared behind the booking form.

               So the whole column is one sticky box again, capped at the screen
               and scrolling inside itself when it does not fit. Nothing overlaps,
               because there is one box; nothing is trapped, because the box
               scrolls. `svh`, not `vh`: a mobile browser's collapsing chrome
               makes `vh` taller than the screen and the part that overflows is
               the bottom. */
            .product-aside {
                position: sticky;
                top: 1.5rem;
            }

            /* **The scrollbar is inside the card** (Mike, 2026-09-22).

               The column used to be the box that scrolled, so the track was
               drawn at the edge of the *column* — a grey bar running down the
               outside of a white card, against the page, with nothing around
               it. Moving the cap and the overflow onto the card itself puts
               the track inside its rounded corner, where it reads as part of
               the card.

               `overflow: hidden` on the card already clips the photograph to
               its radius; `auto` on the block axis keeps that and lets the
               content scroll. The extra inline padding is the room the thumb
               needs: without it, a 6px track sits on top of the last character
               of «Αναχώρηση από». */
            .product-aside > .booking {
                max-block-size: calc(100svh - 3rem);
                overflow-y: auto;

                /* **No `overscroll-behavior: contain` here** (Mike,
                   2026-09-22: *«αν σκρολλάρω μέσα του δεν κινείται τπτ»*). It
                   was copied over with the rest when the scrolling moved from
                   the column to the card, and on a card whose content fits —
                   which is most trips — `contain` stops the wheel reaching the
                   page as well as stopping it inside the box. The pointer sat
                   over the booking card and the page would not move. The
                   default chains: the card scrolls while it has somewhere to
                   go, the page carries on from there. */
                padding-inline-end: calc(clamp(1.75rem, 2.2vw, 2.35rem) - .35rem);

                /* Barely there until a thumb is in it. `scrollbar-gutter:
                   stable` was tried and was worse than the problem it solved:
                   it reserves an empty channel down the side of the card
                   whether or not anything scrolls, which reads as a rendering
                   fault rather than as a scrollbar. */
                scrollbar-width: thin;
                scrollbar-color: color-mix(in srgb, var(--kaiki-text) 16%, transparent) transparent;
            }

            .product-aside > .booking::-webkit-scrollbar { inline-size: 6px; }
            .product-aside > .booking::-webkit-scrollbar-track { background: transparent; }

            .product-aside > .booking::-webkit-scrollbar-thumb {
                border-radius: 999px;
                background: color-mix(in srgb, var(--kaiki-text) 14%, transparent);
                /* A border in the card's own colour, so the thumb is a thin
                   line with air either side of it rather than a bar wedged
                   against the edge. */
                border: 1px solid var(--surface);
                background-clip: padding-box;
            }

            .product-aside > .booking:hover::-webkit-scrollbar-thumb {
                background: color-mix(in srgb, var(--kaiki-text) 26%, transparent);
                background-clip: padding-box;
            }
        }

        /* The facts under the standfirst, as chips: the icon beside its value
           in a soft pill, wrapping onto a second row rather than squeezing.
           Nothing in the row is a control, so the pills carry no border and
           no hover — they are labels, and a label that looks pressable is a
           tap that does nothing. */
        ul.facts {
            list-style: none; margin: 0; padding: 0;
            display: flex; flex-wrap: wrap; gap: .5rem;
            font-size: .9rem; color: var(--kaiki-text);
        }

        ul.facts li {
            display: inline-flex; align-items: center; gap: .45rem;
            padding: .45rem .85rem; border-radius: 999px;
            background: color-mix(in srgb, var(--kaiki-primary) 7%, var(--surface));
        }

        ul.facts .icon { inline-size: 1rem; block-size: 1rem; flex: none; color: var(--kaiki-primary); }


        @media (max-width: 48rem) {
            ul.facts { gap: .4rem; font-size: .85rem; }
            ul.facts li { padding: .38rem .7rem; }
            ul.facts .icon { inline-size: .9rem; block-size: .9rem; }
        }

        /* --- the trip page's lightbox ---------------------------------
           The mosaic at the top of the page opens it. The panels sit in a
           wrapper that takes no box of its own, so a closed lightbox adds no
           gap to the column it is written in. */
        .lightboxes { display: contents; }

        /* The lightbox. Open when the URL names it, and nothing else.
           `display` rather than opacity, so a closed panel is out of the
           accessibility tree instead of merely invisible. */
        .lightbox { display: none; }

        .lightbox:target {
            /* Above the widget's payment bar on a phone, which sits at
               2147483000 (`packages/widget/src/shadow.ts`) — at 60 the bar
               covered the bottom of the photograph and took its taps. */
            position: fixed; inset: 0; z-index: 2147483100;
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
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            gap: .6rem;
        }

        .lightbox figure img {
            max-inline-size: 100%; max-block-size: 80vh;
            inline-size: auto; block-size: auto;
            object-fit: contain; border-radius: 10px; display: block;
        }

        /* The photograph's alt text, when the operator wrote one. */
        .lightbox figcaption {
            color: rgba(255, 255, 255, .9); font-size: .9rem; text-align: center;
            max-inline-size: 40rem;
        }

        /* «2 / 5», level with the close button. */
        .lightbox-count {
            position: absolute; z-index: 2; margin: 0;
            inset-block-start: clamp(.75rem, 3vw, 1.5rem); inset-inline-start: clamp(.75rem, 3vw, 1.5rem);
            padding: .45rem .2rem;
            color: rgba(255, 255, 255, .85); font-size: .9rem; font-variant-numeric: tabular-nums;
        }

        .lightbox-close:focus-visible,
        .lightbox-step a:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }

        /* The page under an open photograph does not scroll. Without a script:
           `:has()` sees the open panel and holds the document still. */
        html:has(.lightbox:target) { overflow: hidden; }

        /* A short fade in, and none for anyone who asked for less motion. */
        @media (prefers-reduced-motion: no-preference) {
            .lightbox:target { animation: lightbox-in .18s ease-out; }
        }

        @keyframes lightbox-in { from { opacity: 0; } to { opacity: 1; } }

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
            /* On a phone the arrows sit on the photograph itself, and a white
               glyph on a white sail is not there at all. */
            background: rgba(6, 16, 20, .45);
        }

        .lightbox-step a:hover { background: rgba(6, 16, 20, .7); }
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

        /* The first tab sits flush with the column.

           The row was already `justify-content: flex-start`, so the tabs were
           left-aligned as a group — but every label carries 1.1rem of inner
           padding, which pushed the first tab's *text* eighteen pixels right of
           where the heading, the prose and the cards below it all begin. That
           is the misalignment the eye actually sees. The padding stays on the
           others, because it is what gives each tab a hit area. */
        .tab-label:first-of-type { padding-inline-start: 0; }

        /* On a phone the row stops wrapping and starts scrolling.

           «Επόμενες αναχωρήσεις», «Πού συναντιόμαστε» and «Το σκάφος» do not fit
           across 360 pixels, so `flex-wrap: wrap` broke them over two and three
           lines — and each line kept its own `border-block-end`, so the rule
           under the row was drawn through the middle of the tabs and the active
           underline landed on whichever line that tab had wrapped onto. One
           scrolling line is the shape every phone already knows.

           The rule moves from `border-block-end` to an inset shadow: a border
           sits outside the padding box, `overflow-x: auto` forces `overflow-y`
           to clip, and the labels' -1px overhang was cut off. A shadow paints
           inside, so the labels cover it exactly as they did. */
        @media (max-width: 48rem) {
            .tablist {
                flex-wrap: nowrap;
                gap: 0;
                overflow-x: auto;
                overflow-y: hidden;
                scrollbar-width: none;
                -webkit-overflow-scrolling: touch;
                border-block-end: 0;
                box-shadow: inset 0 -1px 0 var(--rule);
                margin-block-end: 1.25rem;
            }

            .tablist::-webkit-scrollbar { display: none; }

            .tab-label {
                flex: none;
                margin-block-end: 0;
                padding: .6rem .85rem;
                font-size: .88rem;
            }

            .tab-label:first-of-type { padding-inline-start: 0; }
        }

        .tabpanel { display: none; }

        /* One pair per tab. There are two, so writing them out is shorter and
           clearer than anything that would generate them. */
        #tab-meeting:checked ~ .tablist label[for="tab-meeting"],
        #tab-vessel:checked ~ .tablist label[for="tab-vessel"] {
            color: var(--kaiki-primary); border-block-end-color: var(--kaiki-primary);
        }

        #tab-meeting:focus-visible ~ .tablist label[for="tab-meeting"],
        #tab-vessel:focus-visible ~ .tablist label[for="tab-vessel"] {
            outline: 2px solid var(--kaiki-primary); outline-offset: 2px; border-radius: 6px;
        }

        #tab-meeting:checked ~ .tabpanels .tabpanel-meeting,
        #tab-vessel:checked ~ .tabpanels .tabpanel-vessel { display: block; }

        /* Printed, the tabs are meaningless — show every panel. */
        @media print { .tabpanel { display: block !important; } .tablist { display: none; } }

        /* **Το σκάφος: λωρίδα φωτογραφιών, μετά πινακάκι** (Mike, 2026-09-23 —
           κατεύθυνση Ζ1, μετά από τρεις γύρους μακετών).

           Η λωρίδα βγαίνει από το μέτρο ανάγνωσης και φτάνει ως την άκρη, με
           τα αρνητικά περιθώρια να ακυρώνουν το padding του πίνακα. Το
           `scroll-padding` βάζει την επόμενη φωτογραφία στη θέση που άφησε η
           προηγούμενη αντί να την κολλάει στο μηδέν. */
        .boat-rail {
            list-style: none;
            margin: 0 0 1.5rem;
            padding: 0 0 .5rem;
            display: flex;
            gap: .75rem;
            overflow-x: auto;
            scroll-snap-type: x mandatory;
            /* Μια λωρίδα που κυλάει είναι χειριστήριο, άρα πρέπει να φτάνει
               και από το πληκτρολόγιο· χωρίς αυτό οι φωτογραφίες μετά την
               τρίτη είναι απρόσιτες χωρίς ποντίκι. */
            scrollbar-width: thin;
        }

        .boat-rail:focus-visible { outline: 2px solid var(--kaiki-primary); outline-offset: 3px; border-radius: 4px; }

        /* Μένει μέσα στη στήλη ανάγνωσης και δεν βγαίνει ως την άκρη της
           σελίδας: το `.tabpanel` δεν έχει δικό του περιθώριο να ακυρωθεί, και
           ένα αρνητικό περιθώριο «στο περίπου» είναι ακριβώς ο τρόπος που μια
           σελίδα αποκτά οριζόντιο scroll σε ένα τηλέφωνο. */
        .boat-rail > li { flex: none; width: min(17rem, 72vw); scroll-snap-align: start; }

        /* Each photograph is a link to its lightbox panel (2026-09-25). */
        .boat-open { display: block; border-radius: 10px; overflow: hidden; }
        /* Drawn inside the photograph: the rail scrolls, so it clips anything
           outside its own box, an outline included. */
        .boat-open:focus-visible { outline: 3px solid var(--kaiki-primary); outline-offset: -3px; }

        @media (hover: hover) and (prefers-reduced-motion: no-preference) {
            .boat-open img { transition: scale .4s ease; }
            .boat-open:hover img { scale: 1.03; }
        }

        .boat-rail img {
            width: 100%; height: 100%;
            border-radius: 10px;
            object-fit: cover; object-position: center center;
            aspect-ratio: 3 / 2;
            display: block;
        }

        .boat-name { margin: 0 0 .85rem; font-size: var(--step-1); }

        /* Ζ1: μία γραμμή ανά στοιχείο μέσα σε κουτί με λεπτό περίγραμμα.
           Έξι σύντομα ζεύγη σε στήλες διαβάζονται ως δελτίο χαρακτηριστικών·
           στοιβαγμένα με ετικέτα αριστερά και τιμή δεξιά διαβάζονται ως
           απάντηση — και δεν αφήνουν μισή άδεια στήλη όταν ο διοργανωτής
           συμπλήρωσε τρία πεδία από τα έξι. */
        .boat-facts {
            margin: 0 0 1.5rem; padding: 0;
            border: 1px solid var(--hairline, color-mix(in srgb, var(--kaiki-text) 12%, transparent));
            border-radius: 12px;
            overflow: hidden;
        }

        .boat-facts > div {
            display: flex; justify-content: space-between; gap: 1.5rem;
            padding: .6rem .95rem;
            border-block-start: 1px solid color-mix(in srgb, var(--kaiki-text) 10%, transparent);
            min-width: 0;
        }

        .boat-facts > div:first-child { border-block-start: 0; }

        /* Πολύ ελαφριά εναλλαγή, ώστε το μάτι να μη χάνει σειρά σε έξι γραμμές
           χωρίς να μοιάζει με λογιστικό φύλλο. */
        .boat-facts > div:nth-child(odd) { background: color-mix(in srgb, var(--kaiki-text) 2.5%, transparent); }

        /* Στο τηλέφωνο το κουτί μένει κουτί — αυτό είναι το κέρδος του Ζ1 σε
           σχέση με το πλέγμα που ήταν πριν: δεν έχει στήλες να καταρρεύσουν.
           Μόνο λίγο στενότερο, και το `anywhere` γιατί ένας καπετάνιος με
           μακρύ όνομα θα έσπρωχνε τη γραμμή πιο πλατιά από την οθόνη. */
        @media (max-width: 48rem) {
            .boat-facts { margin-block-end: 1.25rem; }
            .boat-facts > div { padding-inline: .8rem; gap: 1rem; }
            .boat-facts dd { overflow-wrap: anywhere; text-align: end; }
        }

        .boat-facts dt { font-size: .85rem; color: var(--ink-faint); }
        .boat-facts dd { margin: 0; font-size: .9rem; font-weight: 600; font-variant-numeric: tabular-nums; }

        .shots { list-style: none; margin: 0; padding: 0; display: grid; gap: 1rem;
                 grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr)); }
        .shots img { width: 100%; height: 100%; border-radius: 14px; object-fit: cover; object-position: center center; aspect-ratio: 3 / 2; display: block; }

        /* The booking area. Four lines above the mount, and nothing else —
           brand decision 3 of 2026-09-04. */
        .booking {
            background: var(--surface);
            border-radius: 18px;
            padding: clamp(1.75rem, 2.2vw, 2.35rem);
            box-shadow: 0 1px 2px color-mix(in srgb, var(--kaiki-text) 5%, transparent),
                        0 18px 44px -34px color-mix(in srgb, var(--kaiki-text) 30%, transparent);
        }

        .card-facts { display: grid; gap: .55rem; margin: 0 0 1.1rem; }
        .card-facts > div { display: grid; grid-template-columns: 8.5rem 1fr; gap: .9rem; align-items: baseline; }
        .card-facts dt {
            font-size: .74rem; font-weight: 600; letter-spacing: .07em;
            color: var(--ink-faint); margin: 0;
        }
        .card-facts dd { margin: 0; font-weight: 600; }

        /* No rule above it any more: it is the first thing in the card. */
        .price { margin: 0 0 1.3rem; display: flex; flex-wrap: wrap; align-items: baseline; gap: .5rem; }
        .price .from { font-size: .85rem; color: var(--ink-faint); }
        .price strong { font-size: var(--step-3); letter-spacing: -.025em; }
        /* On the same line as the figure, small: `flex-basis: 100%` used to
           break it onto a row of its own, which gave four words the weight of
           a fact. It wraps by itself when the line is too narrow for it. */
        .price .vat { font-size: .74rem; color: var(--ink-faint); }


        .mount .no-js { margin: 0 0 .9rem; color: var(--ink-soft); font-size: .93rem; }
        .contact-cta { margin: 0; display: flex; flex-wrap: wrap; gap: .6rem; }
        .button.ghost { background: transparent; color: var(--kaiki-primary); border: 1px solid var(--kaiki-primary); }

        /* Written after the base rule rather than left to specificity: `.button.ghost`
           is two classes and would otherwise beat `.button:hover`, so a ghost
           button would be the only one on the page that did not answer. */
        .button.ghost:hover, .button.ghost:focus-visible {
            background: color-mix(in srgb, var(--kaiki-primary) 8%, transparent);
            border-color: var(--kaiki-primary);
            color: var(--kaiki-primary);
        }

        /* The trip page is the exception, and it was asked for: there the
           buttons empty out and take the accent on hover — transparent inside,
           accent rule, accent text. It works on that page because the buttons
           sit inside cards on a plain ground with nothing else competing, and
           it does not work on the home page's contact banner or the contact
           form, where a red button reads as a refusal. Scoped rather than
           global for exactly that reason. */
        .product .button:hover, .product .button:focus-visible {
            background: transparent;
            border-color: var(--kaiki-accent);
            color: var(--kaiki-accent);
        }

        /* --- the contact page ---------------------------------------------

           A form on the left, the ways to reach a person on the right. The
           right column is the narrower of the two because it is four lines of
           fact; the form is where the work happens. */
        .page-head { max-width: 44rem; margin-bottom: 2rem; }
        .page-head h1 { margin-bottom: .5rem; }
        .page-head .lede { margin: 0; color: var(--ink-soft); font-size: 1.05rem; line-height: 1.6; }

        .contact-page {
            display: grid; gap: 2rem; align-items: start;
            margin-bottom: 3rem;
        }

        @media (min-width: 62rem) {
            .contact-page { grid-template-columns: minmax(0, 1.55fr) minmax(0, 1fr); gap: 3rem; }
        }

        .contact-form-card {
            padding: 1.6rem; background: #fff;
            border: 1px solid var(--rule); border-radius: var(--kaiki-radius);
        }

        @media (min-width: 48rem) { .contact-form-card { padding: 2rem; } }

        .contact-form-card .field { display: grid; gap: .35rem; margin-bottom: 1rem; min-width: 0; }

        .contact-form-card label {
            font-size: .74rem; font-weight: 600; letter-spacing: .07em;
            color: var(--ink-faint);
        }

        /* Small, unemphatic and beside the label rather than under it: it is a
           note about the field, not a second label for it. */
        .contact-form-card .optional { font-weight: 400; letter-spacing: 0; color: var(--ink-faint); }

        .contact-form-card input,
        .contact-form-card textarea {
            font: inherit; font-size: 1rem; color: var(--kaiki-text);
            padding: .7rem .8rem; border: 1px solid var(--rule);
            border-radius: var(--kaiki-radius); background: #fff;
            width: 100%; box-sizing: border-box;
        }

        .contact-form-card input { height: 2.9rem; padding-block: 0; }
        .contact-form-card textarea { resize: vertical; line-height: 1.55; }

        .contact-form-card input:focus-visible,
        .contact-form-card textarea:focus-visible {
            outline: 2px solid var(--kaiki-primary); outline-offset: 1px; border-color: transparent;
        }

        /* Two on a line where there is room, one under the other on a phone.
           A name and an email are short enough to share a row and long enough
           that stacking them wastes a screen. */
        .field-row { display: grid; gap: 0 1rem; }

        @media (min-width: 40rem) { .field-row { grid-template-columns: 1fr 1fr; } }

        .contact-form-card .field-error { margin: .1rem 0 0; font-size: .85rem; color: var(--kaiki-accent); }

        /* The trip the visitor came from, above the fields it will be sent with. */
        .about-trip {
            margin: 0 0 1.1rem; padding: .55rem .75rem;
            font-size: .9rem; color: var(--ink-soft);
            background: color-mix(in srgb, var(--kaiki-primary) 6%, transparent);
            border-radius: var(--kaiki-radius);
        }

        .contact-form-card .sent {
            margin: 0 0 1.2rem; padding: .8rem 1rem;
            font-size: .95rem; line-height: 1.5;
            border: 1px solid color-mix(in srgb, var(--kaiki-primary) 40%, transparent);
            background: color-mix(in srgb, var(--kaiki-primary) 8%, transparent);
            border-radius: var(--kaiki-radius);
        }

        .contact-form-card .privacy { margin: 1rem 0 0; }

        /* The honeypot (BKG-29). Off-screen rather than `display: none`, which
           is the first thing a form-filling script skips. `aria-hidden` and
           `tabindex="-1"` in the markup keep it away from anybody real. */
        .honey {
            position: absolute; inline-size: 1px; block-size: 1px;
            overflow: hidden; clip-path: inset(50%); white-space: nowrap;
        }

        .contact-details h2 { margin-top: 0; font-size: 1.05rem; }
        .contact-details .contact-list { margin-bottom: 1.2rem; }
        .contact-details .reply { margin: 1.2rem 0 0; }

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

        /* --- FAQ (#103) --------------------------------------------- */

        .faq-list { display: grid; gap: .8rem; }

        .faq-item {
            background: var(--surface); border: 1px solid var(--rule);
            border-radius: var(--kaiki-radius); padding: .9rem 1.1rem;
        }

        /* The whole question is the control, so the tap target is the width of
           the card rather than the width of the words. */
        .faq-item summary {
            /* 500, not 600. A column of questions all set in semibold reads as
               a list of headings rather than as things somebody asked. */
            cursor: pointer; font-weight: 500; letter-spacing: -.005em;
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

        /* The social row. Marks with their names beside them rather than icons
           alone: an unlabelled glyph is a guess, and these sit at the bottom of
           a page where somebody is deciding whether to trust an operator. */
        .social {
            list-style: none; margin: 1.5rem 0 0; padding: 0;
            display: flex; flex-wrap: wrap; gap: .5rem;
        }

        /* Icon only. Three labelled pills were wider than the heading above
           them and read as navigation; the mark alone is what everybody already
           recognises, and the name survives as the link's accessible name. */
        .social a {
            display: grid; place-content: center;
            inline-size: 3rem; block-size: 3rem;
            border: 1px solid var(--rule); border-radius: 50%;
            color: var(--kaiki-text); text-decoration: none;
            transition: border-color .15s ease, color .15s ease;
        }

        .social a:hover { border-color: var(--kaiki-primary); color: var(--kaiki-primary); }
        .social a:focus-visible { outline: 2px solid var(--kaiki-primary); outline-offset: 2px; }
        /* Solid, in the operator's own colour — the same treatment the
           contact rows above them get. */
        .social .icon { inline-size: 1.45rem; block-size: 1.45rem; color: var(--kaiki-primary); }

        @media (prefers-reduced-motion: reduce) { .social a { transition: none; } }
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
            list-style: none; margin: 1.75rem 0 0; padding: 0;
            /* Tighter. At 1.5rem the four rows read as four separate things;
               they are one address card. */
            display: grid; gap: .7rem;
        }

        /* No rules between the rows. Four labelled facts with air around them
           read as an address card; the same four in a ruled table read as a
           settings screen. */
        /* Icon, then value, on one line — the labels moved into `.sr-only`.
           `align-items: start` rather than centre, because the meeting-point row
           runs to two lines and a centred icon beside a two-line value floats
           in the middle of nothing. */
        .contact-list li {
            display: grid; grid-template-columns: 1.15rem 1fr; gap: .1rem .7rem;
            align-items: start;
        }

        .contact-list .icon { inline-size: 1.15rem; block-size: 1.15rem; color: var(--kaiki-primary); margin-block-start: .15rem; }

        /* Black, not the brand colour. Three teal links stacked read as
           navigation; these are facts that happen to be tappable, and the icon
           beside each one is already carrying the colour. */
        /* Regular weight and the body's own ink. At 600 in full black three
           stacked rows read as three headings; they are facts. The icon beside
           each one carries the colour, so the text does not have to.

           No underline on hover either — a row that grows a rule under it as
           the cursor passes is the panel flinching. The colour shift is enough
           to say it is tappable. */
        .contact-list li > span:not(.sr-only),
        .contact-list li > a {
            grid-column: 2; font-size: .98rem; font-weight: 400; line-height: 1.4;
            color: var(--ink-soft); text-decoration: none;
        }

        .contact-list li > a:hover { color: var(--kaiki-primary); text-decoration: none; }

        .contact-list .instructions { grid-column: 2; font-size: .88rem; font-weight: 400; color: var(--ink-faint); }

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
            /* Room above and below. At 2rem the footer sat straight under the
               contact panel like one more row of it, and the whole page ended
               abruptly on a legal link. This is the end of the page: it can
               afford the air, and the separation is what tells a reader the
               content is over. */
            padding-block: clamp(3rem, 6vw, 4.5rem) clamp(3rem, 6vw, 4.5rem);
            font-size: .88rem; color: var(--ink-soft);
        }

        /* Three columns of detail and the brand on the end. `auto-fit` with a
           15rem floor put four equal columns on a laptop and left the mark
           looking like a fourth list; an explicit last column that takes what
           it needs keeps it a sign-off. */
        footer.site .cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr)); gap: 2.5rem 2rem; }

        @media (min-width: 62rem) {
            footer.site .cols { grid-template-columns: 1.2fr 1fr 1fr auto; }
        }

        /* A step up. At .74rem these were smaller than the text beneath them and
           read as captions rather than as the headings of three columns. */
        footer.site h3 { font-size: .82rem; font-weight: 600; letter-spacing: .06em; color: var(--kaiki-text); margin: 0 0 .7rem; }

        .foot-brand {
            display: flex; flex-direction: column; gap: 1.1rem;
            align-items: flex-start;
        }

        @media (min-width: 62rem) {
            /* Right-aligned against the edge of the page, which is the only
               position that reads as a sign-off rather than as one more column
               of links. */
            .foot-brand { align-items: flex-end; text-align: right; }
        }

        .foot-logo { max-block-size: 2.75rem; inline-size: auto; }

        .foot-wordmark {
            margin: 0;
            font-family: var(--kaiki-font, inherit);
            font-size: var(--step-1); font-weight: 800; letter-spacing: -.02em;
            color: var(--kaiki-text);
        }

        /* The same round buttons as the contact panel, a size down: this is a
           footer and the panel above it is where somebody is actually deciding
           to get in touch. */
        .foot-brand .social { margin: 0; }
        .foot-brand .social a { inline-size: 2.4rem; block-size: 2.4rem; }
        .foot-brand .social .icon { inline-size: 1.15rem; block-size: 1.15rem; }
        footer.site p { margin: 0 0 .3rem; }
        footer.site ul { margin: 0; padding: 0; list-style: none; }
        footer.site li { margin-bottom: .3rem; }

        .powered {
            margin-top: 1.75rem; padding-top: 1rem; border-top: 1px solid var(--rule);
            font-size: .8rem; color: var(--ink-faint);
        }

        /* --- the trip page's optional content (2026-09-16) ----------------
           «Τι θα ζήσετε», the programme, what is and is not included, what to
           bring — each drawn only when the operator filled it in.

           Plain on purpose (Mike, 2026-09-16): no icons, no coloured marks, no
           bold, no rules between the lines; «not included» is the same list in
           a softer ink. */
        ul.trip-list { list-style: none; margin: 0; padding: 0; display: grid; gap: .7rem; font-size: 1rem; line-height: 1.55; }
        ul.trip-list li {
            display: grid; grid-template-columns: 1.05rem minmax(0, 1fr); gap: .8rem; align-items: start;
            color: var(--kaiki-text);
        }
        /* The marks came back very light (Mike, 2026-09-16): a thin line in a
           faint ink, no tile, no colour — enough to tell the four lists apart
           at a glance without the lines turning into badges. */
        .trip-mark { display: block; line-height: 0; padding-block-start: .2rem; color: color-mix(in srgb, var(--kaiki-text) 38%, transparent); }
        .trip-mark .icon { inline-size: 1.05rem; block-size: 1.05rem; stroke-width: 1.3; }
        .trip-list-no li { color: var(--ink-soft); }

        /* The programme: a thin line, a small quiet dot per stop, and the time
           in the body's own soft ink beside the stop's name. `ol` still, so a
           screen reader announces the order. */
        ol.trip-timeline { list-style: none; margin: 0; padding: 0; display: block; }
        ol.trip-timeline li { position: relative; padding: 0 0 1.25rem 1.6rem; }
        ol.trip-timeline li::before {
            content: ''; position: absolute; inset-inline-start: 0; top: .55rem;
            inline-size: .45rem; block-size: .45rem; border-radius: 50%;
            background: color-mix(in srgb, var(--kaiki-text) 35%, transparent);
        }
        ol.trip-timeline li::after {
            content: ''; position: absolute; inset-inline-start: .2rem; top: 1.25rem; bottom: .2rem;
            inline-size: 1px; background: var(--line);
        }
        ol.trip-timeline li:last-child { padding-bottom: 0; }
        ol.trip-timeline li:last-child::after { display: none; }
        .trip-time {
            display: inline-block; min-inline-size: 3.2rem; margin-inline-end: .5rem;
            font-size: .95rem; font-weight: 400; color: var(--ink-soft);
            font-variant-numeric: tabular-nums;
        }
        ol.trip-timeline h3 { display: inline; margin: 0; font-size: 1rem; font-weight: 500; color: var(--kaiki-text); }
        ol.trip-timeline p { margin: .2rem 0 0 3.7rem; color: var(--ink-soft); font-size: .94rem; }

        /* ==================================================================
           The design of 16 September, from the operator's WordPress site.

           Last in the sheet on purpose: it restyles the header, the masthead,
           the sections and the footer that the rules above lay out, and a rule
           here wins over an earlier one of the same weight without either
           having to be deleted and argued about again.

           **In the operator's colours, not Aegean Blue's.** Every navy on the
           WordPress site is `--kaiki-primary` here, or a deeper mix of it;
           every terracotta is `--kaiki-accent`. The two tints — sand and mist —
           are neutral enough to sit beside any brand.

           The house rules still hold: light page (the dark footer and the dark
           reasons band are sections on it, not a theme), no case transform,
           no underline, 10px on controls and 14–20px on cards, no web font the
           operator did not choose.
           ================================================================== */

        :root {
            --sand: #F7F3EC;
            --mist: color-mix(in srgb, var(--kaiki-primary) 6%, #FFFFFF);
            --deep: color-mix(in srgb, var(--kaiki-primary) 78%, #000000);
            --line: color-mix(in srgb, var(--kaiki-primary) 10%, #FFFFFF);
            --shadow-sm: 0 1px 2px rgba(11, 39, 64, .05), 0 2px 8px rgba(11, 39, 64, .05);
            --shadow: 0 2px 4px rgba(11, 39, 64, .04), 0 12px 32px rgba(11, 39, 64, .09);
            --section-gap: clamp(3.5rem, 7vw, 6rem);
            --band-pad: clamp(4rem, 8vw, 6.5rem);
        }

        body { background: #FFFFFF; }
        main .wrap { gap: var(--section-gap); }

        /* --- buttons -------------------------------------------------- */

        .button { min-block-size: 3rem; padding: 0 1.4rem; justify-content: center; border-radius: 10px; }
        .button-small { min-block-size: 2.5rem; padding: 0 1rem; }

        .button-accent {
            background: var(--kaiki-accent); border-color: var(--kaiki-accent); color: #fff;
            box-shadow: 0 1px 0 rgba(255, 255, 255, .15) inset, 0 6px 16px color-mix(in srgb, var(--kaiki-accent) 22%, transparent);
        }
        .button-accent:hover, .button-accent:focus-visible {
            background: color-mix(in srgb, var(--kaiki-accent) 82%, #000); border-color: transparent; color: #fff;
        }

        .button-light { background: #fff; border-color: #fff; color: var(--deep); }
        .button-light:hover, .button-light:focus-visible { background: var(--sand); border-color: var(--sand); color: var(--deep); }

        .button-glass { background: rgba(255, 255, 255, .1); border-color: rgba(255, 255, 255, .4); color: #fff; }
        .button-glass:hover, .button-glass:focus-visible { background: rgba(255, 255, 255, .2); border-color: #fff; color: #fff; }

        /* --- the header ------------------------------------------------
           Sticky, and see-through enough to read as part of the page it sits
           over: white at 92% with the page blurred behind it. */
        header.site {
            position: sticky; top: 0; z-index: 40;
            background: rgba(255, 255, 255, .92);
            -webkit-backdrop-filter: saturate(1.6) blur(14px);
            backdrop-filter: saturate(1.6) blur(14px);
            border-bottom: 1px solid var(--line);
        }

        header.site .wrap { gap: 1.25rem; padding-block: .85rem; min-block-size: 4.75rem; }

        .brand { gap: .7rem; color: var(--deep); }
        .brand-mark {
            inline-size: 2.4rem; block-size: 2.4rem; flex: none; border-radius: 11px;
            display: grid; place-items: center; color: #fff;
            background: linear-gradient(145deg, color-mix(in srgb, var(--kaiki-primary) 70%, #fff), var(--deep));
        }
        .brand-mark .icon { inline-size: 1.35rem; block-size: 1.35rem; }
        .brand-text { display: flex; flex-direction: column; line-height: 1.1; min-width: 0; }
        .brand .name { font-size: 1.1rem; font-weight: 800; letter-spacing: -.02em; }
        .brand-tag { font-size: .75rem; font-weight: 500; color: var(--ink-faint); margin-top: .2rem; }

        .site-nav { gap: .15rem; font-size: .95rem; margin-right: 0; }
        .site-nav a { padding: .55rem .8rem; border-radius: 8px; color: var(--kaiki-text); font-weight: 500; }
        .site-nav a:hover { background: var(--mist); color: var(--kaiki-primary); }

        .header-phone {
            display: inline-flex; align-items: center; gap: .4rem; white-space: nowrap;
            font-size: .9rem; font-weight: 600; color: var(--deep);
        }
        .header-phone .icon { inline-size: 1rem; block-size: 1rem; color: var(--kaiki-accent); }
        .header-phone:hover { color: var(--kaiki-accent); }

        .header-book { min-block-size: 2.6rem; padding-inline: 1.1rem; font-size: .92rem; white-space: nowrap; }
        /* Flat in the header (Mike, 2026-09-16): the glow under it read as a
           smudge on a white bar. */
        .header-book, .header-book:hover, .header-book:focus-visible, .menu-book, .menu-book:hover { box-shadow: none; }

        .langs a { border-radius: 8px; }

        @media (max-width: 75rem) { .header-phone { display: none; } }

        /* The burger takes over below 52rem rather than 40: with the phone and
           the booking button in the row, the name and the links ran out of room
           well before a phone's width.

           …and the links fold into it at 73.75rem (1180px) and below since
           «Ημερολόγιο» joined them (2026-09-25): four links, the language
           switch and «Κλείστε θέση» pushed the button off a 1024px screen and
           overflowed the page, and 834px overflowed already with three. Measured:
           at 1160px the operator's name wraps to two lines, at 1170px it does not. The
           button stays in the row down to 52rem; below that it is in the panel. */
        @media (max-width: 52rem) {
            .header-book { display: none; }
        }

        @media (max-width: 73.75rem) {
            .site-nav { display: none; }
            /* With the links folded away, the name takes the room and the
               language switch, the button and the burger sit together at the end. */
            header.site .wrap > .brand { flex: 1 1 auto; }
            header.site .wrap > .header-end { flex: none; }
            .brand { min-width: 0; flex: 1 1 auto; }
            .brand .name { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .langs { flex: 0 0 auto; margin-left: auto; }

            details.menu { display: block; flex: 0 0 auto; }
            details.menu > summary {
                display: grid; place-items: center; inline-size: 2.75rem; block-size: 2.75rem;
                border: 1px solid var(--line); border-radius: 10px; color: var(--deep);
                cursor: pointer; list-style: none; background: #fff;
            }
            details.menu > summary::-webkit-details-marker { display: none; }
            details.menu > summary .icon { inline-size: 1.35rem; block-size: 1.35rem; }
            details.menu[open] > summary { background: var(--mist); border-color: var(--mist); color: var(--deep); }
            details.menu[open] .bar.mid { opacity: 0; }
            details.menu[open] .bar.top { transform: translateY(6px) rotate(45deg); }
            details.menu[open] .bar.bot { transform: translateY(-6px) rotate(-45deg); }
            details.menu .bar { transform-origin: center; }

            .menu-panel {
                position: absolute; inset-inline: 0; top: 100%;
                display: flex; flex-direction: column; gap: .15rem;
                background: #fff; border-bottom: 1px solid var(--line);
                box-shadow: 0 12px 28px rgba(6, 16, 20, .12);
                padding: .5rem clamp(1.25rem, 3vw, 2.5rem) 1.1rem;
            }
            .menu-panel a { padding: .8rem .75rem; border-radius: 10px; border-top: 0; font-size: 1.05rem; color: var(--deep); }
            .menu-panel a:hover { background: var(--mist); }
            .menu-panel .menu-phone { display: inline-flex; align-items: center; gap: .5rem; font-weight: 600; }
            .menu-panel .menu-phone .icon { inline-size: 1.05rem; block-size: 1.05rem; color: var(--kaiki-accent); }
            .menu-panel .menu-book { margin-top: .5rem; min-block-size: 3.1rem; justify-content: center; color: #fff; }
            .menu-panel .menu-book:hover { background: color-mix(in srgb, var(--kaiki-accent) 82%, #000); }
        }

        @media (min-width: 52.01rem) { .menu-panel .menu-book { display: none; } }
        @media (min-width: 73.76rem) { .menu-panel .menu-phone { display: none; } }
        @media (max-width: 26rem) { .brand-tag { display: none; } }

        /* --- the eyebrow and section heads ------------------------------ */

        .eyebrow-line {
            display: inline-flex; align-items: center; gap: .5rem;
            margin: 0 0 .75rem;
            font-size: .8rem; font-weight: 700; letter-spacing: .08em;
            color: var(--kaiki-accent);
        }
        .eyebrow-line::before { content: ''; inline-size: 1.1rem; block-size: 2px; border-radius: 2px; background: currentColor; }

        .section-head { margin-block-end: clamp(2rem, 4vw, 3rem); max-width: 46rem; }
        .section-head.is-center { margin-inline: auto; text-align: center; }
        .section-head h2 { font-size: clamp(1.75rem, 3.4vw, 2.6rem); font-weight: 800; letter-spacing: -.025em; margin: 0; color: var(--deep); }
        .section-head .lead { margin-top: 1rem; font-size: 1.1rem; line-height: 1.7; color: var(--ink-soft); }
        .section-head .lead p { margin: 0 0 .6rem; }
        .section-head .lead p:last-child { margin: 0; }

        .block-head h2, .story-copy > h2, .block.faq > h2 { font-size: clamp(1.75rem, 3.4vw, 2.6rem); font-weight: 800; letter-spacing: -.025em; color: var(--deep); }
        .block-head h2::after, .story-copy > h2::after, .block.faq > h2::after { display: none; }
        .block-head h2 { margin-block-end: 0; }
        .story-copy > h2, .block.faq > h2 { margin-block-end: 1.5rem; }

        /* The FAQ heading on a trip page is one section among six, not the
           head of a home-page band (Mike, 2026-09-22). The rule above is
           written for the home page and reaches this block through the same
           class; the trip page steps it back down to the size its neighbours
           are set in. (In words rather than in Greek on purpose: this
           stylesheet is inlined into every page, and `FaqRenderingTest` asserts
           that a page with no FAQ does not say the heading anywhere.) */
        .product-main .block.faq > h2 {
            font-size: var(--step-1);
            letter-spacing: -.012em;
            margin-block-end: 1rem;
        }
        .block-head { align-items: end; margin-block-end: 2rem; }
        .story .eyebrow { color: var(--kaiki-accent); }

        /* --- full-bleed bands ------------------------------------------
           A section that runs edge to edge in its own colour, with its content
           held to the page's column. Two bands in a row touch, as they do on
           the WordPress site; a band after an ordinary section keeps the gap. */
        .band {
            margin-inline: calc(50% - 50vw);
            padding-inline: calc(50vw - 50%);
            padding-block: var(--band-pad);
        }
        .band + .band { margin-block-start: calc(-1 * var(--section-gap)); }
        .band-sand { background: var(--sand); }
        .band-mist { background: var(--mist); }
        .band-dark { background: var(--deep); color: rgba(255, 255, 255, .78); }
        .band-dark .section-head h2, .band-dark h3 { color: #fff; }
        .band-dark .section-head .lead { color: rgba(255, 255, 255, .78); }
        .band-dark .eyebrow-line { color: color-mix(in srgb, var(--kaiki-accent) 45%, #fff); }

        main:has(.wrap > .band:last-child) { padding-block-end: 0; }

        .band-inner { display: grid; gap: clamp(2rem, 5vw, 4rem); align-items: center; }
        .band-image { inline-size: 100%; aspect-ratio: 4 / 3.2; object-fit: cover; object-position: center center; border-radius: 24px; }
        @media (min-width: 62rem) {
            .band.has-image .band-inner { grid-template-columns: 1.1fr 1fr; }
            .band.has-image .steps, .band.has-image .features { grid-template-columns: 1fr; }
        }

        /* --- the masthead -------------------------------------------------
           Split (Mike chose it on 2026-09-16, option C of three): the small
           line, the heading, the standfirst, the buttons and the badges on the
           left, and the search as a white card with its own title on the right,
           inside the photograph. Nothing hangs off the lower edge any more, so
           the trips start higher. The shade is heaviest behind the words and
           lifts towards the card, which is solid and needs none.

           Below 62rem the two stack: the words first, the card under them and
           still on the photograph. */
        .hero-inner {
            position: relative; z-index: 2;
            /* The page column's own width and gutters (`.wrap`), so the words
               and the card line up with the trips below them. */
            inline-size: 100%; max-width: calc(1400px + 5rem); margin: 0 auto;
            padding: clamp(3rem, 7vw, 5.5rem) clamp(1.25rem, 3vw, 2.5rem);
            display: grid; gap: clamp(2rem, 4vw, 3.5rem); align-items: center;
        }
        @media (min-width: 62rem) {
            .hero-inner { grid-template-columns: minmax(0, 1fr) minmax(24rem, 31rem); }
        }
        .hero-copy { text-align: left; padding: 0; max-width: 42rem; margin: 0; }
        .hero-copy h1 { max-width: none; margin-inline: 0; }
        .hero-copy .standfirst { max-width: 36rem; margin-inline: 0; }
        /* 750px (Mike, 2026-09-24: «το hero να πάει στα 750px ύψος»). */
        .hero.has-image { min-height: 750px; }
        .hero.has-image .hero-copy { padding: 0; }
        /* Between the two (Mike, 24/9): .7 at the words' edge was heavy, .5
           «παραέγινε ανοιχτό»; .6 there, fading to .1 on the right. */
        .hero.has-image::after {
            background:
                linear-gradient(90deg, rgba(6, 16, 20, .6) 0%, rgba(6, 16, 20, .3) 50%, rgba(6, 16, 20, .1) 100%),
                linear-gradient(to bottom, rgba(6, 16, 20, 0) 60%, rgba(6, 16, 20, .22) 100%);
        }
        .hero.has-image h1 { font-size: clamp(2.4rem, 5vw, 3.9rem); line-height: 1.05; letter-spacing: -.03em; text-wrap: balance; }
        .hero.has-image .standfirst { font-size: clamp(1.05rem, 1.5vw, 1.25rem); max-width: 36rem; text-wrap: pretty; }
        .hero .eyebrow-line { color: color-mix(in srgb, var(--kaiki-accent) 45%, #fff); justify-content: flex-start; }
        .hero .cta { display: flex; flex-wrap: wrap; justify-content: flex-start; gap: .75rem; margin-top: 1.75rem; }
        /* At the weight of the older `.hero.has-image .cta .button` rules above,
           which turned every masthead button white on hover — white words on a
           white button — so each state is spelled out here. */
        .hero.has-image .cta .button-accent { background: var(--kaiki-accent); border-color: var(--kaiki-accent); color: #fff; }
        .hero.has-image .cta .button-accent:hover,
        .hero.has-image .cta .button-accent:focus-visible { background: color-mix(in srgb, var(--kaiki-accent) 82%, #000); border-color: transparent; color: #fff; }
        .hero.has-image .cta .button-glass { background: rgba(255, 255, 255, .1); border-color: rgba(255, 255, 255, .4); color: #fff; }
        .hero.has-image .cta .button-glass:hover,
        .hero.has-image .cta .button-glass:focus-visible { background: rgba(255, 255, 255, .22); border-color: #fff; color: #fff; }

        /* A focus ring that shows on what it sits on: white over a photograph
           and on the deep bands, the operator's primary on white. The accent
           ring the page used everywhere vanished around an accent button. */
        .hero.has-image .hero-copy a:focus-visible,
        .cta-band a:focus-visible,
        .band-dark a:focus-visible,
        footer.site a:focus-visible { outline: 2px solid #fff; outline-offset: 3px; }
        .button-accent:focus-visible { outline: 2px solid var(--kaiki-primary); outline-offset: 3px; }
        .hero.has-image .hero-copy .button-accent:focus-visible { outline-color: #fff; }
        .hero-badges {
            list-style: none; margin: 1.5rem 0 0; padding: 0;
            display: flex; flex-wrap: wrap; justify-content: flex-start; gap: .6rem 1.4rem;
            font-size: .9rem; font-weight: 500; color: rgba(255, 255, 255, .88);
        }
        .hero-badges li { display: inline-flex; align-items: center; gap: .5rem; }
        .hero-badges .icon { inline-size: 1.1rem; block-size: 1.1rem; color: color-mix(in srgb, var(--kaiki-accent) 45%, #fff); }
        .hero:not(.has-image) .hero-badges { color: var(--ink-soft); }

        /* The search card. Solid white: a date field with the sea showing
           through it is a date field nobody can read. Two narrow fields share
           the first row; everything else, and the button, runs full width. */
        .hero-search {
            inline-size: 100%; max-width: none; margin: 0;
            padding: clamp(1.25rem, 2.5vw, 1.75rem);
            background: #fff; border: 0; border-radius: 18px;
            box-shadow: 0 2px 4px rgba(11, 39, 64, .06), 0 24px 56px rgba(6, 16, 20, .28);
            color: var(--kaiki-text);
        }
        .hero:not(.has-image) .hero-search { border: 1px solid var(--line); box-shadow: 0 2px 4px rgba(11, 39, 64, .04), 0 18px 48px rgba(11, 39, 64, .12); }
        .hero-search-title {
            margin: 0 0 1.1rem; font-size: 1.3rem; line-height: 1.25; letter-spacing: -.01em;
            color: var(--kaiki-primary); text-shadow: none; text-align: left;
        }
        .hero-search .search-form {
            display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .9rem; align-items: end;
        }
        .hero-search .search-form > * { grid-column: 1 / -1; min-width: 0; }
        .hero-search .search-form > .field:nth-child(1),
        .hero-search .search-form > .field:nth-child(2) { grid-column: auto; }
        .hero-search .search-form input,
        .hero-search .search-form select { min-block-size: 3.1rem; border-radius: 10px; font-size: 1rem; }
        .hero-search .search-form label { font-size: .8rem; color: var(--ink-soft); }
        .hero-search .search-form button { inline-size: 100%; min-block-size: 3.25rem; border-radius: 10px; font-size: 1rem; }

        @media (max-width: 40rem) {
            .hero-search .search-form { grid-template-columns: minmax(0, 1fr); }
            .hero-search .search-form > .field:nth-child(1),
            .hero-search .search-form > .field:nth-child(2) { grid-column: 1 / -1; }
        }

        /* --- numbers ------------------------------------------------------
           Mike's pick («S3», 2026-09-16): the small line and the heading on the
           left, the figures two by two on the right. Each is a large number at
           a light weight in the operator's primary, a hairline over it and a
           few words under it — no icons and no accent colour, so the section
           reads as calm facts rather than a row of badges. */
        /* No band colour, so no band padding either: the gap between sections
           is the room, as for any plain section. Doubled, it left a white
           field under the masthead taller than the figures. */
        .stats-block { display: grid; gap: 2rem clamp(2rem, 6vw, 5rem); align-items: start; padding-block: 0; }
        .stats-block .section-head { margin: 0; }
        .stats-block .section-head h2 { max-width: 14ch; }
        @media (min-width: 62rem) {
            .stats-block { grid-template-columns: minmax(0, .9fr) minmax(0, 1.1fr); }
        }
        .stats {
            list-style: none; margin: 0; padding: 0;
            display: grid; gap: clamp(1.75rem, 3vw, 2.5rem) clamp(1.5rem, 3vw, 3rem);
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .stats.stats-1 { grid-template-columns: minmax(0, 1fr); }
        .stats li { display: flex; flex-direction: column; padding-block-start: 1.25rem; border-block-start: 1px solid var(--line); }
        .stats strong {
            display: block; font-size: clamp(2.1rem, 3.6vw, 2.75rem); font-weight: 400; line-height: 1.05;
            letter-spacing: -.02em; color: var(--kaiki-primary); font-variant-numeric: tabular-nums;
        }
        .stat-label { display: block; margin-top: .55rem; font-size: .98rem; line-height: 1.45; color: var(--ink-soft); }

        /* --- contact ------------------------------------------------------
           Mike's pick («K2», 2026-09-16): a full-width band in the operator's
           primary. The heading, the button and the social marks on the left;
           phone, email, address and meeting point as cards on the right, each
           with its icon on a soft tile and a small visible label. The whole
           card is the tap target. The operator's photograph, when there is
           one, is a faint texture in the band rather than half of the panel.
           Square corners, edge to edge. */
        .block.contact,
        .block.contact.has-image {
            display: block; position: relative; isolation: isolate; overflow: hidden;
            margin-inline: calc(50% - 50vw); border-radius: 0;
            padding-block: clamp(3.5rem, 8vw, 6rem);
            padding-inline: calc(50vw - 50%);
            background:
                radial-gradient(ellipse 55% 90% at 100% 0%, color-mix(in srgb, #fff 10%, transparent), transparent 70%),
                var(--kaiki-primary);
            color: rgba(255, 255, 255, .8);
        }
        .block.contact.has-image .contact-image {
            position: absolute; inset: 0; z-index: -1;
            inline-size: 100%; block-size: 100%; min-height: 0; border-radius: 0;
            object-fit: cover; opacity: .05; filter: grayscale(1);
        }
        .block.contact > *:not(.contact-image),
        .block.contact.has-image > *:not(.contact-image),
        .block.contact.has-image > *:not(.contact-image):first-of-type,
        .block.contact.has-image > *:not(.contact-image):last-child { padding: 0; }
        .block.contact .contact-inner,
        .block.contact.has-image .contact-inner {
            display: grid; gap: 2.25rem clamp(2rem, 5vw, 4.5rem); align-items: center;
        }
        @media (min-width: 62rem) {
            .block.contact .contact-inner,
            .block.contact.has-image .contact-inner {
                grid-template-columns: minmax(0, .8fr) minmax(0, 1.7fr);
                grid-template-areas: "words cards" "social cards";
            }
            .block.contact .contact-inner > div:first-child { grid-area: words; align-self: end; }
            .block.contact .contact-list { grid-area: cards; }
            .block.contact .social { grid-area: social; align-self: start; }
        }
        .block.contact .eyebrow { color: color-mix(in srgb, var(--kaiki-accent) 45%, #fff); letter-spacing: .04em; margin-bottom: .6rem; }
        .block.contact h2 { color: #fff; font-size: clamp(2rem, 3.4vw, 2.6rem); max-width: 14ch; margin-bottom: .9rem; }
        .block.contact .prose { color: rgba(255, 255, 255, .78); }
        .block.contact .contact-cta { margin-top: 1.5rem; }
        .block.contact .contact-cta .button { background: var(--kaiki-accent); border-color: var(--kaiki-accent); color: #fff; }
        .block.contact .contact-cta .button:hover,
        .block.contact .contact-cta .button:focus-visible { background: color-mix(in srgb, var(--kaiki-accent) 82%, #000); border-color: transparent; color: #fff; }

        .block.contact .contact-list {
            margin: 0; display: grid; gap: .9rem;
            grid-template-columns: repeat(auto-fit, minmax(min(100%, 14rem), 1fr));
        }
        .block.contact .contact-list li {
            position: relative;
            display: grid; grid-template-columns: minmax(0, 1fr); gap: .2rem; align-content: start;
            padding: 1.35rem 1.4rem 1.45rem; border-radius: 16px;
            background: rgba(255, 255, 255, .06); border: 1px solid rgba(255, 255, 255, .12);
            transition: background-color .15s ease, border-color .15s ease;
        }
        .block.contact .contact-list li:hover { background: rgba(255, 255, 255, .1); border-color: rgba(255, 255, 255, .24); }
        /* Line icons, no tile (2026-09-24), the same drawing as the reasons
           band's so the two panels read as one family. */
        .block.contact .contact-list .icon {
            inline-size: 1.6rem; block-size: 1.6rem; margin: 0 0 .85rem;
            color: color-mix(in srgb, var(--kaiki-accent) 45%, #fff);
        }
        .block.contact .contact-label { grid-column: 1; font-size: .8rem; font-weight: 500; letter-spacing: .02em; color: rgba(255, 255, 255, .62); }
        .block.contact .contact-list li > a,
        .block.contact .contact-list li > span:not(.contact-label) {
            grid-column: 1; font-size: 1.02rem; font-weight: 600; line-height: 1.4; color: #fff; overflow-wrap: anywhere;
        }
        .block.contact .contact-list li > a::after { content: ''; position: absolute; inset: 0; border-radius: inherit; }
        .block.contact .contact-list li > a:hover { color: #fff; }
        .block.contact .contact-list li > a:focus-visible { outline: none; }
        .block.contact .contact-list li:has(> a:focus-visible) { outline: 2px solid #fff; outline-offset: 3px; }
        .block.contact .contact-list .instructions { grid-column: 1; font-weight: 400; color: rgba(255, 255, 255, .65); }
        .block.contact .contact-list li span a { color: #fff; }

        .block.contact .social { margin: 0; }
        .block.contact .social a { border-color: rgba(255, 255, 255, .25); color: #fff; }
        .block.contact .social a:hover { border-color: #fff; background: rgba(255, 255, 255, .08); }
        .block.contact .social a:focus-visible { outline-color: #fff; }
        .block.contact .social .icon { color: #fff; }
        @media (prefers-reduced-motion: reduce) { .block.contact .contact-list li { transition: none; } }
        main:has(.wrap > .block.contact:last-child) { padding-block-end: 0; }

        /* Straight after the masthead, the figures are the operator's WordPress
           card (Mike, 2026-09-16): white, rounded, lifted over the masthead's
           lower edge, four figures split by hairlines, the number bold in the
           primary with a few words under it. No heading there — the card says
           what it is. Anywhere else on the page the section keeps its
           heading-beside-figures layout above. */
        .hero:has(+ .stats-block) .hero-inner { padding-block-end: clamp(6.5rem, 10vw, 8.5rem); }
        .hero + .stats-block {
            display: block; position: relative; z-index: 3;
            margin-block-start: calc(-1 * var(--section-gap) - 4rem);
            padding: 0; margin-inline: 0; inline-size: auto;
        }
        .hero + .stats-block .section-head {
            position: absolute; inline-size: 1px; block-size: 1px; overflow: hidden;
            clip-path: inset(50%); white-space: nowrap;
        }
        .hero + .stats-block .stats,
        .hero + .stats-block .stats.stats-1 {
            grid-template-columns: repeat(var(--stat-count, 4), minmax(0, 1fr)); gap: 0;
            background: #fff; border-radius: 20px; overflow: hidden;
            box-shadow: 0 2px 4px rgba(11, 39, 64, .04), 0 18px 48px rgba(11, 39, 64, .12);
        }
        .hero + .stats-block .stats-3 { --stat-count: 3; }
        .hero + .stats-block .stats-2 { --stat-count: 2; }
        .hero + .stats-block .stats-1 { --stat-count: 1; }
        .hero + .stats-block .stats li {
            align-items: center; text-align: center; padding: 1.75rem 1.5rem; border: 0;
        }
        .hero + .stats-block .stats li + li { border-inline-start: 1px solid var(--line); }
        .hero + .stats-block .stats strong { font-size: clamp(1.75rem, 2.6vw, 2.15rem); font-weight: 800; line-height: 1; letter-spacing: -.03em; }
        .hero + .stats-block .stat-label { margin-top: .5rem; font-size: .9rem; color: var(--ink-soft); }
        @media (max-width: 47.5rem) {
            .hero + .stats-block .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .hero + .stats-block .stats li:nth-child(3) { border-inline-start: 0; }
            .hero + .stats-block .stats li:nth-child(n+3) { border-block-start: 1px solid var(--line); }
        }

        /* --- steps ------------------------------------------------------ */

        .steps { list-style: none; margin: 0; padding: 0; display: grid; gap: 1.25rem; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); }
        .step { padding: 1.75rem; border-radius: 16px; background: #fff; box-shadow: var(--shadow-sm); }
        .step-number {
            display: grid; place-items: center; inline-size: 2.25rem; block-size: 2.25rem;
            margin-bottom: 1rem; border-radius: 50%;
            background: var(--kaiki-accent); color: #fff; font-weight: 700; font-size: .95rem;
        }
        .step h3, .feature h3 { font-size: 1.12rem; margin: 0 0 .4rem; letter-spacing: -.01em; color: var(--deep); }
        .step p, .feature p { margin: 0; font-size: .95rem; line-height: 1.6; color: var(--ink-soft); }

        /* --- reasons ------------------------------------------------------ */

        .features { list-style: none; margin: 0; padding: 0; display: grid; gap: 1.25rem; grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr)); }
        .feature { padding: 1.75rem; border-radius: 16px; background: #fff; border: 1px solid var(--line); }
        /* The icon on its own, no tile behind it (Mike, 2026-09-24): the card
           is already the box, and a box in a box was one frame too many. */
        .feature-icon {
            display: block; margin-bottom: 1rem; color: var(--kaiki-primary);
        }
        .feature-icon .icon { inline-size: 1.9rem; block-size: 1.9rem; display: block; }
        .band-dark .feature { background: rgba(255, 255, 255, .05); border-color: rgba(255, 255, 255, .1); }
        .band-dark .feature h3 { color: #fff; }
        .band-dark .feature p { color: rgba(255, 255, 255, .7); }
        .band-dark .feature-icon { color: color-mix(in srgb, var(--kaiki-accent) 45%, #fff); }

        /* --- reviews ------------------------------------------------------ */

        .quotes { list-style: none; margin: 0; padding: 0; display: grid; gap: 1.25rem; grid-template-columns: repeat(auto-fit, minmax(17rem, 1fr)); }
        .quote { display: flex; flex-direction: column; block-size: 100%; margin: 0; padding: 1.75rem; border-radius: 16px; background: #fff; border: 1px solid var(--line); }
        .quote-stars { margin: 0 0 .75rem; color: #E0A33B; letter-spacing: 2px; font-size: .95rem; }
        .quote blockquote { margin: 0 0 1.25rem; font-size: 1rem; line-height: 1.65; color: var(--kaiki-text); }
        .quote figcaption { margin-top: auto; display: flex; align-items: center; gap: .75rem; font-size: .88rem; color: var(--ink-soft); }
        .quote figcaption b { display: block; color: var(--deep); font-weight: 600; }
        .avatar {
            inline-size: 2.5rem; block-size: 2.5rem; flex: none; border-radius: 50%; object-fit: cover;
            display: grid; place-items: center; background: var(--mist); color: var(--kaiki-primary); font-weight: 700;
        }

        /* --- the call-to-action band -------------------------------------- */

        /* Edge to edge with square corners (Mike, 2026-09-16), the words held
           to the page's column by the same inline padding every band uses. */
        .cta-band {
            position: relative; isolation: isolate; overflow: hidden;
            border-radius: 0; background: var(--deep); color: #fff;
            margin-inline: calc(50% - 50vw);
            padding-block: clamp(4rem, 8vw, 6.5rem);
            padding-inline: calc(50vw - 50%);
        }
        .band + .cta-band, .cta-band + .band { margin-block-start: calc(-1 * var(--section-gap)); }

        /* The contact band's own rules live with the numbers above (K2). */
        main:has(.wrap > .block.contact:last-child), main:has(.wrap > .cta-band:last-child) { padding-block-end: 0; }
        .cta-image { position: absolute; inset: 0; inline-size: 100%; block-size: 100%; object-fit: cover; object-position: center center; z-index: -2; }
        .cta-band.has-image::before {
            content: ''; position: absolute; inset: 0; z-index: -1;
            background: linear-gradient(90deg, color-mix(in srgb, var(--deep) 94%, transparent) 0%, color-mix(in srgb, var(--deep) 78%, transparent) 50%, color-mix(in srgb, var(--deep) 30%, transparent) 100%);
        }
        .cta-copy { max-width: 40rem; }
        .cta-band h2 { color: #fff; font-size: clamp(1.75rem, 3.4vw, 2.6rem); font-weight: 800; letter-spacing: -.025em; margin: 0; }
        .cta-band .lead { margin-top: .9rem; color: rgba(255, 255, 255, .85); font-size: 1.08rem; line-height: 1.7; }
        .cta-band .lead p { margin: 0 0 .6rem; }
        .cta-band .lead p:last-child { margin: 0; }
        .cta-band .eyebrow-line { color: color-mix(in srgb, var(--kaiki-accent) 45%, #fff); }
        .cta-band .buttons { display: flex; flex-wrap: wrap; gap: .75rem; margin: 1.75rem 0 0; }

        /* --- trip cards ----------------------------------------------------
           A lift on hover this time, as the WordPress cards have: those are the
           cards Mike pointed at. The label over the photograph is the operator's
           own, and white so it reads on any picture. */
        /* No lift any more (Mike, 2026-09-24: the card that moves under the
           pointer went). The deeper shadow stays, so the card still answers. */
        li.trip { position: relative; border-radius: 16px; border: 1px solid var(--line); box-shadow: var(--shadow-sm); transition: box-shadow .2s ease; }
        /* Much lighter than `--shadow` (Mike, 24/9): the card answers the
           pointer, it does not lift off the page. */
        @media (hover: hover) {
            li.trip:hover { box-shadow: 0 1px 2px rgba(11, 39, 64, .05), 0 4px 12px rgba(11, 39, 64, .06); }
        }
        li.trip h3 { font-size: 1.15rem; letter-spacing: -.015em; color: var(--deep); }
        .trip-badge {
            position: absolute; inset-block-start: .85rem; inset-inline-start: .85rem; z-index: 1;
            margin: 0; padding: .3rem .7rem; border-radius: 999px;
            background: rgba(255, 255, 255, .95); color: var(--deep);
            font-size: .78rem; font-weight: 600; line-height: 1.3;
            box-shadow: 0 2px 8px rgba(0, 0, 0, .08);
            pointer-events: none;
        }
        .trip-price strong { color: var(--deep); font-size: 1.3rem; font-weight: 800; }

        /* Three to a row, at most, everywhere a trip is a card (Mike,
           2026-09-16): three on a desktop, two on a tablet, one on a phone —
           the home grid, the featured rail and the search results alike. Fixed
           tracks rather than `auto-fit`, which put four across a wide screen,
           and a search with two answers keeps two cards of the same size rather
           than two stretched ones. The cards are already equal in height with
           the price row pinned to the bottom (`.trip-foot`). */
        ul.trips, .trips.results { grid-template-columns: repeat(3, minmax(0, 1fr)); justify-content: stretch; }
        /* Four to a row on a wide screen (Mike, 2026-09-24), three from a
           tablet up to 80rem. The featured rail keeps its own three. */
        @media (min-width: 80rem) {
            ul.trips, .trips.results { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
        /* `ul.` again: the rule above outweighs a bare `.trips-rail`, and a
           rail with three explicit columns squeezed three cards and stretched
           the fourth. */
        ul.trips.trips-rail { grid-template-columns: none; grid-auto-columns: calc((100% - 3rem) / 3); }

        @media (max-width: 62rem) {
            ul.trips, .trips.results { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            ul.trips.trips-rail { grid-template-columns: none; grid-auto-columns: calc((100% - 1.5rem) / 2); }
        }

        @media (max-width: 40rem) {
            ul.trips, .trips.results { grid-template-columns: minmax(0, 1fr); }
            ul.trips.trips-rail { grid-template-columns: none; grid-auto-columns: 86%; }
        }

        /* --- FAQ, as cards that open ------------------------------------- */

        .faq-list { gap: .6rem; }
        .faq-item { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 0; transition: box-shadow .2s ease, border-color .2s ease; }
        .faq-item[open] { border-color: transparent; box-shadow: var(--shadow); }
        .faq-item summary { padding: 1.15rem 1.5rem; font-weight: 700; color: var(--deep); align-items: center; }
        .faq-item summary::after {
            content: '+'; flex: none; display: grid; place-items: center;
            inline-size: 1.65rem; block-size: 1.65rem; border-radius: 50%;
            background: var(--mist); color: var(--kaiki-primary); font-size: 1.1rem;
        }
        .faq-item[open] summary::after { content: '−'; }
        .faq-item .prose { margin: 0; padding: 0 1.5rem 1.35rem; }

        /* --- the footer --------------------------------------------------- */

        footer.site {
            background: var(--deep); border-top: 0; color: rgba(255, 255, 255, .72);
            padding-block: clamp(3.5rem, 7vw, 5rem) 2rem; font-size: .93rem;
        }
        footer.site h3 { color: #fff; font-size: .95rem; font-weight: 700; letter-spacing: 0; margin: .25rem 0 1rem; }
        footer.site a { color: rgba(255, 255, 255, .85); }
        footer.site a:hover { color: color-mix(in srgb, var(--kaiki-accent) 45%, #fff); }
        footer.site li { margin-bottom: .55rem; }
        footer.site .cols { grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr)); gap: 2.5rem; }
        @media (min-width: 62rem) { footer.site .cols { grid-template-columns: 1.4fr 1fr 1.2fr 1.2fr; } }

        .foot-brand, .foot-brand { align-items: flex-start; text-align: left; }
        @media (min-width: 62rem) { .foot-brand { align-items: flex-start; text-align: left; } }
        .foot-wordmark { display: flex; align-items: center; gap: .7rem; color: #fff; font-size: 1rem; }
        .foot-wordmark .brand-mark { background: rgba(255, 255, 255, .1); }
        .foot-wordmark .brand-tag { color: rgba(255, 255, 255, .55); }
        .foot-brand .social a { background: rgba(255, 255, 255, .08); color: #fff; border-color: transparent; }
        .foot-brand .social .icon { color: #fff; }
        .foot-brand .social a:hover { background: rgba(255, 255, 255, .18); color: #fff; }

        .foot-contact li { display: flex; gap: .6rem; align-items: center; }
        .foot-contact .icon { inline-size: 1.05rem; block-size: 1.05rem; flex: none; color: color-mix(in srgb, var(--kaiki-accent) 45%, #fff); }

        .foot-bottom {
            margin-top: clamp(2.5rem, 5vw, 4rem); padding-top: 1.5rem;
            border-top: 1px solid rgba(255, 255, 255, .12);
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem 2rem;
            font-size: .82rem; color: rgba(255, 255, 255, .55);
        }
        .foot-bottom p { margin: 0; }
        .foot-legal { display: flex; flex-wrap: wrap; gap: .4rem 1.25rem; }
        footer.site .foot-legal li { margin: 0; }
        footer.site .foot-legal a { color: rgba(255, 255, 255, .7); }
        .foot-bottom .powered { margin: 0; padding: 0; border: 0; color: rgba(255, 255, 255, .5); font-size: .82rem; }

        @media (prefers-reduced-motion: reduce) {
            li.trip, .faq-item { transition: none; }
        }

        /* The header now stays on screen, so everything that pins itself or
           scrolls to an anchor has to clear it: the trip page's booking column,
           and every `#trips`, `#faq` and `#gallery` a link jumps to. */
        html { scroll-padding-top: 6rem; }

        @media (min-width: 60rem) {
            .product-aside { top: 6.25rem; max-block-size: calc(100svh - 7.75rem); }
        }

        /* A phone's masthead is as tall as its words, not most of the screen:
           the search card follows it and should be in reach. */
        @media (max-width: 40rem) {
            .hero.has-image { min-height: 0; }
        }

        /* --- the trip page's booking card ----------------------------------
           It has to stand out from the page (Mike, 2026-09-16): the card of the
           operator's WordPress trip page — white, a hairline border, 20px and a
           soft two-layer shadow. The sticky column around it is unchanged.

           When the widget has become the bottom sheet, the price and the four
           lines are already hidden and the sheet carries its own look; a
           shadowed card left around what remains would be an empty box, so the
           card steps back to the page. */
        .booking {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 20px;
            box-shadow: 0 2px 4px rgba(11, 39, 64, .04), 0 12px 32px rgba(11, 39, 64, .09);
        }

        .booking:has([data-kaiki-sheet="true"]) {
            background: transparent; border-color: transparent; box-shadow: none;
            padding-inline: 0; padding-block: 0;
        }

        /* The aside scrolls inside itself on a desktop, which would clip the
           card's shadow at the column's edge; the room is given back inside. */
        @media (min-width: 60rem) {
            .product-aside { padding: .25rem 1.5rem 2.5rem; margin-inline: -1.5rem; }
        }


        /* ==================================================================
           The header's layout (Mike, 2026-09-24, from the first home mockup):
           the name on the left, the links in the middle, the language switch
           as two plain words and the button on the right. Name and end take
           equal shares, so the links sit in the true centre.
           ================================================================== */
        header.site .brand { flex: 1 1 0; min-width: 0; }
        /* Closer and a little bolder (Mike, 24/9): 1.9rem at 500 read as loose. */
        header.site .site-nav { margin-inline: auto; gap: 1.35rem; font-size: .95rem; font-weight: 600; }
        header.site .site-nav a { color: var(--kaiki-text); white-space: nowrap; font-weight: 600; }
        header.site .site-nav a:hover, header.site .site-nav a[aria-current] { color: var(--kaiki-accent); }
        .header-end { flex: 1 1 0; display: flex; align-items: center; justify-content: flex-end; gap: 1.1rem; }
        .header-end .langs { gap: 0; font-weight: 600; font-size: .85rem; }
        .header-end .langs a { border: 0; border-radius: 0; background: none; padding: .15rem .55rem; color: var(--ink-faint); }
        .header-end .langs a + a { border-inline-start: 1px solid var(--rule); }
        .header-end .langs a[aria-current="true"] { background: none; color: var(--kaiki-primary); }
        /* Below a laptop the equal shares squeeze the name onto three lines;
           there the name keeps its own width and the links take what is left. */
        @media (min-width: 40.01rem) and (max-width: 64rem) {
            header.site .brand, .header-end { flex: none; }
            header.site .site-nav { gap: .85rem; font-size: .86rem; }
            .header-end { gap: .5rem; }
            .header-end .langs a { padding-inline: .4rem; }
            .header-end .header-book { padding-inline: .85rem; }
            .header-end .header-book::after { display: none; }
        }

        /* ==================================================================
           «Πώς λειτουργεί» as a route (Mike, 2026-09-24, mockup version Ε).
           Numbered circles on a dashed line, each with its step under it; on
           a phone the line runs down the left. The complete route (circles
           filled, lines solid) is the default; `/hosted/route.js` sets
           data-animate="ready" to empty it and "on" to travel it in four
           seconds, once, when it comes into view.
           ================================================================== */
        .steps-route .section-head { margin-block-end: clamp(2rem, 4vw, 2.75rem); }
        .route { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: minmax(0, 1fr); position: relative; }
        .route .stop { position: relative; display: grid; grid-template-columns: 3rem minmax(0, 1fr); column-gap: 1rem; padding-block-end: 1.75rem; }
        .route .stop:last-child { padding-block-end: 0; }
        /* the dashed track, from this circle to the next */
        .route .stop:not(:last-child)::before {
            content: ""; position: absolute; inset-inline-start: calc(1.5rem - 1px); inset-block: 3rem 0;
            border-inline-start: 2px dashed color-mix(in srgb, var(--kaiki-accent) 28%, #fff);
        }
        /* the solid line over it, which is what travels */
        .route .stop:not(:last-child)::after {
            content: ""; position: absolute; z-index: 0; inset-inline-start: calc(1.5rem - 1.5px); inset-block: 3rem 0; inline-size: 3px;
            border-radius: 3px; background: var(--kaiki-accent); transform-origin: top;
        }
        .stop-dot {
            position: relative; z-index: 1; display: grid; place-items: center;
            inline-size: 3rem; block-size: 3rem; border-radius: 50%;
            border: 2px solid var(--kaiki-accent); background: var(--kaiki-accent); color: #fff;
            font-weight: 800; font-size: 1.05rem; font-variant-numeric: tabular-nums;
            box-shadow: 0 0 0 6px color-mix(in srgb, var(--kaiki-accent) 10%, #fff);
        }
        .stop-dot .icon { inline-size: 1.3rem; block-size: 1.3rem; stroke-width: 2.4; }
        .stop-copy h3 { margin: .7rem 0 .3rem; font-size: 1.12rem; letter-spacing: -.01em; color: var(--deep); }
        .stop-copy p { margin: 0; color: var(--ink-soft); font-size: .97rem; line-height: 1.6; max-width: 24rem; }
        @media (min-width: 48rem) {
            .route-2 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .route-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .route-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); }
            .route .stop { grid-template-columns: minmax(0, 1fr); justify-items: center; text-align: center; padding: 0 .75rem; }
            .route .stop-copy { display: flex; flex-direction: column; align-items: center; }
            .route .stop-copy h3 { margin-block-start: 1.1rem; }
            .route .stop:not(:last-child)::before {
                inset-inline: calc(50% + 1.5rem) calc(-50% + 1.5rem); inset-block: calc(1.5rem - 1px) auto;
                border-inline-start: 0; border-block-start: 2px dashed color-mix(in srgb, var(--kaiki-accent) 28%, #fff);
            }
            .route .stop:not(:last-child)::after {
                inset-inline: calc(50% + 1.5rem) calc(-50% + 1.5rem); inset-block: calc(1.5rem - 1.5px) auto;
                inline-size: auto; block-size: 3px; transform-origin: left;
            }
        }
        @media (min-width: 62rem) {
            .steps-route.has-image .band-inner { grid-template-columns: 1.1fr 1fr; }
            .steps-route.has-image .route { grid-template-columns: minmax(0, 1fr); }
            .steps-route.has-image .route .stop { grid-template-columns: 3rem minmax(0, 1fr); justify-items: start; text-align: start; padding: 0 0 1.75rem; }
            .steps-route.has-image .route .stop-copy { align-items: flex-start; }
            .steps-route.has-image .route .stop:not(:last-child)::before { inset-inline: calc(1.5rem - 1px) auto; inset-block: 3rem 0; border-block-start: 0; border-inline-start: 2px dashed color-mix(in srgb, var(--kaiki-accent) 28%, #fff); }
            .steps-route.has-image .route .stop:not(:last-child)::after { inset-inline: calc(1.5rem - 1.5px) auto; inset-block: 3rem 0; inline-size: 3px; block-size: auto; transform-origin: top; }
        }

        /* Ready: emptied, waiting to be travelled. */
        .route[data-animate="ready"] .stop-dot,
        .route[data-animate="on"] .stop-dot { background: #fff; color: var(--kaiki-accent); box-shadow: none; }
        .route[data-animate="ready"] .stop::after { transform: scaleY(0); }
        @media (min-width: 48rem) { .route[data-animate="ready"] .stop::after { transform: scaleX(0); } }

        /* On: four seconds, the first circle to the last. */
        .route[data-animate="on"] .stop-dot { animation: route-fill .5s ease forwards; }
        .route[data-animate="on"] .stop::after { transform: scaleY(0); animation: route-grow-y 1.3s ease-in-out forwards; }
        .route[data-animate="on"] .stop:nth-child(1) .stop-dot { animation-delay: .1s; }
        .route[data-animate="on"] .stop:nth-child(1)::after { animation-delay: .5s; }
        .route[data-animate="on"] .stop:nth-child(2) .stop-dot { animation-delay: 1.8s; }
        .route[data-animate="on"] .stop:nth-child(2)::after { animation-delay: 2.2s; }
        .route[data-animate="on"] .stop:nth-child(3) .stop-dot { animation-delay: 3.5s; }
        .route[data-animate="on"] .stop:nth-child(3)::after { animation-delay: 3.9s; }
        .route[data-animate="on"] .stop:nth-child(4) .stop-dot { animation-delay: 5.2s; }
        .route-4[data-animate="on"] .stop-dot { animation-duration: .4s; }
        @media (min-width: 48rem) {
            .route[data-animate="on"] .stop::after { transform: scaleX(0); animation-name: route-grow-x; }
        }
        @media (min-width: 62rem) {
            .steps-route.has-image .route[data-animate="ready"] .stop::after,
            .steps-route.has-image .route[data-animate="on"] .stop::after { transform: scaleY(0); animation-name: route-grow-y; }
            .steps-route.has-image .route[data-animate="ready"] .stop::after { animation: none; }
        }
        @keyframes route-grow-x { to { transform: scaleX(1); } }
        @keyframes route-grow-y { to { transform: scaleY(1); } }
        @keyframes route-fill {
            to { background: var(--kaiki-accent); color: #fff; box-shadow: 0 0 0 6px color-mix(in srgb, var(--kaiki-accent) 10%, #fff); }
        }

        /* ==================================================================
           The footer's first row (Mike, 2026-09-24): «Έχετε ερώτηση;» with a
           support icon, and phone, WhatsApp and email as plain links, on the
           operator's secondary colour, flush with the top of the footer.
           ================================================================== */
        footer.site:has(> .foot-reach) { padding-block-start: 0; }
        .foot-reach { background: var(--kaiki-secondary); margin-block-end: clamp(2.5rem, 6vw, 4rem); }
        .foot-reach .wrap { display: flex; flex-wrap: wrap; align-items: center; gap: .9rem 2rem; padding-block: clamp(1.75rem, 3vw, 2.4rem); }
        .foot-reach-title { display: inline-flex; align-items: center; gap: .65rem; margin: 0 auto 0 0 !important; color: #fff; font-weight: 700; font-size: 1.05rem; letter-spacing: -.01em; }
        .foot-reach-title .icon { inline-size: 1.35rem; block-size: 1.35rem; color: color-mix(in srgb, var(--kaiki-accent) 45%, #fff); }
        .foot-reach ul { display: flex; flex-wrap: wrap; gap: .6rem 1.75rem; }
        .foot-reach li { margin: 0 !important; }
        .foot-reach a { display: inline-flex; align-items: center; gap: .55rem; color: #fff !important; font-weight: 600; overflow-wrap: anywhere; }
        .foot-reach a:hover { color: color-mix(in srgb, var(--kaiki-accent) 45%, #fff) !important; }
        .foot-reach a .icon { inline-size: 1.15rem; block-size: 1.15rem; flex: none; color: color-mix(in srgb, var(--kaiki-accent) 45%, #fff); }
        @media (max-width: 40rem) {
            .foot-reach .wrap { flex-direction: column; align-items: flex-start; }
            .foot-reach ul { flex-direction: column; gap: .7rem; }
        }

        /* ==================================================================
           The call-to-action band with a photograph, split (Mike, 2026-09-24,
           «Ιδιωτικές ναυλώσεις» from the first home mockup): the words on the
           operator's deep colour on the left, the photograph beside them on the
           right, rounded, inside the page's width rather than edge to edge.
           On a phone the photograph goes under the words.
           ================================================================== */
        .cta-band.has-image {
            display: grid; grid-template-columns: minmax(0, 1fr);
            margin-inline: 0; padding: 0;
            border-radius: clamp(18px, 2vw, 26px); overflow: hidden;
            background: var(--deep);
        }
        .cta-band.has-image::before { display: none; }
        .cta-band.has-image .cta-image {
            position: static; z-index: auto; order: 2;
            inline-size: 100%; block-size: 100%; min-block-size: 15rem;
            object-fit: cover; object-position: center center;
        }
        .cta-band.has-image .cta-copy {
            max-width: 34rem; align-self: center;
            padding: clamp(1.75rem, 4.5vw, 3.5rem);
        }
        @media (min-width: 62rem) {
            .cta-band.has-image { grid-template-columns: minmax(0, .9fr) minmax(0, 1.1fr); min-block-size: 22rem; }
        }
        /* The first button in the accent, as on every other solid button; the
           second stays the outlined one. */
        .cta-band.has-image .buttons .button-light {
            background: var(--kaiki-accent); border-color: var(--kaiki-accent); color: #fff;
        }
        .cta-band.has-image .buttons .button-light:hover,
        .cta-band.has-image .buttons .button-light:focus-visible {
            background: color-mix(in srgb, var(--kaiki-accent) 86%, #000); color: #fff;
        }
        .band + .cta-band.has-image, .cta-band.has-image + .band { margin-block-start: 0; }
        /* Half the width, so a smaller heading (Mike, 24/9): the band's own size
           broke «Όλο το σκάφος, μόνο για την παρέα σας» onto three lines. */
        .cta-band.has-image h2 { font-size: clamp(1.55rem, 2.3vw, 2.05rem); line-height: 1.15; }
        .cta-band.has-image .lead { font-size: 1rem; }

        /* ==================================================================
           The card's facts on one line, side by side (Mike, 2026-09-24, point 3
           of his reference): the duration, then the port and boat, which gives
           way with an ellipsis rather than wrapping under it. The full text is
           still there for a screen reader.
           ================================================================== */
        /* Two facts (duration, port), one line, never wrapping (Mike, 24/9:
           the boat is off the card). The duration never shrinks; the port
           gives way with an ellipsis only if a card is too narrow for both. */
        li.trip .facts { display: flex; flex-wrap: nowrap; align-items: center; gap: .65rem; white-space: nowrap; min-width: 0; overflow: hidden; }
        li.trip .facts span { flex: none; gap: .3rem; }
        li.trip .facts .fact-port { display: block; flex: 0 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; }
        li.trip .facts .fact-port .icon { display: inline-block; vertical-align: -.15em; margin-inline-end: .3rem; }

        /* ==================================================================
           The trip card's foot (Mike, 2026-09-24, from the first home mockup):
           the price on the left in the accent, what it is for beside it, and a
           round arrow on the right instead of a full-width button.
           ================================================================== */
        .trip-foot { flex-direction: row; align-items: center; justify-content: space-between; gap: .75rem; min-height: 0; }
        .trip-foot.is-party-price { min-height: 0; }
        .trip-foot .trip-price { min-width: 0; }
        .trip-price strong { color: var(--kaiki-accent); }
        .trip-price .per { font-size: .85rem; color: var(--ink-soft); }
        .trip-price .on-request { color: var(--kaiki-accent); font-weight: 700; }
        .trip-go {
            flex: none; margin-left: auto;
            display: grid; place-items: center; inline-size: 2.6rem; block-size: 2.6rem; border-radius: 50%;
            border: 1.5px solid var(--kaiki-accent); color: var(--kaiki-accent);
            transition: background-color .15s ease, color .15s ease;
        }
        .trip-go .icon { inline-size: 1.1rem; block-size: 1.1rem; transition: transform .15s ease; }
        .trip-go:hover, .trip-go:focus-visible { background: var(--kaiki-accent); color: #fff; }
        .trip-go:hover .icon { transform: translateX(2px); }
        @media (prefers-reduced-motion: reduce) { .trip-go, .trip-go .icon { transition: none; } }

        /* ==================================================================
           The search under the masthead (Mike, 2026-09-24): one horizontal
           white bar, overlapping the photograph's lower edge by about 3.5rem,
           as wide as the page column so it lines up with the trips below.
           ================================================================== */
        .hero:has(+ .hero-search-below) .hero-inner { grid-template-columns: minmax(0, 1fr); }
        .hero.has-image:has(+ .hero-search-below) .hero-inner { padding-block-end: clamp(5.5rem, 9vw, 7.5rem); }
        .hero-search.hero-search-below {
            position: relative; z-index: 3;
            inline-size: auto; max-width: none;
            /* Exactly the column's width, the trips' below it (Mike, 24/9). */
            margin: calc(-1 * var(--section-gap) - 3.5rem) 0 0;
            padding: .6rem; border-radius: 18px;
            background: #fff;
            box-shadow: 0 2px 6px rgba(11, 39, 64, .06), 0 24px 56px rgba(11, 39, 64, .18);
        }
        .hero-search.hero-search-below .search-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0; align-items: stretch; }
        .hero-search.hero-search-below .search-form > * { grid-column: auto; }
        .hero-search.hero-search-below .search-form > :last-child { grid-column: 1 / -1; padding: .5rem .35rem .35rem; }
        .hero-search.hero-search-below .field { padding: .6rem 1rem; }
        .hero-search.hero-search-below .field:nth-child(odd) { border-inline-start: 0; }
        .hero-search.hero-search-below .field:nth-child(even) { border-inline-start: 1px solid var(--rule); }
        .hero-search.hero-search-below .field:nth-child(n+3) { border-block-start: 1px solid var(--rule); }
        .hero-search.hero-search-below .search-form input,
        .hero-search.hero-search-below .search-form select { border: 0; background: transparent; padding-inline: 0; min-block-size: 2.2rem; box-shadow: none; }
        .hero-search.hero-search-below .search-form button { inline-size: 100%; min-block-size: 3.25rem; border-radius: 12px; }
        @media (min-width: 64rem) {
            .hero-search.hero-search-below .search-form { display: flex; flex-wrap: nowrap; align-items: stretch; gap: 0; }
            .hero-search.hero-search-below .field { flex: 1 1 0; min-width: 0; padding: .65rem 1.2rem; border-block-start: 0 !important; border-inline-start: 1px solid var(--rule) !important; }
            .hero-search.hero-search-below .field:first-child { border-inline-start: 0 !important; }
            /* `align-self: center`: the base `.search-form .submit` pins the
               button to the row's floor, which in a bar of two-line fields put
               it visibly below the middle (Mike, 24/9). */
            .hero-search.hero-search-below .search-form > :last-child { flex: 0 0 auto; display: flex; align-items: center; align-self: center; padding: 0 0 0 .6rem; }
            .hero-search.hero-search-below .search-form button { inline-size: auto; block-size: auto; min-block-size: 3.4rem; padding-inline: 2.2rem; }
        }
        @media (max-width: 40rem) {
            .hero-search.hero-search-below { margin-inline: 0; }
            .hero-search.hero-search-below .search-form { grid-template-columns: minmax(0, 1fr); }
            .hero-search.hero-search-below .field { border-inline-start: 0 !important; }
            .hero-search.hero-search-below .field:nth-child(n+2) { border-block-start: 1px solid var(--rule); }
        }
        /* The figures card, when a page has one, sits under the bar rather than
           climbing over the photograph beside it. */
        .hero-search-below + .stats-block { margin-block-start: 0; }

        /* The bar's look (Mike, 2026-09-24, from the first home mockup): the
           icon in the operator's accent, two lines tall, on the left; the field's
           name in bold beside it and the value under it in grey; a thin rule
           between fields; the button in the accent with an arrow.

           The label becomes `display: contents`, so its icon and its words are
           grid items of the field itself — the icon spans both rows, the words
           take the first and the control the second. Clicking the words still
           focuses the control: the label is still the label. */
        .hero-search.hero-search-below .field:not(.submit) {
            display: grid; grid-template-columns: 1.6rem minmax(0, 1fr); column-gap: .75rem; row-gap: .05rem; align-items: center;
        }
        .hero-search.hero-search-below .field:not(.submit) label { display: contents; font-size: .9rem; font-weight: 600; letter-spacing: 0; color: var(--kaiki-text); }
        .hero-search.hero-search-below .field:not(.submit) label .icon { grid-row: 1 / span 2; inline-size: 1.45rem; block-size: 1.45rem; color: var(--kaiki-accent); }
        .hero-search.hero-search-below .field:not(.submit) :is(input, select) {
            grid-column: 2; block-size: 1.6rem; min-block-size: 0; font-size: .88rem; color: var(--ink-faint);
            border: 0; background-color: transparent; padding: 0; border-radius: 0; cursor: pointer;
        }
        .hero-search.hero-search-below .field:not(.submit) select {
            appearance: none; -webkit-appearance: none; padding-inline-end: 1.5rem;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%238593A6' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right center;
        }
        .hero-search.hero-search-below .field.submit { border: 0 !important; }
        .hero-search.hero-search-below .search-form button {
            background: var(--kaiki-accent); color: #fff; font-weight: 700; gap: .6rem;
            box-shadow: 0 6px 16px color-mix(in srgb, var(--kaiki-accent) 25%, transparent);
        }
        .hero-search.hero-search-below .search-form button:hover { background: color-mix(in srgb, var(--kaiki-accent) 85%, #000); }
        .hero-search.hero-search-below .search-form button .icon { display: none; }
        .hero-search.hero-search-below .search-form button::after { content: "→"; font-size: 1.1em; line-height: 1; }

        /* ==================================================================
           «Σχετικά με εμάς» (2026-09-24): the timeline, the boats, the people,
           the licences and the meeting point. Mockup approved by Mike the same
           day (docs/mockups/about-page.html). They work on the home page too.
           ================================================================== */

        /* The masthead without the search: one column, the words given room. */
        .hero.hero-plain .hero-inner { grid-template-columns: minmax(0, 1fr); }
        .hero.hero-plain .hero-copy { max-width: 46rem; }
        .hero.hero-plain h1 { font-size: clamp(2.4rem, 6vw, 4.4rem); line-height: 1.02; }

        /* --- timeline: down on a phone, across from a tablet up --- */
        .timeline { list-style: none; margin: 0; padding: 0; display: grid; }
        .timeline li { position: relative; padding: 0 0 1.6rem 1.9rem; border-inline-start: 2px solid var(--line); margin-inline-start: .4rem; }
        .timeline li:last-child { border-inline-start-color: transparent; padding-block-end: 0; }
        .timeline li::before {
            content: ""; position: absolute; inset-inline-start: -.47rem; inset-block-start: .3rem;
            inline-size: .8rem; block-size: .8rem; border-radius: 50%;
            background: #fff; border: 2px solid var(--kaiki-accent);
        }
        .timeline li.is-now::before { background: var(--kaiki-accent); }
        .timeline-year { display: block; font-weight: 700; color: var(--kaiki-accent); font-variant-numeric: tabular-nums; margin-block-end: .15rem; }
        .timeline h3 { margin: 0 0 .2rem; font-size: 1.05rem; color: var(--kaiki-primary); }
        .timeline p { margin: 0; color: var(--ink-soft); max-width: 32rem; }
        /* --- the timeline travelled (Mike, 2026-09-24), as «Πώς λειτουργεί»:
           the track is dashed, a solid line runs from each dot to the next,
           and each dot fills as the line reaches it. Complete by default;
           route.js empties it ("ready") and travels it ("on"), .8s a year. */
        .timeline li { border-inline-start-style: dashed; border-inline-start-color: color-mix(in srgb, var(--kaiki-accent) 28%, #fff); }
        .timeline li:last-child { border-inline-start-color: transparent; }
        .timeline li::before { background: var(--kaiki-accent); z-index: 1; }
        .timeline li:not(:last-child)::after {
            content: ""; position: absolute; z-index: 0; background: var(--kaiki-accent); border-radius: 3px;
            inset-inline-start: -2.5px; inset-block: .7rem -.3rem; inline-size: 3px; transform-origin: top;
        }
        @media (min-width: 48rem) {
            ol.timeline { border-block-start-style: dashed; border-block-start-color: color-mix(in srgb, var(--kaiki-accent) 28%, #fff); }
            .timeline li:not(:last-child)::after {
                inset-inline: .8rem calc(-1.5rem); inset-block: calc(-1.9rem - 2.5px) auto; inline-size: auto; block-size: 3px; transform-origin: left;
            }
        }
        .timeline[data-animate="ready"] li::before,
        .timeline[data-animate="on"] li::before { background: #fff; }
        .timeline[data-animate="ready"] li::after { transform: scaleY(0); }
        .timeline[data-animate="on"] li::before { animation: timeline-dot .4s ease forwards; }
        .timeline[data-animate="on"] li::after { transform: scaleY(0); animation: route-grow-y .7s ease-in-out forwards; }
        @media (min-width: 48rem) {
            .timeline[data-animate="ready"] li::after { transform: scaleX(0); }
            .timeline[data-animate="on"] li::after { transform: scaleX(0); animation-name: route-grow-x; }
        }
        .timeline[data-animate="on"] li:nth-child(1)::before { animation-delay: .1s; }
        .timeline[data-animate="on"] li:nth-child(1)::after { animation-delay: .3s; }
        .timeline[data-animate="on"] li:nth-child(2)::before { animation-delay: 1s; }
        .timeline[data-animate="on"] li:nth-child(2)::after { animation-delay: 1.1s; }
        .timeline[data-animate="on"] li:nth-child(3)::before { animation-delay: 1.8s; }
        .timeline[data-animate="on"] li:nth-child(3)::after { animation-delay: 1.9s; }
        .timeline[data-animate="on"] li:nth-child(4)::before { animation-delay: 2.6s; }
        .timeline[data-animate="on"] li:nth-child(4)::after { animation-delay: 2.7s; }
        .timeline[data-animate="on"] li:nth-child(5)::before { animation-delay: 3.4s; }
        .timeline[data-animate="on"] li:nth-child(5)::after { animation-delay: 3.5s; }
        .timeline[data-animate="on"] li:nth-child(6)::before { animation-delay: 4.2s; }
        .timeline[data-animate="on"] li:nth-child(6)::after { animation-delay: 4.3s; }
        .timeline[data-animate="on"] li:nth-child(7)::before { animation-delay: 5s; }
        .timeline[data-animate="on"] li:nth-child(7)::after { animation-delay: 5.1s; }
        .timeline[data-animate="on"] li:nth-child(8)::before { animation-delay: 5.8s; }
        @keyframes timeline-dot { to { background: var(--kaiki-accent); box-shadow: 0 0 0 5px color-mix(in srgb, var(--kaiki-accent) 12%, #fff); } }

        @media (min-width: 48rem) {
            .timeline { grid-auto-flow: column; grid-auto-columns: minmax(0, 1fr); gap: 1.5rem; border-block-start: 2px solid var(--line); padding-block-start: 1.9rem; }
            .timeline li { border: 0; padding: 0; margin: 0; }
            .timeline li::before { inset-inline-start: 0; inset-block-start: -2.4rem; }
        }

        /* --- boats --- */
        .fleet { display: grid; gap: 1.25rem; }
        @media (min-width: 40rem) { .fleet-2, .fleet-3 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (min-width: 62rem) { .fleet-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        .boat-card { background: #fff; border-radius: 18px; overflow: hidden; box-shadow: var(--shadow-sm), 0 0 0 1px var(--line); display: flex; flex-direction: column; }
        .boat-photo { inline-size: 100%; aspect-ratio: 3 / 2; object-fit: cover; display: block; }
        .boat-photo-empty { display: grid; place-items: center; background: linear-gradient(160deg, color-mix(in srgb, var(--kaiki-primary) 70%, #fff), var(--kaiki-primary)); color: #fff; font-weight: 800; font-size: 1.6rem; letter-spacing: -.02em; }
        .boat-body { padding: 1.1rem 1.25rem 1.25rem; display: grid; gap: .75rem; flex: 1; align-content: start; }
        .boat-body h3 { margin: 0; font-size: 1.2rem; color: var(--kaiki-primary); }
        .boat-reg { margin: .1rem 0 0; font-size: .82rem; color: var(--ink-faint); font-variant-numeric: tabular-nums; letter-spacing: .02em; }
        .boat-specs { display: flex; flex-wrap: wrap; gap: .4rem 1.6rem; margin: 0; }
        .boat-specs dt { font-size: .78rem; color: var(--ink-faint); }
        .boat-specs dd { margin: 0; font-weight: 700; font-size: 1.05rem; font-variant-numeric: tabular-nums; }
        .boat-licence { margin: 0; font-size: .88rem; color: var(--ink-soft); }
        .boat-trips { list-style: none; margin: auto 0 0; padding: .75rem 0 0; border-block-start: 1px solid var(--line); display: grid; gap: .35rem; }
        .boat-trips a { display: flex; justify-content: space-between; gap: .75rem; font-weight: 600; font-size: .95rem; color: var(--kaiki-accent); }
        .boat-trips a span { flex: none; transition: transform .15s ease; }
        .boat-trips a:hover span { transform: translateX(2px); }
        .boat-trips .more { font-size: .88rem; color: var(--ink-faint); }

        /* --- people --- */
        .crew { list-style: none; margin: 0; padding: 0; display: grid; gap: 1.5rem; }
        @media (min-width: 36rem) { .crew-2, .crew-3, .crew-4 { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (min-width: 62rem) { .crew-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); } .crew-4 { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        .person { display: grid; gap: .9rem; align-content: start; }
        .person-photo { inline-size: 100%; aspect-ratio: 4 / 5; object-fit: cover; border-radius: 18px; display: block; }
        .person-initials { display: grid; place-items: center; background: linear-gradient(160deg, color-mix(in srgb, var(--kaiki-primary) 70%, #fff), var(--kaiki-primary)); color: #fff; font-weight: 800; font-size: clamp(2rem, 5vw, 3rem); letter-spacing: -.03em; }
        .person h3 { margin: 0; font-size: 1.1rem; color: var(--kaiki-primary); }
        .person-role { margin: .15rem 0 .35rem; font-size: .9rem; font-weight: 600; color: var(--kaiki-accent); }
        .person-copy p:last-child { margin-block-end: 0; }
        .person-copy p:not(.person-role) { color: var(--ink-soft); margin-block-start: 0; }
        @media (max-width: 35.99rem) {
            /* A phone: photograph beside the words, so four people are not four screens. */
            .crew-block:not(.no-photos) .person { grid-template-columns: 5.5rem minmax(0, 1fr); align-items: center; gap: 1rem; }
            .crew-block:not(.no-photos) .person-photo { aspect-ratio: 1; border-radius: 50%; font-size: 1.6rem; }
        }
        .crew-block.no-photos .person { border-block-start: 2px solid var(--kaiki-primary); padding-block-start: .9rem; }

        /* --- licences --- */
        .credentials { list-style: none; margin: 0; padding: 0; display: grid; border-block-start: 1px solid var(--line); }
        @media (min-width: 48rem) { .credentials { grid-template-columns: repeat(2, minmax(0, 1fr)); column-gap: 3rem; } }
        .credentials li { display: grid; grid-template-columns: 1.5rem minmax(0, 1fr); gap: .85rem; padding-block: 1rem; border-block-end: 1px solid var(--line); }
        .credentials svg { color: var(--kaiki-accent); inline-size: 1.35rem; block-size: 1.35rem; margin-block-start: .1rem; }
        .credentials strong { display: block; color: var(--kaiki-primary); }
        .credential-number { display: block; color: var(--ink-soft); font-size: .92rem; font-variant-numeric: tabular-nums; }

        /* --- meeting point: the port's photograph across the page, a card on it --- */
        .meeting-block { position: relative; display: flex; align-items: flex-end; min-block-size: 30rem; background: var(--deep); overflow: hidden; }
        .meeting-photo { position: absolute; inset: 0; inline-size: 100%; block-size: 100%; object-fit: cover; }
        .meeting-card { position: relative; z-index: 1; background: #fff; border-radius: 18px; padding: clamp(1.25rem, 3vw, 1.75rem); max-inline-size: 27rem; box-shadow: 0 18px 44px rgba(6, 16, 20, .28); }
        .meeting-card h2 { font-size: var(--step-2); margin: 0 0 .6rem; color: var(--kaiki-primary); }
        .meeting-card p { margin: 0 0 .35rem; color: var(--ink-soft); }
        .meeting-position { font-size: .85rem; color: var(--ink-faint) !important; font-variant-numeric: tabular-nums; }
        .meeting-hint { display: flex; gap: .55rem; align-items: flex-start; background: var(--sand); border-radius: 10px; padding: .7rem .85rem; margin: .9rem 0 1rem !important; color: var(--kaiki-text) !important; font-size: .93rem; }
        .meeting-hint svg { flex: none; inline-size: 1.1rem; block-size: 1.1rem; margin-block-start: .15rem; color: var(--kaiki-accent); }
        .meeting-hint p { margin: 0; }
        .meeting-cta { margin: 0 !important; }
        /* ==================================================================
           The dark boxes, all in one place and last, so no earlier rule can
           flatten them (Mike, 2026-09-24): «Γιατί …» (band-dark), the private
           trips band (the split call to action), and «Πώς λειτουργεί», which
           became a box of the same kind — centred, colour only, no photograph.

           Each is the deep colour lifting towards the primary, with a large
           glow of the accent that drifts across it. The glow is its own layer
           (`::before`) moved with `transform`, which the browser animates on
           the compositor: smoother than moving a background, and it never
           repaints the words over it. Still for reduced motion.
           ================================================================== */
        .band-dark,
        .cta-band.has-image,
        .steps-route:not(.has-image) {
            position: relative; isolation: isolate; overflow: hidden;
            background: linear-gradient(155deg, var(--deep) 0%, color-mix(in srgb, var(--deep) 62%, var(--kaiki-primary)) 100%);
        }
        .band-dark > *, .cta-band.has-image > *, .steps-route:not(.has-image) > * { position: relative; z-index: 1; }
        /* More room above and below (Mike, 24/9), as the steps band has. */
        .band-dark { padding-block: clamp(4.5rem, 9vw, 7.5rem); }
        .band-dark::before,
        .cta-band.has-image::before,
        .steps-route:not(.has-image)::before {
            content: ""; display: block; position: absolute; z-index: 0; pointer-events: none;
            inset-block: -45% -45%; inset-inline: -35% -35%;
            background:
                radial-gradient(38% 42% at 72% 28%, color-mix(in srgb, var(--kaiki-accent) 62%, transparent) 0%, transparent 100%),
                radial-gradient(30% 34% at 22% 78%, color-mix(in srgb, var(--kaiki-accent) 30%, transparent) 0%, transparent 100%);
            filter: blur(10px);
            opacity: .95;
        }
        /* The split band's photograph covers its right half: the glow lives on the words' side. */
        .cta-band.has-image::before {
            inset-inline: -40% 20%;
            background:
                radial-gradient(40% 44% at 40% 30%, color-mix(in srgb, var(--kaiki-accent) 66%, transparent) 0%, transparent 100%),
                radial-gradient(30% 34% at 20% 85%, color-mix(in srgb, var(--kaiki-accent) 30%, transparent) 0%, transparent 100%);
        }
        @media (prefers-reduced-motion: no-preference) {
            .band-dark::before,
            .cta-band.has-image::before,
            .steps-route:not(.has-image)::before { animation: glow-wander 11s ease-in-out infinite alternate; will-change: transform; }
        }
        @keyframes glow-wander {
            0%   { transform: translate3d(0, 0, 0) scale(1); }
            50%  { transform: translate3d(-9%, 7%, 0) scale(1.12); }
            100% { transform: translate3d(6%, -5%, 0) scale(.96); }
        }

        /* «Πώς λειτουργεί» as a box: inside the page's width, rounded like the
           private trips band, the words and the route in the middle. */
        /* Edge to edge (Mike, 24/9: «full width το background»): the band
           reaches both sides of the screen, the words and the route stay in
           the page's column. */
        .steps-route:not(.has-image) {
            margin-inline: calc(50% - 50vw); border-radius: 0;
            padding-block: clamp(4.5rem, 9vw, 7.5rem); padding-inline: calc(50vw - 50%);
            color: rgba(255, 255, 255, .78);
        }
        .steps-route:not(.has-image) .section-head h2 { color: #fff; }
        .steps-route:not(.has-image) .section-head .lead { color: rgba(255, 255, 255, .78); }
        .steps-route:not(.has-image) .eyebrow-line { color: color-mix(in srgb, var(--kaiki-accent) 45%, #fff); }
        .steps-route:not(.has-image) .stop-copy h3 { color: #fff; }
        .steps-route:not(.has-image) .stop-copy p { color: rgba(255, 255, 255, .72); }
        /* the route on dark: a pale dashed track, a bright line, dots with a soft ring */
        .steps-route:not(.has-image) .route .stop:not(:last-child)::before { border-color: rgba(255, 255, 255, .28); }
        .steps-route:not(.has-image) .route .stop:not(:last-child)::after { background: color-mix(in srgb, var(--kaiki-accent) 55%, #fff); }
        .steps-route:not(.has-image) .stop-dot { box-shadow: 0 0 0 6px rgba(255, 255, 255, .08); border-color: color-mix(in srgb, var(--kaiki-accent) 55%, #fff); }
        .steps-route:not(.has-image) .route[data-animate="ready"] .stop-dot,
        .steps-route:not(.has-image) .route[data-animate="on"] .stop-dot {
            background: color-mix(in srgb, var(--deep) 70%, var(--kaiki-primary)); color: #fff; box-shadow: none;
        }
        .steps-route:not(.has-image) .route[data-animate="on"] .stop-dot { animation-name: route-fill-dark; }
        @keyframes route-fill-dark {
            to { background: var(--kaiki-accent); color: #fff; box-shadow: 0 0 0 6px rgba(255, 255, 255, .08); }
        }
        /* ==================================================================
           The story with a photograph, half the screen each (Mike, 2026-09-24,
           direction Γ of docs/mockups/story-sections.html): the photograph
           runs to the edge of the screen on its side, the words sit on a soft
           tint on the other, and two stories in a row touch, so they read as
           one band that zig-zags. On a phone the photograph goes on top.
           ================================================================== */
        .story.has-image {
            margin-inline: calc(50% - 50vw); gap: 0; align-items: stretch;
            grid-template-columns: minmax(0, 1fr);
        }
        .story.has-image .story-image {
            inline-size: 100%; block-size: 100%; min-block-size: 18rem; max-block-size: none;
            aspect-ratio: auto; border-radius: 0; object-fit: cover;
        }
        .story.has-image .story-copy {
            display: flex; flex-direction: column; justify-content: center;
            padding: clamp(2.5rem, 6vw, 6rem) clamp(1.25rem, 5vw, 5.5rem);
            background: var(--mist);
        }
        .story.has-image.side-right .story-copy { background: var(--sand); }
        .story.has-image .story-copy > * { max-width: 34rem; }
        .story.has-image + .story.has-image { margin-block-start: calc(-1 * var(--section-gap)); }
        /* An edge-to-edge story and an edge-to-edge band touch, as two bands do:
           the white gap between them read as a hole (Mike, 24/9). */
        .story.has-image + .band, .band + .story.has-image,
        .story.has-image + .steps-route:not(.has-image), .steps-route:not(.has-image) + .story.has-image { margin-block-start: calc(-1 * var(--section-gap)); }
        /* The figures card over the masthead's edge, with an edge-to-edge story
           straight under it (the about page). The card took up its own lower
           half in the flow, so the story started below it and a white strip
           showed between masthead and photograph (Mike, 25/9). Now the card
           takes no room: the story meets the masthead, and the card sits
           across the join — as far into the masthead as before, so it never
           reaches the masthead's text, and the rest over the photograph. */
        /* Wherever the card is one row (from 47.5rem, see the figures card):
           on a phone it is two rows tall and would cover the photograph's
           people, and the strip beside a card that nearly fills the width
           does not read as a gap. */
        @media (min-width: 47.51rem) {
            .hero + .stats-block:has(+ .story.has-image) { block-size: 0; margin-block-start: calc(-1 * var(--section-gap)); }
            .hero + .stats-block:has(+ .story.has-image) .stats { transform: translateY(-4rem); }
            .hero + .stats-block + .story.has-image { margin-block-start: calc(-1 * var(--section-gap)); }
            /* The card's lower half now lies over the story's top edge. */
            .hero + .stats-block + .story.has-image .story-copy { padding-block-start: calc(clamp(2.5rem, 6vw, 6rem) + 5rem); }
        }
        @media (min-width: 62rem) {
            .story.has-image, .story.side-left.has-image { grid-template-columns: 1fr 1fr; min-block-size: 34rem; }
            .story.has-image .story-image { min-block-size: 34rem; }
        }
        @media (max-width: 61.99rem) {
            .story.has-image .story-image { order: -1; max-block-size: 26rem; }
        }

        /* A slow Ken Burns on the photographed masthead (Mike, 2026-09-24):
           up to 15% closer and some drift, 22 seconds one way and back (made
           stronger the same evening). With the origin at 60% / 45% the scaled
           photograph overhangs every edge by more than the drift at every
           point of the way, so no edge of it ever shows.
           Still for reduced motion; a video masthead is left alone. */
        .hero.has-image { overflow: hidden; }
        @media (prefers-reduced-motion: no-preference) {
            .hero.has-image img.hero-image { animation: ken-burns 22s ease-in-out infinite alternate; transform-origin: 60% 45%; will-change: transform; }
        }
        @keyframes ken-burns {
            from { transform: scale(1) translate3d(0, 0, 0); }
            to   { transform: scale(1.15) translate3d(-3%, -2%, 0); }
        }
        /* The search bar's fields (Mike, 2026-09-24):
           - no calendar icon at the end of the date: the browser's picker
             button is stretched, invisible, over the whole field, so a click
             anywhere on «Ημερομηνία» opens the calendar;
           - focus lights the whole field, a soft tint with a rounded ring,
             instead of a square outline around the bare control. */
        .hero-search-below .field:not(.submit) input[type="date"] { position: static; }
        .hero-search-below .field:not(.submit) { position: relative; border-radius: 12px; transition: background-color .15s ease, box-shadow .15s ease; }
        .hero-search-below .field:not(.submit) input[type="date"]::-webkit-calendar-picker-indicator {
            position: absolute; inset: 0; inline-size: auto; block-size: auto; margin: 0; padding: 0;
            opacity: 0; cursor: pointer; background: none;
        }
        .hero-search-below .search-form input:focus-visible,
        .hero-search-below .search-form select:focus-visible { outline: none; box-shadow: none; }
        .hero-search-below .field:not(.submit):focus-within {
            background: color-mix(in srgb, var(--kaiki-accent) 6%, #fff);
            box-shadow: inset 0 0 0 2px color-mix(in srgb, var(--kaiki-accent) 42%, #fff);
        }
        .hero-search-below .field:not(.submit):hover { background: color-mix(in srgb, var(--kaiki-accent) 3%, #fff); }
        /* And no spinner arrows on «Πόσα άτομα»: the number is typed, and a phone shows its number pad. */
        .hero-search-below input[type="number"] { -moz-appearance: textfield; appearance: textfield; }
        .hero-search-below input[type="number"]::-webkit-inner-spin-button,
        .hero-search-below input[type="number"]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
        /* The operator's name on a phone (Mike, 2026-09-24): it was cut to
           «Aegean Blue…». A size smaller, the town under it hidden, and the
           language switch and the burger a little tighter, so a name of about
           twenty letters fits whole; a longer one still ellipses. */
        @media (max-width: 40rem) {
            header.site .brand .name { font-size: .98rem; letter-spacing: -.025em; }
            header.site .brand .brand-tag { display: none; }
            header.site .brand-mark { inline-size: 2.1rem; block-size: 2.1rem; }
            header.site .wrap { gap: .5rem; }
            header.site .brand { flex: 1 1 auto; }
            .header-end { flex: none; gap: .35rem; }
            .header-end .langs a { padding-inline: .35rem; }
        }

        /* The timeline's dashed track ends at the last year's dot rather than
           running on to the edge (Mike, 2026-09-24). The border stays for its
           room but goes transparent; the dashes are a background as long as
           the distance from the first dot to the last. */
        @media (min-width: 48rem) {
            ol.timeline {
                --gap: 1.5rem;
                border-block-start-color: transparent;
                background: repeating-linear-gradient(90deg, color-mix(in srgb, var(--kaiki-accent) 28%, #fff) 0 6px, transparent 6px 11px)
                    0 0 / calc((100% - (var(--n) - 1) * var(--gap)) * (var(--n) - 1) / var(--n) + (var(--n) - 1) * var(--gap) + .4rem) 2px no-repeat;
                background-origin: border-box; background-clip: border-box;
            }
            .timeline-n1 { --n: 1; } .timeline-n2 { --n: 2; } .timeline-n3 { --n: 3; } .timeline-n4 { --n: 4; }
            .timeline-n5 { --n: 5; } .timeline-n6 { --n: 6; } .timeline-n7 { --n: 7; } .timeline-n8 { --n: 8; }
        }

        /* ==================================================================
           ONE TYPE SYSTEM for every guest page (Mike, 2026-09-24: «κάνε όλες
           τις προτάσεις του designer»; docs/mockups/typo/index.html).

           Last in the sheet on purpose: it restates sizes that earlier rules
           set one page at a time, and the later rule wins. Nine steps and
           nothing between them:

             caption 13 · small 15 · body 16 · title 18 · lead 17→20
             sub 20→24 · panel 24→32 · h2 28→40 · page h1 32→44 · hero h1 40→60

           800 only on the hero's title; 700 for every other heading and price;
           600 for navigation, buttons, labels and links. Letter-spacing no more
           than +0.02em on small labels (lower-case Greek spaced wider reads
           letter by letter), negative only from 18px up. Greys: #4A5D5A for
           secondary text (7:1), #5F716E for labels (5.1:1), replacing #7B8D8A
           (3.5:1, below AA). Paragraphs no wider than about 65 characters.
           ================================================================== */
        :root {
            --ink-faint: #5F716E;
            --t-cap: .8125rem;
            --t-sm: .9375rem;
            --t-title: 1.125rem;
            --t-lead: clamp(1.0625rem, 1.4vw, 1.25rem);
            --t-sub: clamp(1.25rem, 1.6vw, 1.5rem);
            --t-panel: clamp(1.5rem, 2.3vw, 2rem);
            --t-h2: clamp(1.75rem, 3vw, 2.5rem);
            --t-h1-page: clamp(2rem, 3.2vw, 2.75rem);
            --t-h1-hero: clamp(2.5rem, 4.6vw, 3.75rem);
            --measure: min(65ch, 32rem);
        }
        /* A <button> does not inherit the page's font by default: the contact
           form's «Αποστολή μηνύματος» was Arial on an Inter page. */
        button, input, select, textarea { font-family: inherit; }

        /* ---- titles ---- */
        .hero h1, .hero.has-image h1, .hero.hero-plain h1 {
            font-size: var(--t-h1-hero); font-weight: 800; line-height: 1.1; letter-spacing: -.025em;
        }
        main .wrap > h1, .page-head h1, .search-head h1, .trip-hero h1 {
            font-size: var(--t-h1-page); font-weight: 700; line-height: 1.12; letter-spacing: -.025em; color: var(--deep);
        }
        .section-head h2, .block-head h2, .trips-block .block-head h2, .story-copy > h2, .block.faq > h2,
        .block.contact h2, .block.contact.has-image h2 {
            font-size: var(--t-h2); font-weight: 700; line-height: 1.15; letter-spacing: -.022em;
        }
        .cta-band h2, .cta-band.has-image h2 { font-size: var(--t-panel); font-weight: 700; line-height: 1.18; letter-spacing: -.02em; }
        .trips-more { font-size: var(--t-sub); font-weight: 700; line-height: 1.25; letter-spacing: -.015em; color: var(--deep); }
        /* The trip page's sections: one style (they were 18/700 ink, 18/800 navy and 16/700). */
        .product-main .section > h2, .product-main .block > h2, .product-main .lists h2, .product-main .block.faq > h2 {
            font-size: var(--t-sub); font-weight: 700; line-height: 1.2; letter-spacing: -.015em; color: var(--deep);
        }
        .ask h2 { font-size: var(--t-title); font-weight: 700; letter-spacing: -.012em; }
        .contact-details h2 { font-size: var(--t-title); font-weight: 700; letter-spacing: -.012em; color: var(--deep); }
        /* One card title: trips, route stops, features, timeline, boats, crew
           (16.8, 17.6, 17.9, 18.4 and 19.2 before). */
        li.trip h3, .step h3, .feature h3, .stop-copy h3, .timeline h3, .boat-body h3, .person-copy h3 {
            font-size: var(--t-title); font-weight: 700; line-height: 1.3; letter-spacing: -.012em;
        }
        ol.trip-timeline h3 { font-size: 1rem; }

        /* ---- eyebrows and labels ---- */
        .eyebrow, .eyebrow-line, .eyebrow.eyebrow-line, .block.contact .eyebrow {
            font-size: var(--t-cap); font-weight: 600; letter-spacing: .02em; line-height: 1.4;
        }
        .person-role { font-size: var(--t-cap); font-weight: 600; letter-spacing: .02em; }
        .search-form label { font-size: var(--t-cap); font-weight: 600; letter-spacing: .02em; color: var(--ink-soft); }
        .contact-form-card label { font-size: var(--t-sm); font-weight: 600; letter-spacing: 0; color: var(--kaiki-text); }
        .contact-form-card .optional { font-size: var(--t-cap); color: var(--ink-faint); }
        /* The search bar under the hero: the values a guest has chosen are not
           placeholders, and were set in the placeholder grey. */
        .hero-search.hero-search-below .field:not(.submit) :is(input, select) { font-size: var(--t-sm); color: var(--ink-soft); }

        /* ---- cards and prices: the name first, the price second ---- */
        .trip-price strong { font-size: var(--t-title); font-weight: 700; letter-spacing: -.01em; font-variant-numeric: tabular-nums; }
        .trip-price .from, .trip-price .per { font-size: var(--t-cap); color: var(--ink-soft); }
        .trip-price .on-request { font-size: var(--t-sm); font-weight: 600; }
        .trip-badge { font-size: var(--t-cap); font-weight: 600; }
        li.trip .summary { font-size: var(--t-sm); line-height: 1.55; }
        li.trip .facts { font-size: var(--t-cap); color: var(--ink-faint); }
        .result-count { font-size: var(--t-sm); color: var(--ink-soft); }
        .price strong { font-size: var(--t-panel); font-weight: 700; color: var(--kaiki-accent); font-variant-numeric: tabular-nums; }
        .price .from, .price .vat { font-size: var(--t-cap); color: var(--ink-faint); }
        .quote-stars { color: #B7791F; }
        .quote figcaption { font-size: var(--t-cap); }
        .quote figcaption b { font-size: var(--t-sm); }
        .stats strong, .hero + .stats-block .stats strong { font-weight: 700; font-variant-numeric: tabular-nums; }
        .stat-label, .hero + .stats-block .stat-label { font-size: var(--t-sm); }
        .boat-reg, .boat-specs dt { font-size: var(--t-cap); color: var(--ink-faint); }
        .boat-specs dd { font-size: var(--t-title); }
        .boat-licence, .boat-trips a { font-size: var(--t-sm); }
        .timeline-year { font-size: 1rem; }

        /* ---- running text and UI on the one small step ---- */
        .site-nav a, .button, .see-all, .hero-badges, .tab-label, ul.facts li, .trip-time,
        .step p, .feature p, .stop-copy p, .ask p, .contact-details a, .ask-actions .button {
            font-size: var(--t-sm);
        }
        .muted { font-size: var(--t-sm); color: var(--ink-soft); }
        .brand-tag, header.site .langs a { font-size: var(--t-cap); }
        .mosaic-all, .tiers li, .map-open .button { font-size: var(--t-sm); }
        .contact-details a, .contact-details .contact-list a { font-size: 1rem; }
        .menu-panel a, .menu-panel .button { font-size: var(--t-title); }
        .hero.has-image .standfirst { font-size: var(--t-lead); }
        .crumbs a { font-size: var(--t-sm); font-weight: 600; color: var(--kaiki-accent); }
        .page-head .lede, .search-head .standfirst, .trip-intro .standfirst { font-size: var(--t-lead); line-height: 1.5; }

        /* ---- measure ---- */
        .story-copy .prose, .faq-item .prose, .page-head .lede { max-width: var(--measure); }
        /* The trip's own text runs wider (Mike, 25/9: «more width»): 32rem left
           «Για την εκδρομή» a thin column beside a wide empty page. */
        .product-main .prose { max-width: 48rem; }

        /* ---- the legal page: prose, not sections ----
           It is the one page whose column holds its h1, h2 and paragraphs
           directly, so the column's gap put ~85px between the lines of an
           address, and nothing held the lines to a readable width. */
        main .wrap:not(:has(> .block)):has(> h1) { gap: 0; }
        main .wrap:not(:has(> .block)):has(> h1) > h1 { margin: 0 0 1rem; }
        main .wrap:not(:has(> .block)):has(> h1) > h2 {
            font-size: var(--t-sub); font-weight: 700; line-height: 1.2; letter-spacing: -.015em; color: var(--deep);
            margin: 2.25rem 0 .6rem;
        }
        main .wrap:not(:has(> .block)):has(> h1) > :is(p, ul, ol) { max-width: var(--measure); margin: 0 0 .6rem; }

        /* ---- the trip's schedule: a row without a time still lines up ---- */
        ol.trip-timeline li:not(:has(.trip-time)) h3 { margin-inline-start: 3.7rem; }

        /* ---- footer ---- */
        footer.site { font-size: var(--t-sm); }
        footer.site h3 { font-size: var(--t-cap); font-weight: 600; letter-spacing: .02em; line-height: 1.4; color: rgba(255, 255, 255, .62); margin: .25rem 0 .9rem; }
        .foot-bottom, .foot-bottom .powered { font-size: var(--t-cap); }
        .foot-reach-title { font-size: var(--t-title); }
        footer.site .brand-tag { color: rgba(255, 255, 255, .62); }

        /* ==================================================================
           Two more of 24/9, kept here with the rest of the day.
           ================================================================== */
        /* The white hairline between «Πώς λειτουργεί» and the story under it.
           The scroll reveal slid the story's two halves up 10px as they came
           in, and for that distance the page's white showed through between
           two sections that are meant to touch. The edge-to-edge halves stay
           put; the words inside the tinted half rise instead. */
        @supports (animation-timeline: view()) {
            @media (prefers-reduced-motion: no-preference) {
                .story.has-image .story-image,
                .story.has-image .story-copy { animation: none; }
                .story.has-image .story-copy > * {
                    animation: kaiki-rise linear both;
                    animation-timeline: view();
                    animation-range: entry 0% entry 32%;
                }
            }
        }

        /* The private-trips band's two buttons (Mike): one under the other and
           one width — both as wide as the wider, left-aligned in the text
           column; the whole column on a phone. */
        .cta-band.has-image .buttons { display: inline-grid; grid-template-columns: minmax(0, 1fr); justify-items: stretch; }
        .cta-band.has-image .buttons .button { justify-content: center; text-align: center; }
        @media (max-width: 40rem) {
            .cta-band.has-image .buttons { display: grid; }
        }
        /* The hero's two buttons had the same fault on a phone only: stacked
           there, at 185 and 179px. One width when one is above the other;
           side by side on a wider screen they keep their own size. */
        @media (max-width: 40rem) {
            .hero .cta { display: inline-grid; grid-template-columns: minmax(0, 1fr); }
            .hero .cta .button { justify-content: center; }
        }

        /* The footer's own room (Mike, 24/9): more above the columns and below
           the legal line. The «Έχετε ερώτηση;» row keeps its padding; the
           space under it is its bottom margin. */
        .foot-reach { margin-block-end: clamp(3.5rem, 7vw, 5.5rem); }
        footer.site { padding-block-end: clamp(2.5rem, 5vw, 3.5rem); }
        footer.site:not(:has(> .foot-reach)) { padding-block-start: clamp(3.5rem, 7vw, 5.5rem); }

        /* ==================================================================
           The top of «Βρείτε εκδρομή» and «Επικοινωνήστε μαζί μας» (Mike,
           2026-09-24, direction Α of docs/mockups/page-tops.html). The band is
           `.band.band-dark` — full bleed, and the dark boxes' gradient and
           drifting glow come from their rules above, not a copy of them. What
           follows it climbs over its lower edge: the home page's white search
           bar, or the contact form and the «Ή βρείτε μας απευθείας» card.
           ================================================================== */
        /* Flush under the header: the column's own top padding is taken back. */
        main .wrap > .page-top:first-child { margin-block-start: -2rem; }
        .page-top.band-dark { padding-block: clamp(2.5rem, 5vw, 4.25rem) clamp(5.5rem, 9vw, 7.75rem); }
        .page-top .crumbs { margin: 0 0 1.25rem; font-size: var(--t-cap); color: rgba(255, 255, 255, .7); }
        .page-top .crumbs a { font-size: var(--t-cap); font-weight: 600; color: #fff; }
        .page-top h1 {
            font-size: var(--t-h1-page); font-weight: 700; line-height: 1.12; letter-spacing: -.025em;
            color: #fff; margin: 0 0 .75rem; text-wrap: balance;
        }
        .page-top .lede { font-size: var(--t-lead); line-height: 1.5; color: rgba(255, 255, 255, .86); max-width: 36rem; margin: 0; }
        /* The overlap: less on a phone, where the band is short already. The
           column's 1.75rem gap is taken back first. */
        .page-top-over,
        .hero-search.hero-search-below.page-top-over {
            position: relative; z-index: 3;
            margin: calc(-1.75rem - clamp(2.5rem, 5.5vw, 4.75rem)) 0 0;
        }
        .page-top-over .contact-form-card,
        .page-top-over .contact-details {
            background: #fff; border: 0; border-radius: 18px;
            box-shadow: 0 2px 6px rgba(11, 39, 64, .06), 0 24px 56px rgba(11, 39, 64, .16);
        }
        .page-top-over .contact-details { padding: clamp(1.4rem, 2.4vw, 1.9rem); }
        .page-top-over .contact-details h2 { margin-block-start: 0; }
    </style>

    {{-- The booking bundle, fetched from the first byte of the page.

         The `<script defer>` that loads it sits inside the booking card, which
         is most of a trip page below the top of the document — and `defer`
         starts the download when the *parser reaches the tag*, not when the
         page starts. So on a phone the bundle queued behind the whole
         itinerary, the FAQ and every photograph, and the real booking bar could
         not exist until all of that had been read off the wire.

         A preload in the head starts the same request immediately and hands the
         finished script to the tag below, which executes it in exactly the same
         place and order. Nothing about the page's behaviour changes; only when
         the bytes arrive does.

         `@stack` because only the trip page has a widget: preloading a bundle
         the page never runs is a wasted request charged to somebody's data. --}}
    @stack('head')
</head>
<body>

@php
    /*
     * Does this operator have a home page for a link to go to?
     *
     * ADR-0029: a «bookings only» operator serves trip pages, the search page,
     * the contact page and the legal pages — and `/{operator}` 404s. The header
     * hung the logo on that URL regardless, so the one thing every visitor
     * presses to «go back to the start» led to a not-found page on every
     * booking-only site (Mike, 2026-09-22).
     *
     * Read from the tenant on every render rather than baked in anywhere, which
     * is what makes it follow the switch in both directions: an operator
     * promoted to the full site gets the home link on their next page view, and
     * one moved back to booking pages loses it just as fast. Nothing is cached
     * per mode; there is nothing to invalidate.
     */
    $servesHome = \App\Domain\Hosted\Support\HostedUrl::homeEnabledFor($tenant);

    // Where «the start of this site» is. The search page is the honest answer
    // for an operator with no home page: it is the list of everything they
    // sell, which is what a visitor pressing a logo is looking for.
    $siteStart = $servesHome
        ? route('hosted.index', ['operator' => $tenant->slug, 'lang' => $locale])
        : route('hosted.search', ['operator' => $tenant->slug, 'lang' => $locale]);

    // The routes above rather than `HostedUrl::siteStart()` on purpose: these
    // are links inside the page, and on a custom domain (HOS-3) the route
    // helper keeps the visitor on the host they are already on, while
    // `HostedUrl` always builds the platform's own. The decision — which of
    // the two pages is the start — is the same one, and it is `$servesHome`.
@endphp

<header class="site">
    <div class="wrap">
        {{-- The operator's logo, or — for the operator who has not uploaded
             one — a mark in their own colours beside their name and the town
             they sail from, the masthead of their WordPress site (2026-09-16).
             The mark is drawing and hidden; the name is the link text. --}}
        <a class="brand" href="{{ $siteStart }}">
            @if ($logo)
                <img src="{{ $logo }}" alt="{{ $tenant->name }}">
            @else
                <span class="brand-mark" aria-hidden="true">@include('hosted.partials.icon', ['name' => 'boat'])</span>
                <span class="brand-text">
                    <span class="name">{{ $tenant->name }}</span>
                    @if ($tenant->city)
                        <span class="brand-tag">{{ $tenant->city }}</span>
                    @endif
                </span>
            @endif
        </a>

        {{-- The search of #105 and the contact page, reachable from every hosted
             page. A feature a guest cannot find is a feature the operator paid
             for and nobody uses — and "how do I reach a person" is the question
             a visitor asks on whichever page they happen to be standing on. --}}
        <nav class="site-nav">
            @include('hosted.partials.nav-links')
        </nav>

        {{-- The right-hand end (Mike, 2026-09-24, the first home mockup's
             header): the language switch as two words, then the one button the
             header exists for. The telephone left the row the same day — it is
             in the footer's first row, and in the burger's panel on a phone. --}}
        <div class="header-end">
            <nav class="langs" aria-label="{{ __('hosted.nav.language') }}">
                @foreach (['el' => 'ΕΛ', 'en' => 'EN'] as $code => $label)
                    <a href="{{ $alternates[$code] }}"
                       hreflang="{{ $code }}"
                       @if ($code === $locale) aria-current="true" @endif>{{ $label }}</a>
                @endforeach
            </nav>

            <a class="button button-accent arrow header-book" href="{{ route('hosted.search', ['operator' => $tenant->slug, 'lang' => $locale]) }}">{{ __('hosted.nav.book') }}</a>
        </div>

        {{-- The same links behind a burger, on a phone only.

             ## Why `<details>` and not a button

             The hosted pages run with no JavaScript at all (HOS-2), so the
             toggle has to be markup. `<details>` is the only element that opens
             and closes on its own: it is focusable, it answers the space bar and
             the enter key, it reports itself as expanded or collapsed to a
             screen reader, and none of that is code anybody here has to keep
             working.

             The alternative — a hidden checkbox with a `<label>` — needs three
             extra elements and an `aria-expanded` that nothing updates, which is
             a burger that lies to the people who most need it to be honest.

             It comes last in the row and last in the markup, so a tab key
             visits the header in the order a thumb crosses it. --}}
        <details class="menu">
            <summary aria-label="{{ __('hosted.nav.menu') }}" title="{{ __('hosted.nav.menu') }}">
                {{-- Two states in one drawing: three bars, and the cross they
                     become when the panel is open. CSS swaps them, so the icon
                     needs no script either. --}}
                <svg class="icon burger" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false">
                    <line class="bar top" x1="3" y1="6" x2="21" y2="6"/>
                    <line class="bar mid" x1="3" y1="12" x2="21" y2="12"/>
                    <line class="bar bot" x1="3" y1="18" x2="21" y2="18"/>
                </svg>
            </summary>

            <nav class="menu-panel" aria-label="{{ __('hosted.nav.menu') }}">
                @include('hosted.partials.nav-links')
                @if ($tenant->phone)
                    <a class="menu-phone" href="tel:{{ $tenant->phone }}">@include('hosted.partials.icon', ['name' => 'phone']){{ $tenant->phone }}</a>
                @endif
                <a class="button button-accent menu-book" href="{{ route('hosted.search', ['operator' => $tenant->slug, 'lang' => $locale]) }}">{{ __('hosted.nav.book') }}</a>
            </nav>
        </details>

    </div>
</header>

<main>
    <div class="wrap">
        @yield('content')
    </div>
</main>

<footer class="site">
    {{-- «Έχετε ερώτηση; Είμαστε εδώ.» (Mike, 2026-09-24): one quiet row at the
         top of the footer, on the operator's secondary colour, with the three
         ways to reach them as plain links. It replaced the contact panel that
         sat above the footer on the home page. Each link only when the
         operator has that way to be reached. --}}
    @php
        $reachWhatsApp = data_get($tenant->settings, 'social.whatsapp');
        $reachWhatsApp = is_string($reachWhatsApp) && str_starts_with($reachWhatsApp, 'https://') ? $reachWhatsApp : null;
    @endphp
    @if ($tenant->phone || $tenant->email || $reachWhatsApp)
        <div class="foot-reach">
            <div class="wrap">
                <p class="foot-reach-title">@include('hosted.partials.icon', ['name' => 'support']){{ __('hosted.footer.reach') }}</p>
                <ul>
                    @if ($tenant->phone)
                        <li><a href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $tenant->phone) }}">@include('hosted.partials.icon', ['name' => 'phone']){{ $tenant->phone }}</a></li>
                    @endif
                    @if ($reachWhatsApp)
                        <li><a href="{{ $reachWhatsApp }}" rel="noopener noreferrer" target="_blank">@include('hosted.partials.icon', ['name' => 'social-whatsapp']){{ __('hosted.footer.whatsapp') }}</a></li>
                    @endif
                    @if ($tenant->email)
                        <li><a href="mailto:{{ $tenant->email }}">@include('hosted.partials.icon', ['name' => 'mail']){{ $tenant->email }}</a></li>
                    @endif
                </ul>
            </div>
        </div>
    @endif

    <div class="wrap">
        {{-- A deep band in the operator's own primary colour since 16 September,
             the footer of their WordPress site: their mark and a way to follow
             them first, then where to go, how to reach them and who they are
             legally — and the legal links in a row of their own underneath. --}}
        <div class="cols">
            <div class="foot-brand">
                @if ($footLogo)
                    <img class="foot-logo" src="{{ $footLogo }}" alt="{{ $tenant->name }}">
                @else
                    <p class="foot-wordmark">
                        <span class="brand-mark" aria-hidden="true">@include('hosted.partials.icon', ['name' => 'boat'])</span>
                        <span class="brand-text">
                            <span class="name">{{ $tenant->name }}</span>
                            @if ($tenant->city)
                                <span class="brand-tag">{{ $tenant->city }}</span>
                            @endif
                        </span>
                    </p>
                @endif

                @php
                    $footSocial = collect((array) data_get($tenant->settings, 'social', []))
                        ->filter(static fn (mixed $url, mixed $key): bool => is_string($key) && is_string($url) && str_starts_with($url, 'https://'))
                        ->all();
                @endphp

                @if ($footSocial !== [])
                    <ul class="social">
                        @foreach ($footSocial as $network => $url)
                            <li>
                                <a href="{{ $url }}"
                                   rel="noopener noreferrer me"
                                   target="_blank"
                                   aria-label="{{ __('hosted.blocks.contact.social.' . $network) }}"
                                   title="{{ __('hosted.blocks.contact.social.' . $network) }}">
                                    @include('hosted.partials.icon', ['name' => 'social-' . $network . '-solid'])
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div>
                <h3>{{ __('hosted.footer.explore') }}</h3>
                <ul>
                    {{-- «Αρχική» only where there is one. The other two are
                         served in both modes. --}}
                    @if ($servesHome)
                        <li><a href="{{ route('hosted.index', ['operator' => $tenant->slug, 'lang' => $locale]) }}">{{ __('hosted.footer.home') }}</a></li>
                    @endif
                    <li><a href="{{ route('hosted.search', ['operator' => $tenant->slug, 'lang' => $locale]) }}">{{ __('hosted.search.nav') }}</a></li>
                    @if ($hasCalendar ?? true)
                        <li><a href="{{ route('hosted.calendar', ['operator' => $tenant->slug, 'lang' => $locale]) }}">{{ __('hosted.calendar.nav') }}</a></li>
                    @endif
                    <li><a href="{{ route('hosted.contact', ['operator' => $tenant->slug, 'lang' => $locale]) }}">{{ __('hosted.contact.nav') }}</a></li>
                </ul>
            </div>

            <div>
                <h3>{{ __('hosted.footer.contact') }}</h3>
                <ul class="foot-contact">
                    @if ($tenant->phone)
                        <li>@include('hosted.partials.icon', ['name' => 'phone'])<a href="tel:{{ $tenant->phone }}">{{ $tenant->phone }}</a></li>
                    @endif
                    <li>@include('hosted.partials.icon', ['name' => 'mail'])<a href="mailto:{{ $tenant->email }}">{{ $tenant->email }}</a></li>
                </ul>
            </div>

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
        </div>

        <div class="foot-bottom">
            <p>© {{ now()->year }} {{ $tenant->legal_name ?? $tenant->name }}</p>

            <nav aria-label="{{ __('hosted.footer.legal') }}">
                <ul class="foot-legal">
                    <li><a href="{{ route('hosted.legal', ['operator' => $tenant->slug, 'lang' => $locale]) }}">{{ __('hosted.footer.terms') }}</a></li>
                    <li><a href="{{ route('hosted.legal', ['operator' => $tenant->slug, 'lang' => $locale]) }}#privacy">{{ __('hosted.footer.privacy') }}</a></li>
                    <li><a href="{{ route('hosted.legal', ['operator' => $tenant->slug, 'lang' => $locale]) }}#cancellation">{{ __('hosted.footer.cancellation') }}</a></li>
                </ul>
            </nav>

            @if ($poweredBy)
                {{-- Brand decision 6: always, custom domains included. From a flag,
                     so a white-label tier is a config change rather than an edit. --}}
                <p class="powered">{{ __('hosted.footer.powered_by') }}</p>
            @endif
        </div>
    </div>
</footer>

</body>
</html>
