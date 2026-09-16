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

    ## These screens are light, whatever the operator's laptop is set to

    Everything below paints a light ground — `#f7f9fc`, the dot grid, the scrim
    over the photograph. Filament, meanwhile, still puts `dark` on `<html>`
    whenever the operating system asks for it, and its own utilities then set
    `dark:text-white` on the heading, the labels, the inputs and the hints. The
    result on a laptop in dark mode was **white type on that near-white ground**:
    the labels were invisible and the heading was a ghost.

    The fix is to take `dark` off, not to answer it. A dark variant of this
    screen is a second design — a second photograph treatment, a second scrim, a
    second dot grid — and this screen is one composition built around a bright
    photograph. Overriding the colours one utility at a time was the other
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
       reads the OS, not the class above. Without this the fields keep a dark
       browser chrome on a light page. */
    .fi-simple-layout { color-scheme: light; }
    /* The scrim over the photograph's lower half, so «Powered by Kaiki» and the
       locale switcher stay legible on a bright sky. Declared once, used twice. */
    :root {
        --kaiki-auth-scrim: linear-gradient(
            to bottom,
            rgba(11, 39, 64, .10) 0%,
            rgba(11, 39, 64, .40) 60%,
            rgba(11, 39, 64, .74) 100%
        );
    }

    /* Both layouts hang a wordmark off this, so it is the containing block at
       every width. */
    .fi-simple-layout { position: relative; }

    @media (min-width: 1024px) {
        .fi-simple-layout {
            /* Two columns. `min-h-screen` is Filament's and stays; the flex
               column it sets is replaced.

               42/58, arrived at by going too far first: 40/60 turned the
               photograph into a stripe down the edge and was taken back to
               halves, then trimmed twice. The form is capped at 28rem whatever
               the column does, so this is about how much picture there is, not
               about room for fields.

               The containing block for the wordmark is set once, outside this
               query, because the phone puts a wordmark on a banner too. */
            display: grid;
            grid-template-columns: 42% 58%;
            align-items: stretch;
        }

        /* The wordmark, moved onto the photograph's bottom-left corner.

           The element is Filament's own `.fi-logo` — the panel's `brandName()`
           or `brandLogo()`, whichever is configured — taken out of the flow and
           placed against the layout rather than copied into a `content:`
           string. A copy would be a second place to change the product's name,
           and it would be the place nobody remembers.

           It had a rust rule above it for an afternoon and lost it: on a
           photograph a short bar over a word reads as a stray graphic rather
           than as the accent it is on a white page. */
        /* Above `lg` the mark on the photograph is Filament's own `.fi-logo`,
           so the phone's copy is not rendered at all. */
        .kaiki-auth-brandmark { display: none; }

        .fi-simple-layout .fi-logo {
            position: absolute;
            inset-block-end: 3.25rem;
            inset-inline-start: 3.5rem;
            z-index: 2;
            margin: 0;
            color: #fff;
            font-size: 2.9rem;
            font-weight: 800;
            line-height: 1;
            letter-spacing: -.025em;
            /* The photograph is a photograph: no gradient is dark everywhere a
               bright sky might be, so the type carries its own shadow too. */
            text-shadow: 0 2px 20px rgba(6, 16, 20, .55);
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
           the card growing to the full width of the half.

           ## The pattern behind it

           A dot grid in the brand navy at seven per cent — enough that the half
           is a surface rather than a blank, not enough to be a thing anybody
           looks at. Two gradients rather than an image: a `radial-gradient`
           needs no file, no request and no `img-src` allowance, and it stays
           sharp at any pixel ratio.

           The wash on top is what keeps it honest. Dots running straight under
           a password field make the field harder to read, so a soft ellipse of
           the page's own colour sits over the middle and fades them out exactly
           where the form is. The pattern survives at the edges, which is where
           it was wanted. */
        .fi-simple-layout > .fi-simple-main-ctn {
            padding-inline: 2rem;
            background-color: #f7f9fc;
            background-image:
                radial-gradient(ellipse 34rem 30rem at 50% 48%,
                                rgba(247, 249, 252, .97) 38%,
                                rgba(247, 249, 252, 0) 78%),
                radial-gradient(circle at center,
                                rgba(18, 58, 94, .07) 1.1px, transparent 1.1px);
            background-size: 100% 100%, 22px 22px;
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

        /* Left, not centred. A centred heading over left-aligned fields is two
           alignments in a column four inches wide. */
        .fi-simple-layout .fi-simple-header { align-items: flex-start; }

        /* And the language switch with it. The switcher carries an inline
           `justify-content: flex-end` for the case where it is the only thing
           at the top of a page and has nothing to line up with; here it has the
           heading directly below it, so it starts where the heading starts.
           Inline styles are why this needs `!important`. */
        .fi-simple-page > div:has(> .kaiki-locale-switcher) {
            justify-content: flex-start !important;
        }

        .fi-simple-layout .fi-simple-header-heading {
            text-align: start;
        }
    }

    /* Past this width the picture does not need to keep growing with the
       window; two points back gives the form the difference. */
    @media (min-width: 1536px) {
        .fi-simple-layout { grid-template-columns: 44% 56%; }
    }

    /* --- below `lg` ------------------------------------------------
       No photograph: one above a sign-in form on a phone is a screenful of
       scrolling between an operator and the password field.

       What was left was not Filament's layout working, though — it was
       Filament's layout with nothing to sit on. `fi-simple-main` is a white
       card with a ring, and its corners only round from 640px up, so at 390px
       it rendered as a full-width white band with a hairline across the screen
       at each end, floating in grey. Three bands, and none of them meant
       anything.

       So on a phone it stops pretending to be a card: the page is one surface,
       the same patterned off-white as the form's half on a wide screen, and the
       form sits on it. The alignment matches too — heading and wordmark to the
       left, where the fields already were. */
    @media (max-width: 1023.98px) {
        .fi-simple-layout {
            background-color: #f7f9fc;
            background-image:
                radial-gradient(ellipse 22rem 26rem at 50% 45%,
                                rgba(247, 249, 252, .97) 40%,
                                rgba(247, 249, 252, 0) 80%),
                radial-gradient(circle at center,
                                rgba(18, 58, 94, .07) 1.1px, transparent 1.1px);
            background-size: 100% 100%, 22px 22px;
        }

        /* Not a card any more: no ground of its own, no ring, no shadow. */
        .fi-simple-layout .fi-simple-main {
            background: transparent;
            box-shadow: none;
            --tw-ring-color: transparent;
        }

        .fi-simple-layout .fi-simple-header { align-items: flex-start; }
        .fi-simple-layout .fi-simple-header-heading { text-align: start; }

        /* Under the banner, not floating in the middle of what is left of the
           screen. Filament centres this vertically, which is right for a card
           on an empty page and wrong once there is a masthead above it — it
           left a hand's width of dotted nothing between the two. */
        .fi-simple-layout > .fi-simple-main-ctn { align-items: flex-start; }
        .fi-simple-layout .fi-simple-main { margin-block: 2.25rem; }

        /* The mark on top, centred, and no photograph.

           A band of the picture was tried here first and taken out: on a phone
           the sign-in screen is one job, and a masthead is the part of it that
           can be a name rather than a scene.

           This is `auth-brandmark.blade.php`, not Filament's `.fi-logo` —
           Filament renders that one below the language switcher, and the order
           wanted is mark, language, form. Filament's copy is hidden here so
           there is exactly one on screen. */
        .fi-simple-layout .fi-logo { display: none; }

        /* The language switch to the left, under the mark and over the form,
           where the heading and the fields already start. Its inline
           `justify-content: flex-end` is for a page where it is the only thing
           at the top; here it has a column to line up with. */
        .fi-simple-page > div:has(> .kaiki-locale-switcher) {
            justify-content: flex-start !important;
        }

        .kaiki-auth-brandmark {
            display: flex;
            justify-content: center;
            margin-block-end: 1.1rem;
        }

        .kaiki-auth-brandmark span {
            font-size: 2rem;
            font-weight: 800;
            line-height: 1;
            letter-spacing: -.02em;
            color: var(--kaiki-navy, #123a5e);
        }

        /* A logo is whatever shape it is; this only stops a wide one running
           off a 390px screen. */
        .kaiki-auth-brandmark img {
            max-block-size: 3rem;
            max-inline-size: 70%;
            inline-size: auto;
        }
    }
</style>
