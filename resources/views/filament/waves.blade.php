{{--
    The light waves along the bottom of the page, for both panels.

    They were part of `filament/app/sea.blade.php` and therefore `/app` only —
    «/admin keeps Filament's plain look, which is also how somebody switching
    between the two tabs can tell them apart». The product owner reversed that
    on 2026-09-22: *«και τα κύματα βάλτα και στο admin περιβάλλον»*. The blue
    sidebar stays where it is, so the two panels still read differently at a
    glance; what they now share is the water.

    One tiled SVG (`public/images/waves.svg`, shared with the sign-in screens),
    drawn in a blue only a shade darker than the page and fixed to the bottom of
    the viewport, so a long table scrolls over them rather than dragging them
    along. Off in dark mode, where there is no shade light enough.
--}}
<style>
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
</style>
