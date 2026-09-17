{{--
    The sign-in screens, split in half: the panel's blue with moving waves on
    the left, the form on a plain light ground on the right (direction C,
    product owner, 2026-09-17). It replaced a photograph with a blue scrim,
    which read as too plain; the photograph file was deleted.

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

    Everything below is layout on Filament's own elements plus one view of
    ours, `auth-brandmark.blade.php`, which carries the mark and the line. `::before` of the layout is the blue column; the brandmark is
    laid over it. It is the same reasoning, and the same mechanism, as
    `touch-targets.blade.php`.

    ## The waves move, and only on the blue

    Two layers of `public/images/waves-light.svg` drifting slowly, rising and
    leaning towards the pointer. How, and why this version, is at the rules
    below. Nothing moves for anyone who has asked their device for less motion.
    The form's side has no waves at all.

    ## These screens are light, whatever the operator's laptop is set to

    The form's side paints a light ground, `#F7FAFD`. Filament, meanwhile, still puts `dark` on `<html>`
    whenever the operating system asks for it, and its own utilities then set
    `dark:text-white` on the heading, the labels, the inputs and the hints. The
    result on a laptop in dark mode was **white type on that near-white ground**:
    the labels were invisible and the heading was a ghost.

    The fix is to take `dark` off, not to answer it. A dark variant of this
    screen is a second design, and this screen is one composition. Overriding the colours one utility at a time was the other
    option and it is a losing game: the live page carries `dark:` classes on the
    heading, the logo, the required marker, the input wrapper, the input itself,
    the error message, the hint and the card, and the next Filament release adds
    more without telling us.

    **Why it is safe to remove.** The class is written once by Filament's own
    script in the head, from `prefers-color-scheme` when no choice is stored.
    Nothing re-applies it here: the theme switcher lives in the user menu, and
    these four pages have no user menu — nobody is signed in yet. Removing it in
    the body therefore sticks, and an operator's stored preference is untouched
    for every page behind the sign-in.

    It runs at `SIMPLE_PAGE_START`, which is the first thing inside the layout
    and before any of the form is parsed, so the class is gone before there is
    anything painted to flash.
--}}
<script>
    /* Not `x-data`, not Alpine: this has to run as the parser reaches it, and
       Alpine has not started yet.

       **Twice, because Filament applies the class twice.** Its head script calls
       `loadDarkMode()` as the page parses *and* registers it on
       `livewire:navigated` — which Livewire v3 fires on the first load too, not
       only on an SPA navigation. Removing the class once, here in the body, was
       therefore undone a moment later by an event that had not fired yet. This
       listener is registered after Filament's, so for the same event it runs
       second, which is the whole trick.

       **Guarded by the layout, not by the URL.** `livewire:navigated` also fires
       when the operator signs in and is carried to a panel page without a full
       page load, and a listener on `document` outlives the body it was written
       into. Asking whether a simple layout is on the page answers "are we still
       on a sign-in screen" exactly, and keeps the dashboard's dark mode — and
       the operator's stored preference — untouched. */
    (function () {
        const lightenAuthScreens = () => {
            if (document.querySelector('.fi-simple-layout')) {
                document.documentElement.classList.remove('dark');
            }
        };

        lightenAuthScreens();
        document.addEventListener('livewire:navigated', lightenAuthScreens);
    })();
</script>

<style>
    /* The other half of the same decision. `color-scheme` is what the *browser*
       paints — the form controls' own chrome, the scrollbar, the caret — and it
       reads the OS, not the class above. */
    .fi-simple-layout {
        color-scheme: light;
        /* The containing block for the blue side at every width. */
        position: relative;
        background-color: #F7FAFD;
    }

    /* Exactly one mark on screen: ours, in the blue. */
    .fi-simple-layout .fi-logo { display: none; }

    /* Not a card: the form sits straight on the light ground. */
    .fi-simple-layout .fi-simple-main {
        background: transparent;
        box-shadow: none;
        --tw-ring-color: transparent;
    }

    .fi-simple-layout .fi-simple-header { align-items: flex-start; }
    .fi-simple-layout .fi-simple-header-heading { text-align: start; }

    /* The language switch starts where the heading starts. Its inline
       `justify-content: flex-end` is why this needs `!important`. */
    .fi-simple-page > div:has(> .kaiki-locale-switcher) {
        justify-content: flex-start !important;
    }

    /* --- the blue side ------------------------------------------------ */
    .kaiki-auth-brandmark {
        position: absolute;
        inset-block-start: 0;
        inset-inline-start: 0;
        z-index: 1;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        overflow: hidden;
        background-color: #0F2E57;
        color: #fff;
    }

    .kaiki-auth-brandmark > * {
        position: relative;
        z-index: 1;
    }

    .kaiki-auth-brandmark-name {
        display: block;
        font-weight: 800;
        line-height: 1;
        letter-spacing: -.03em;
    }

    .kaiki-auth-brandmark img {
        inline-size: auto;
        max-inline-size: 70%;
    }

    .kaiki-auth-brandmark p {
        margin: .75rem 0 0;
        color: #C4D3E8;
        max-inline-size: 36ch;
    }

    /* The waves: two layers of `waves-light.svg` along the bottom of the blue,
       crossing in opposite directions and swelling gently, and rising and
       leaning towards the pointer (product owner, 2026-09-17).

       Four versions were tried that day — a canvas of spring lines and a
       silver-fibre ribbon among them — and this is the one he kept. A slower
       copy was tried and was too slow; these are the original speeds.

       **On the compositor, three motions that never fight.** Each layer is a
       strip one tile wider than the blue:
       - `transform` drifts it sideways at a constant, slow speed, exactly one
         tile per loop so there is no seam;
       - `translate` swells it up and down on an ease-in-out curve;
       - `scale` and a margin answer the pointer (`--ka-wave-lift`,
         `--ka-wave-shift`, set by `auth-brandmark.blade.php`), eased so the
         water follows the hand rather than jumping to it.
       None of them repaints. */
    .kaiki-auth-brandmark::before,
    .kaiki-auth-brandmark::after {
        content: '';
        position: absolute;
        inset-block-end: -24px;
        z-index: 0;
        pointer-events: none;
        background-image: url('/images/waves-light.svg');
        background-repeat: repeat-x;
        background-position: 0 100%;
        transform-origin: 50% 100%;
        will-change: transform, translate, scale;
        scale: 1 var(--ka-wave-lift, 1);
        transition:
            scale .9s cubic-bezier(.22, .61, .36, 1),
            margin-inline-start .9s cubic-bezier(.22, .61, .36, 1);
    }

    .kaiki-auth-brandmark::after {
        inset-inline-start: 0;
        margin-inline-start: calc(var(--ka-wave-shift, 0) * 1px);
        inline-size: calc(100% + 520px);
        block-size: calc(55% + 24px);
        background-size: 520px 220px;
        /* Quieter, asked for twice (product owner, 2026-09-17). */
        opacity: .6;
        animation:
            kaiki-auth-drift-left 22s linear infinite,
            kaiki-auth-swell 7s ease-in-out infinite alternate;
    }

    .kaiki-auth-brandmark::before {
        inset-inline-start: -780px;
        /* The back layer answers less, and the other way: depth. */
        margin-inline-start: calc(var(--ka-wave-shift, 0) * -.5px);
        scale: 1 calc(1 + (var(--ka-wave-lift, 1) - 1) * .5);
        inline-size: calc(100% + 780px);
        block-size: calc(80% + 24px);
        background-size: 780px 330px;
        opacity: .3;
        animation:
            kaiki-auth-drift-right 38s linear infinite,
            kaiki-auth-swell 9s ease-in-out -4s infinite alternate-reverse;
    }

    @keyframes kaiki-auth-drift-left {
        from { transform: translate3d(0, 0, 0); }
        to { transform: translate3d(-520px, 0, 0); }
    }

    @keyframes kaiki-auth-drift-right {
        from { transform: translate3d(0, 0, 0); }
        to { transform: translate3d(780px, 0, 0); }
    }

    @keyframes kaiki-auth-swell {
        from { translate: 0 0; }
        to { translate: 0 -14px; }
    }

    @media (prefers-reduced-motion: reduce) {
        .kaiki-auth-brandmark::before,
        .kaiki-auth-brandmark::after {
            animation: none;
            transition: none;
        }
    }

    /* --- a wide screen: the blue is the left column ------------------- */
    @media (min-width: 1024px) {
        .fi-simple-layout {
            display: grid;
            grid-template-columns: 42% 58%;
            align-items: stretch;
        }

        /* The first grid item keeps the left column; the brandmark is laid
           over it, since it lives deep inside the form's markup. */
        .fi-simple-layout::before {
            content: '';
            background-color: #0F2E57;
        }

        .fi-simple-layout > *:not(.fi-simple-main-ctn) {
            grid-column: 2;
        }

        .fi-simple-layout > .fi-simple-main-ctn {
            padding-inline: 2rem;
        }

        .fi-simple-layout .fi-simple-main {
            max-inline-size: 28rem;
            padding-inline: 0;
        }

        .kaiki-auth-brandmark {
            inline-size: 42%;
            block-size: 100%;
            padding: 3.25rem 3.5rem;
        }

        .kaiki-auth-brandmark-name { font-size: 3.25rem; }
        .kaiki-auth-brandmark img { max-block-size: 4rem; }
        .kaiki-auth-brandmark p { font-size: 1.125rem; }
    }

    @media (min-width: 1536px) {
        .fi-simple-layout { grid-template-columns: 44% 56%; }
        .kaiki-auth-brandmark { inline-size: 44%; }
    }

    /* --- a phone: the blue is a band across the top -------------------
       Short enough that the password field is still on the first screen. */
    @media (max-width: 1023.98px) {
        .kaiki-auth-brandmark {
            inset-inline-end: 0;
            block-size: 10.5rem;
            padding: 1.75rem 1.5rem;
            justify-content: flex-start;
        }

        .kaiki-auth-brandmark-name { font-size: 2.25rem; }
        .kaiki-auth-brandmark img { max-block-size: 3rem; }
        .kaiki-auth-brandmark p { font-size: .95rem; margin-top: .5rem; }

        .kaiki-auth-brandmark::after { block-size: calc(70% + 24px); background-size: 520px 150px; }
        .kaiki-auth-brandmark::before { block-size: calc(90% + 24px); background-size: 780px 220px; }

        .fi-simple-layout > .fi-simple-main-ctn {
            align-items: flex-start;
            padding-block-start: 10.5rem;
        }

        .fi-simple-layout .fi-simple-main { margin-block: .75rem 1.75rem; }
    }
</style>

