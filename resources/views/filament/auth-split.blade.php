{{--
    The sign-in screens, split in half: a photograph on the left, the form on
    the right. Asked for on 14 September.

    ## Which screens this is

    Every page Filament renders through its "simple" layout — `/app/login`,
    `/admin/login`, and the password-request and password-reset pages both panels
    gained on 2026-09-08. They share one layout, so they share one rule and
    none of them can drift away from the others.

    ## Why CSS from a render hook rather than a published view

    Filament's `filament-panels::components.layout.simple` could be published
    into `resources/views/vendor/` and rewritten. That forks a vendor view: every
    upgrade of the package then needs somebody to diff our copy against theirs
    and merge by hand, forever, for a layout we want to change in one dimension.

    Everything below is layout on the layout's own outermost element, so it needs
    no new markup at all — `::before` is the photograph and the existing
    `fi-simple-main-ctn` is the other half. It is the same reasoning, and the
    same mechanism, as `touch-targets.blade.php`.

    ## The photograph

    `public/images/auth-cockpit.jpg`, served from our own origin because SEC-10's
    `img-src 'self' data: blob:` refuses anything else — no CDN, and no operator's
    IP leaving for a background. It is decoration and carries no information, so
    it is a background rather than an `<img>`: nothing for a screen reader to
    announce, and nothing to caption.

    **It is hidden below `lg`.** A photograph above a sign-in form on a phone is
    a screenful of scrolling between an operator and the password field, which is
    the opposite of the point. The brand colour fills the space instead, which
    also means the form never lands on a white page with nothing on it.
--}}
<style>
    /* The scrim over the photograph's lower half, so «Powered by Kaiki» and the
       locale switcher stay legible on a bright sky. Declared once, used twice. */
    :root {
        --kaiki-auth-scrim: linear-gradient(
            to bottom,
            rgba(11, 39, 64, .10) 0%,
            rgba(11, 39, 64, .38) 62%,
            rgba(11, 39, 64, .62) 100%
        );
    }

    @media (min-width: 1024px) {
        .fi-simple-layout {
            /* Two halves, not a centred column. `min-h-screen` is Filament's
               and stays; the flex column it sets is replaced.

               40/60 was tried on 14 September to give the form more room and
               taken back the same day: at 40% the photograph stops being a
               picture and starts being a stripe down the edge, and the form
               did not need the width — it is capped at 28rem either way, so
               everything the column gained became empty margin. */
            display: grid;
            grid-template-columns: 1fr 1fr;
            align-items: stretch;
        }

        .fi-simple-layout::before {
            content: '';
            /* First grid item, so it takes the left column without the markup
               knowing anything about it. */
            background-image: var(--kaiki-auth-scrim), url('/images/auth-cockpit.jpg');
            background-size: cover, cover;
            /* The helm is left of centre in the frame and the horizon sits above
               the middle; holding the crop at 40% keeps the wheel in shot at
               every window height instead of sliding it off the edge. */
            background-position: center, 40% center;
            background-repeat: no-repeat;
            /* The colour behind it is what shows while the photograph loads, and
               on a connection where it never does. */
            background-color: var(--kaiki-navy, #123a5e);
        }

        /* The form's half. `items-center` and `justify-center` are Filament's
           and do the right thing inside the grid cell already; this only stops
           the card growing to the full width of the half. */
        .fi-simple-layout > .fi-simple-main-ctn {
            padding-inline: 2rem;
        }

        .fi-simple-layout .fi-simple-main {
            /* Filament's `max-w-lg` measured against the whole viewport. Against
               one column of it the card wanted to be the whole column. 28rem is
               the widest the fields read well at — past that the label and the
               far edge of the input stop looking like one object. */
            max-inline-size: 28rem;
            /* The card is the page's only object on its own half now, so the
               ring and shadow that separated it from a grey page are separating
               it from nothing. */
            box-shadow: none;
            --tw-ring-color: transparent;
            background: transparent;
            padding-inline: 0;
        }

        /* Anything the panel renders outside the form — the locale switcher, the
           "powered by" line — belongs on the form's half, not spread across
           both. */
        .fi-simple-layout > *:not(.fi-simple-main-ctn) {
            grid-column: 2;
        }
    }

    /* Wide enough for the split to be generous rather than merely possible. */
    @media (min-width: 1536px) {
        .fi-simple-layout { grid-template-columns: 1.1fr 1fr; }
    }

    /* Below `lg`: no photograph, and the card carries its own weight again.
       Nothing is declared here — Filament's own layout is already right — which
       is the point of scoping every rule above to a minimum width. */
</style>
