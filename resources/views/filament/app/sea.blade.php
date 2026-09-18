{{--
    The operator panel's colours: an Aegean-blue sidebar and very light waves
    along the bottom of the page (product owner, 2026-09-16).

    Chosen after three rounds of dashboard mockups, where the answer was to
    keep the screens as they are and change only these two things. `/app`
    only: the super-admin at `/admin` keeps Filament's plain look, which is
    also how somebody switching between the two tabs can tell them apart.

    Injected from a render hook rather than a compiled Filament theme, for the
    reason `touch-targets` records: a theme is a second Vite entry point and a
    build step for the panel, to carry a handful of declarations.

    **The waves are a background, never content.** One tiled SVG
    (`public/images/waves.svg`, shared with the sign-in screens), drawn in a
    blue only a shade darker than the page, and fixed to the bottom of the
    viewport so a long table scrolls over them rather than dragging them along.
    They drift slowly (see below) and are off in dark mode, where there is no
    shade light enough.
--}}
<style>
    :root {
        --ka-sea-deep: #0F2E57;
        --ka-sea-hover: #17396A;
        --ka-sea-active: #1C4378;
        --ka-sea-line: #23497D;
        --ka-sea-text: #C4D3E8;
        --ka-sea-muted: #7F98BA;
    }

    html:not(.dark) .fi-body::before,
    html:not(.dark) .fi-body::after {
        background-image: url("/images/waves.svg");
        background-repeat: repeat-x;
        background-size: 520px 170px;
        content: "";
        position: fixed;
        left: 0;
        bottom: 0;
        width: calc(100% + 520px);
        height: 170px;
        pointer-events: none;
        z-index: -1;
        will-change: transform;
        animation: ka-sea-drift 90s linear infinite;
    }

    html:not(.dark) .fi-body {
        background-color: #F7FAFD;
    }

    /*
     * The waves drift, very slowly (product owner, 2026-09-16).
     *
     * Two fixed layers behind everything, each one tile wider than the screen,
     * sliding left by exactly one tile and starting again, so the loop has no
     * visible seam. A `transform` rather than an animated background position:
     * the phone moves it without repainting the page, which matters on a boat,
     * on a battery.
     *
     * The back layer is taller, fainter and slower, and goes the other way, so
     * the two never line up and it reads as water rather than a moving pattern.
     * Nothing moves for anyone who has asked their device for less motion.
     */
    html:not(.dark) .fi-body::before {
        height: 230px;
        background-size: 520px 230px;
        opacity: .55;
        animation-duration: 150s;
        animation-direction: reverse;
    }

    @keyframes ka-sea-drift {
        from { transform: translateX(0); }
        to { transform: translateX(-520px); }
    }

    @media (prefers-reduced-motion: reduce) {
        html:not(.dark) .fi-body::before,
        html:not(.dark) .fi-body::after {
            animation: none;
        }
    }

    /* The topbar lets the page show through, so the waves are not cut off by a white band. */
    html:not(.dark) .fi-topbar nav {
        background: rgba(255, 255, 255, .85);
        backdrop-filter: blur(6px);
    }

    /* Sidebar: the same blue in light and dark mode. */
    .fi-sidebar,
    .fi-sidebar-header,
    .fi-sidebar-nav {
        background: var(--ka-sea-deep) !important;
    }

    /*
     * A tighter menu (Menu 1): Filament's spacing made the 13 entries 1,020px
     * tall, so on a laptop the menu scrolled. The groups sit closer and the
     * rows are a little shorter, with the text kept at 15px. If a
     * shorter screen still scrolls, the scrollbar is thin and blue rather than
     * a grey browser bar on the blue, which looks like a fault.
     */
    .fi-sidebar-nav {
        padding-top: .75rem !important;
        padding-bottom: .75rem !important;
        row-gap: .75rem !important;
        scrollbar-width: thin;
        scrollbar-color: var(--ka-sea-line) transparent;
    }

    .fi-sidebar-nav-groups {
        row-gap: .625rem !important;
    }

    .fi-sidebar-group,
    .fi-sidebar-group-items {
        row-gap: 1px !important;
    }

    .fi-sidebar-group-button {
        padding-top: .125rem !important;
        padding-bottom: .125rem !important;
    }

    .fi-sidebar-group-label {
        font-size: .8125rem !important;
    }

    .fi-sidebar-item-button {
        padding-top: .4375rem !important;
        padding-bottom: .4375rem !important;
    }

    /* 15px: 13px was too small to read (product owner, same day). */
    .fi-sidebar-item-label {
        font-size: .9375rem !important;
        font-weight: 500;
    }

    .fi-sidebar-item-icon {
        width: 1.25rem !important;
        height: 1.25rem !important;
    }

    .fi-sidebar .ka-sidebar-foot {
        padding-top: .5rem;
        padding-bottom: .5rem;
        gap: 1px;
    }

    .fi-sidebar-header {
        box-shadow: none !important;
        --tw-ring-color: transparent;
    }

    .fi-sidebar-header .fi-logo,
    .fi-sidebar-header a {
        color: #fff;
    }

    .fi-sidebar-group-label,
    .fi-sidebar-group-button .fi-icon-btn,
    .fi-sidebar-group-collapse-button {
        color: var(--ka-sea-muted) !important;
    }

    .fi-sidebar-item-label {
        color: var(--ka-sea-text) !important;
    }

    .fi-sidebar-item-icon {
        color: var(--ka-sea-muted) !important;
    }

    .fi-sidebar-item-button:hover,
    .fi-sidebar-item-button:focus-visible {
        background: var(--ka-sea-hover) !important;
    }

    .fi-sidebar-item-active .fi-sidebar-item-button {
        background: var(--ka-sea-active) !important;
    }

    .fi-sidebar-item-active .fi-sidebar-item-label,
    .fi-sidebar-item-active .fi-sidebar-item-icon {
        color: #fff !important;
    }

    .fi-sidebar .fi-badge {
        background: var(--ka-sea-active);
        color: #CFE0F7;
        --tw-ring-color: transparent;
    }

    .fi-sidebar-item-grouped-border > div {
        background: var(--ka-sea-line) !important;
    }

    /*
     * The link to the guest side carries its own light-grey styles. «Ρυθμίσεις» at the
     * foot of the sidebar reads `--ka-sea-line` in its own view instead of
     * being named here: `SettingsHubTest` looks for that class in the page to
     * find the footer, and a mention in the head would be found first.
     */
    .fi-sidebar .kaiki-view-frontend {
        border-color: var(--ka-sea-line);
        color: var(--ka-sea-text);
    }

    .fi-sidebar .kaiki-view-frontend:hover {
        background: var(--ka-sea-hover);
        color: #fff;
    }

    /*
     * Line tabs (trip form, product owner, 2026-09-17): plain words, the open
     * one dark with a 2px blue bar under it, one hairline under the row, and
     * the count as quiet text rather than a pill. The bar marks the open tab;
     * it is not an underlined link.
     */
    .ka-line-tabs > nav.fi-tabs {
        justify-content: flex-start;
        max-width: none;
        margin-inline: 0;
        gap: 1.75rem;
        padding: 0;
        margin-block-end: .5rem;
        border-block-end: 1px solid #E1E8F2;
        border-radius: 0;
        background: transparent;
        box-shadow: none;
        overflow-x: auto;
    }

    .ka-line-tabs > nav.fi-tabs .fi-tabs-item {
        padding: .7rem 0;
        margin-block-end: -1px;
        border-radius: 0;
        border-block-end: 2px solid transparent;
        background: transparent !important;
    }

    .ka-line-tabs > nav.fi-tabs .fi-tabs-item-label {
        font-size: 1.0625rem;
        font-weight: 600;
        color: #7B8BA1;
    }

    .ka-line-tabs > nav.fi-tabs .fi-tabs-item.fi-active {
        border-block-end-color: var(--ka-sea-deep);
    }

    .ka-line-tabs > nav.fi-tabs .fi-tabs-item.fi-active .fi-tabs-item-label {
        color: #15233A;
    }

    .ka-line-tabs > nav.fi-tabs .fi-badge {
        background: transparent;
        --tw-ring-color: transparent;
        box-shadow: none;
        padding-inline: 0;
        font-weight: 500;
    }

    /*
     * One language switch for the whole form (2026-09-18), and the two blocks
     * it shows and hides. Both languages stay in the DOM — see the switch's own
     * comment — so this is `display: none` rather than a removal.
     */
    .ka-locale-switch {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: .6rem;
        margin-block-end: .25rem;
    }

    .ka-locale-switch-label {
        font-size: .8125rem;
        color: #7B8BA1;
    }

    .ka-locale-switch-buttons {
        display: inline-flex;
        border: 1px solid #DCE4EF;
        border-radius: .5rem;
        overflow: hidden;
        background: #fff;
    }

    .ka-locale-switch-buttons button {
        padding: .35rem .75rem;
        font-size: .875rem;
        font-weight: 600;
        color: #7B8BA1;
        background: transparent;
    }

    .ka-locale-switch-buttons button.ka-locale-on {
        background: var(--ka-sea-deep);
        color: #fff;
    }

    .ka-locale-switch-buttons button:focus-visible {
        outline: 2px solid var(--ka-sea-deep);
        outline-offset: -2px;
    }

    form[data-ka-locale="el"] .ka-locale--en,
    form[data-ka-locale="en"] .ka-locale--el {
        display: none;
    }

    /*
     * «Πριν τη δημοσίευση» as a column of its own (2026-09-18). It follows the
     * form down the page, because it is the answer to "why can I not publish
     * this yet" and that question is asked from whichever tab is open.
     */
    @media (min-width: 1280px) {
        .ka-checklist-side {
            position: sticky;
            top: 5rem;
        }
    }
</style>
