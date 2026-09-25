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

    /* The desktop's column carries the long line, the phone's band the short
       one; exactly one is ever displayed. */
    .kaiki-auth-brandmark-line-short { display: none; }

    /* Never an underlined link on these screens, not even on hover (Kaiki
       rule). Filament's links underline their label on hover and focus. */
    .fi-simple-layout a,
    .fi-simple-layout .fi-link,
    .fi-simple-layout .fi-link * {
        text-decoration: none !important;
    }

    /* The header's subheading, and ours on the reset page, start where the
       heading starts. */
    .fi-simple-layout .fi-simple-header-subheading { text-align: start; }

    /* «‹ Σύνδεση», the heading and the line under it on «Ξέχασα τον κωδικό».
       Filament's header is left empty there; see the page's view. */
    .fi-simple-page:has(.kaiki-auth-intro) .fi-simple-header { display: none; }

    .kaiki-auth-intro .fi-simple-header-heading {
        margin: 0;
        font-size: 1.5rem;
        font-weight: 700;
        line-height: 2rem;
        letter-spacing: -.025em;
        color: #13233A;
    }

    .kaiki-auth-intro .fi-simple-header-subheading {
        margin: .5rem 0 0;
        font-size: .9375rem;
        line-height: 1.45;
        color: #45556A;
    }

    .kaiki-auth-back {
        display: inline-flex;
        align-items: center;
        gap: .375rem;
        min-block-size: 2.75rem;
        margin-block-end: .25rem;
        font-weight: 600;
        color: #174F94;
    }

    .kaiki-auth-back:hover { color: #0F2E57; }

    /* A wrong email or password: said once, above the fields, with what to
       try (Α1, 2026-09-23). The field keeps its red border; its own line
       under it would say the same thing a second time. */
    .kaiki-login-alert {
        display: flex;
        gap: .625rem;
        align-items: flex-start;
        padding: .75rem .875rem;
        border-radius: .625rem;
        background-color: #FEF1EF;
        color: #B42318;
        line-height: 1.4;
    }

    .kaiki-login-alert__icon {
        flex: none;
        display: grid;
        place-items: center;
        inline-size: 1.25rem;
        block-size: 1.25rem;
        margin-block-start: .0625rem;
        border-radius: 50%;
        background-color: #B42318;
        color: #fff;
        font-size: .8125rem;
        font-weight: 800;
    }

    .kaiki-login-alert__title { margin: 0; font-weight: 600; }
    .kaiki-login-alert__hint { margin: .125rem 0 0; color: #7A2A20; font-size: .9375rem; }

    .kaiki-login-form--rejected .fi-fo-field-wrp-error-message { display: none; }

    /* «Ξέχασα τον κωδικό» is in the markup twice (see `App\Filament\App\Auth\Login`):
       beside the password's label here on a desktop, in the «Να με θυμάσαι»
       row on a phone. */
    .kaiki-login-forgot-phone { display: none !important; }

    /* --- a phone or a tablet held upright: the blue is a band on top ----
       Direction Α, variant Α1 of docs/mockups/login-mobile-directions.html
       (product owner, 2026-09-23): a 230 px band with the mark in the middle
       and the line under it, the language switch small in its corner, and
       the whole empty form on the first screen of an iPhone SE.

       **Calm sizes, a fixed band** (second round, same day: «έγιναν τεράστιες
       οι φόρμες», and the band that folded while typing felt odd). Fields
       and the button are 48 px, the type inside the fields exactly 16 px —
       the size under which iOS zooms the page on focus, and no bigger —
       labels 14 px, the heading 26 px. The band never changes height; when
       a field takes the focus the browser scrolls it into view as it always
       does, helped by the few lines of script below.

       **Tablets.** Upright (768, 820 wide) they get this layout, with the
       form a centred 420 px column rather than stretched across the screen.
       On their side (1024 wide and up) they get the desktop's split, which
       fits there. */
    @media (max-width: 1023.98px) {
        .kaiki-auth-brandmark {
            inset-inline-end: 0;
            block-size: 230px;
            padding: 0 1.5rem;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .kaiki-auth-brandmark-name { font-size: 46px; }

        .kaiki-auth-brandmark img {
            max-block-size: 3.5rem;
            margin-inline: auto;
        }

        .kaiki-auth-brandmark .kaiki-auth-brandmark-line { display: none; }

        .kaiki-auth-brandmark .kaiki-auth-brandmark-line-short {
            display: block;
            margin: 12px auto 0;
            font-size: 16px;
            line-height: 1.4;
        }

        /* The waves, scaled to the band rather than cropped out of the
           desktop's tiles (product owner, 2026-09-23: «σαν να τελειώνει το
           σχέδιο»). The desktop's tiles are 520 × 220 and 780 × 330 for a
           drawing that is 520 × 170, which on a desktop only adds sky above
           it; squeezed to the phone's heights the browser kept the drawing's
           proportions and shrank it *narrower than its tile*, so every tile
           ended in a blank gap and each wave visibly stopped short of the
           next. `waves-light-band.svg` is the same drawing told to fill
           whatever box it gets (`preserveAspectRatio="none"`, strokes that
           keep their width), so the tiles stay exactly 520 and 780 wide —
           the drift's one-tile loop is unchanged and seamless — and only the
           height follows the band. Each layer is exactly one tile tall and
           sits on the band's floor, so no line is sliced at the top or by
           the bottom edge. */
        .kaiki-auth-brandmark::before,
        .kaiki-auth-brandmark::after {
            inset-block-end: 0;
            background-image: url('/images/waves-light-band.svg');
        }

        .kaiki-auth-brandmark::after { block-size: 120px; background-size: 520px 120px; }
        .kaiki-auth-brandmark::before { block-size: 170px; background-size: 780px 170px; }

        /* The language switch: small, in the band's top-right corner, light
           on the blue. Flags off here; the two letters are enough. */
        .fi-simple-page > div:has(> .kaiki-locale-switcher) {
            position: absolute;
            inset-block-start: 12px;
            inset-inline-end: 16px;
            z-index: 2;
            margin: 0 !important;
        }

        .fi-simple-layout .kaiki-locale-switcher {
            padding: 0;
            gap: 0;
            border-radius: 8px;
            background-color: rgb(255 255 255 / .12);
        }

        .fi-simple-layout .kaiki-locale-switcher__option {
            position: relative;
            padding: 9px 12px;
            border-radius: 8px;
            font-size: 14px;
            color: #DCE6F3;
        }

        /* A 48 px target around a 34 px pill. */
        .fi-simple-layout .kaiki-locale-switcher__option::after {
            content: '';
            position: absolute;
            inset: -7px 0;
        }

        .fi-simple-layout .kaiki-locale-switcher__option:hover {
            color: #fff;
            background-color: rgb(255 255 255 / .08);
        }

        .fi-simple-layout .kaiki-locale-switcher__option[aria-current='true'] {
            color: #0F2E57;
            background-color: rgb(255 255 255 / .92);
            box-shadow: none;
        }

        .fi-simple-layout .kaiki-locale-switcher__option:focus-visible { outline-color: #fff; }
        .fi-simple-layout .kaiki-locale-switcher__flag { display: none; }

        /* --- the form under the band --- */
        .fi-simple-layout > .fi-simple-main-ctn {
            align-items: flex-start;
            padding-block-start: 230px;
        }

        .fi-simple-layout .fi-simple-main {
            margin-block: 24px;
            padding-block: 0;
        }

        .fi-simple-page > section { row-gap: 14px; }

        .fi-simple-layout .fi-simple-header-heading {
            font-size: 26px;
            font-weight: 800;
            line-height: 1.2;
            letter-spacing: -.02em;
            color: #13233A;
        }

        .kaiki-auth-intro .fi-simple-header-heading { font-size: 24px; }
        .kaiki-auth-intro .fi-simple-header-subheading { font-size: 15px; }

        /* The error takes the heading's place, as in the mockup's third
           screen: the band, the alert, the fields. */
        .fi-simple-page:has(.kaiki-login-alert) .fi-simple-header { display: none; }

        .kaiki-login-alert { font-size: 15px; }
        .kaiki-login-alert__hint { font-size: 14px; }

        .fi-simple-layout .fi-form { row-gap: 14px; }
        .fi-simple-layout .fi-form > .fi-fo-component-ctn { row-gap: 14px; }

        .fi-simple-layout .fi-fo-field-wrp > .grid { row-gap: 6px; }

        .fi-simple-layout .fi-fo-field-wrp-label span {
            font-size: 14px;
            font-weight: 600;
            color: #13233A;
        }

        /* Every field is required here and says so by being there. */
        .fi-simple-layout .fi-fo-field-wrp-label sup { display: none; }

        /* 48 px fields, 16 px type inside them: under 16 px iOS zooms the
           whole page in when a field takes the focus. */
        .fi-simple-layout .fi-fo-text-input {
            min-block-size: 48px;
            border-radius: 10px;
        }

        .fi-simple-layout .fi-fo-text-input:not(:focus-within) { --tw-ring-color: #BFCBDB; }

        .fi-simple-layout .fi-fo-text-input .fi-input {
            min-block-size: 46px;
            padding-inline: 14px;
            font-size: 16px;
            color: #13233A;
        }

        /* «Εμφάνιση» as a word inside the field, not only an eye. The label is
           the icon button's own (visually hidden) text, shown here. */
        .fi-simple-layout .fi-input-wrp-suffix {
            border-inline-start: 0;
            padding-inline: 0 4px;
        }

        .fi-simple-layout .fi-input-wrp-suffix .fi-icon-btn {
            inline-size: auto;
            block-size: 44px;
            padding-inline: 10px;
            color: #174F94;
        }

        .fi-simple-layout .fi-input-wrp-suffix .fi-icon-btn > svg { display: none; }

        .fi-simple-layout .fi-input-wrp-suffix .fi-icon-btn > .sr-only {
            position: static;
            inline-size: auto;
            block-size: auto;
            margin: 0;
            overflow: visible;
            clip: auto;
            white-space: nowrap;
            font-size: 14px;
            font-weight: 600;
        }

        /* «Να με θυμάσαι» and «Ξέχασα τον κωδικό» share one 44 px row. */
        .kaiki-login-forgot-desktop { display: none !important; }
        .fi-simple-layout .kaiki-login-forgot-phone { display: inline-flex !important; }

        .fi-simple-layout .fi-fo-component-ctn > :has(.fi-checkbox-input) {
            margin-block: -6px;
        }

        .fi-simple-layout .fi-fo-field-wrp:has(.fi-checkbox-input) > div > div:first-child {
            min-block-size: 44px;
        }

        .fi-simple-layout .fi-fo-field-wrp-label:has(.fi-checkbox-input) span {
            font-size: 15px;
            font-weight: 400;
        }

        .fi-simple-layout .fi-checkbox-input {
            inline-size: 20px;
            block-size: 20px;
            border-radius: 5px;
        }

        .fi-simple-layout .kaiki-login-forgot-phone {
            padding-block: 12px;
            padding-inline-start: 8px;
        }

        /* The mockup's link blue, darkened to #174F94 so it clears 7:1 on
           the light ground (#1D5FAF is 6.1:1). Filament colours the label
           through its own custom properties, hence the `!important`. */
        .fi-simple-layout .kaiki-login-forgot-phone,
        .fi-simple-layout .kaiki-login-forgot-phone * {
            font-size: 15px;
            color: #174F94 !important;
        }

        .fi-simple-layout .fi-form-actions .fi-btn {
            min-block-size: 48px;
            border-radius: 10px;
            font-size: 16px;
        }
    }

    /* A tablet held upright: the same band, the form a centred column. */
    @media (min-width: 640px) and (max-width: 1023.98px) {
        .fi-simple-layout .fi-simple-main {
            max-inline-size: 420px;
            margin-block-start: 48px;
            padding-inline: 0;
        }
    }
</style>

<script>
    /* On a phone, keep the focused field and the button under it in sight
       once the on-screen keyboard is up (product owner, 2026-09-23, second
       round). Nothing about the layout changes — the band stays as it is —
       this only scrolls, and only when the keyboard has actually covered
       part of the form. The browser's own scroll brings the field into view;
       this centres it, so on a 390 × 844 phone the password and «Σύνδεση»
       are both above the keys. */
    (function () {
        if (window.kaikiAuthScroll) {
            return;
        }

        window.kaikiAuthScroll = true;

        const narrow = window.matchMedia('(max-width: 1023.98px)');
        const viewport = window.visualViewport;
        const fieldSelector = '.fi-simple-layout input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"])';

        let pending = 0;

        const reveal = () => {
            const field = document.activeElement;

            if (! narrow.matches || ! field?.matches?.(fieldSelector)) {
                return;
            }

            const visible = viewport ? viewport.offsetTop + viewport.height : window.innerHeight;
            const button = field.form?.querySelector('[type="submit"]');
            const lowest = (button ?? field).getBoundingClientRect().bottom;

            if (lowest > visible) {
                field.scrollIntoView({ block: 'center' });
            }
        };

        const later = () => {
            clearTimeout(pending);
            pending = setTimeout(reveal, 300);
        };

        document.addEventListener('focusin', later);
        viewport?.addEventListener('resize', later);
    })();
</script>

