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
     *
     * The row still scrolls sideways when it has to — five Greek words with
     * their counts do not fit a phone — but **without the scrollbar**
     * (product owner, 2026-09-21: «βγαίνει ένα συμβολάκι για scroll»). Windows
     * draws a classic grey bar inside the element, which under a row of tabs
     * reads as a second, broken underline sitting on the hairline. Hiding the
     * bar costs nothing here: the tabs are also reachable by touch swipe, by
     * the arrow keys and by Tab, and a tab scrolled out of view is scrolled
     * into it when it is focused.
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
        scroll-padding-inline: 2.5rem;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }

    .ka-line-tabs > nav.fi-tabs::-webkit-scrollbar {
        display: none;
    }

    /*
     * Rule Α of the form mockup (2026-09-24): the closed tabs are dark enough
     * to read (#7B8BA1 was a pale grey the eye skipped), the open one has a
     * 3px bar, and the row stays under the top bar while the form scrolls —
     * a long «Τιμές» tab no longer loses its way back to «Βασικά».
     */
    .ka-line-tabs > nav.fi-tabs {
        position: sticky;
        top: 4rem;
        z-index: 15;
        background: #F7FAFD;
    }

    .dark .ka-line-tabs > nav.fi-tabs {
        background: rgb(var(--gray-950));
    }

    .ka-line-tabs > nav.fi-tabs .fi-tabs-item {
        padding: .7rem 0;
        margin-block-end: -1px;
        border-radius: 0;
        border-block-end: 3px solid transparent;
        background: transparent !important;
    }

    .ka-line-tabs > nav.fi-tabs .fi-tabs-item-label {
        font-size: 1.0625rem;
        font-weight: 600;
        color: #4F607A;
    }

    .ka-line-tabs > nav.fi-tabs .fi-tabs-item.fi-active {
        border-block-end-color: var(--ka-sea-deep);
    }

    .ka-line-tabs > nav.fi-tabs .fi-tabs-item.fi-active .fi-tabs-item-label {
        color: #15233A;
    }

    /*
     * Where the row runs past the screen, its cut edge fades (phone audit,
     * 2026-09-23): with no scrollbar, a row that ended flush on «Όρο» gave no
     * sign that «Σελίδα» existed. The script at the end of this file says which
     * edges have more behind them, and brings the open tab into view.
     */
    .ka-line-tabs > nav.fi-tabs[data-more-end] {
        -webkit-mask-image: linear-gradient(to left, transparent, #000 3rem);
        mask-image: linear-gradient(to left, transparent, #000 3rem);
    }

    .ka-line-tabs > nav.fi-tabs[data-more-start] {
        -webkit-mask-image: linear-gradient(to right, transparent, #000 3rem);
        mask-image: linear-gradient(to right, transparent, #000 3rem);
    }

    .ka-line-tabs > nav.fi-tabs[data-more-start][data-more-end] {
        -webkit-mask-image: linear-gradient(to right, transparent, #000 3rem, #000 calc(100% - 3rem), transparent);
        mask-image: linear-gradient(to right, transparent, #000 3rem, #000 calc(100% - 3rem), transparent);
    }

    .dark .ka-line-tabs > nav.fi-tabs {
        border-block-end-color: rgba(255, 255, 255, .1);
    }

    .dark .ka-line-tabs > nav.fi-tabs .fi-tabs-item-label {
        color: rgb(var(--gray-400));
    }

    .dark .ka-line-tabs > nav.fi-tabs .fi-tabs-item.fi-active {
        border-block-end-color: rgb(var(--primary-400));
    }

    .dark .ka-line-tabs > nav.fi-tabs .fi-tabs-item.fi-active .fi-tabs-item-label {
        color: #fff;
    }

    /*
     * Filament's own tabs over a list («Όλες · Πρόχειρη · Σε πώληση …») wrap
     * onto a second row on a phone rather than scrolling sideways out of
     * sight: they are pills, and a second row of pills reads as more of the
     * same.
     */
    @media (max-width: 639.98px) {
        :not(.ka-line-tabs) > nav.fi-tabs {
            flex-wrap: wrap;
            overflow-x: visible;
            justify-content: flex-start;
        }
    }

    /*
     * A page whose header ends in «⋯» ({@see \App\Filament\Support\MoreActions})
     * keeps its buttons beside the title on a phone instead of on a row of
     * their own under it; they drop below only when the two do not fit.
     */
    @media (max-width: 639.98px) {
        .fi-header:has(.fi-ac-btn-group) {
            flex-direction: row;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            column-gap: .75rem;
            row-gap: .75rem;
        }

        .fi-header:has(.fi-ac-btn-group) > div:first-child {
            flex: 1 1 10rem;
            min-width: 0;
        }
    }

    /* The «Μενού» button: the sidebar's navy all but vanished on the dark top
       bar, so it takes the sidebar's lighter active blue there. */
    .dark .ka-mobile-menu-btn {
        background: var(--ka-sea-active);
        box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .08);
    }

    /* The count beside a tab: a small quiet pill («1 ενεργό», «7 από 8») —
       quiet text until 2026-09-24, when the mockup gave it an edge so it reads
       as a note on the tab rather than more of its name. */
    .ka-line-tabs > nav.fi-tabs .fi-badge:not(.fi-color-warning) {
        background: #E8EEF6;
        color: #4F607A;
    }

    .dark .ka-line-tabs > nav.fi-tabs .fi-badge:not(.fi-color-warning) {
        background: rgba(255, 255, 255, .08);
        color: rgb(var(--gray-300));
    }

    .ka-line-tabs > nav.fi-tabs .fi-badge {
        --tw-ring-color: transparent;
        box-shadow: none;
        padding-inline: .5rem;
        border-radius: 999px;
        font-weight: 600;
        font-size: .75rem;
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

    .dark .ka-locale-switch-label {
        color: rgb(var(--gray-400));
    }

    .dark .ka-locale-switch-buttons {
        border-color: rgba(255, 255, 255, .15);
        background: rgba(255, 255, 255, .05);
    }

    .dark .ka-locale-switch-buttons button {
        color: rgb(var(--gray-300));
    }

    .dark .ka-locale-switch-buttons button.ka-locale-on {
        background: rgb(var(--primary-500));
        color: rgb(var(--primary-950));
    }

    .dark .ka-locale-switch-buttons button:focus-visible {
        outline-color: rgb(var(--primary-400));
    }

    /* On a phone the switch is a small pair at the right, not a row with a
       sentence: the label is the buttons' accessible name already. */
    @media (max-width: 639.98px) {
        .ka-locale-switch-label {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0 0 0 0);
            white-space: nowrap;
        }

        .ka-locale-switch-buttons button {
            min-height: 2.75rem;
        }
    }

    form[data-ka-locale="el"] .ka-locale--en,
    form[data-ka-locale="en"] .ka-locale--el {
        display: none;
    }

    /*
     * Rules Β and Γ of the form mockup (2026-09-24, «μου φαίνεται λίγο χάος»).
     *
     * Β — every section has a header you can see: a pale strip, the icon in a
     * small blue tile, the title a step above the field labels (17px against
     * 14px). Until now a section title and a field label were the same size,
     * so a long tab read as one undivided list of fields.
     *
     * Γ — a table that carries its own heading (a relation manager inside a
     * form: «Δρομολόγια», «Τιμοκατάλογοι», «Πρόσθετα») gets the same strip,
     * so it reads as a section like the others. And the footer that only says
     * «Προβολή 1 αποτελέσματος» goes when there is a single page: a pager
     * with nothing to page is one more line of noise.
     */
    .fi-main .fi-section:not(.fi-aside) > .fi-section-header,
    .fi-main .fi-ta-ctn .fi-ta-header:has(.fi-ta-header-heading) {
        background: #F1F5FA;
        border-start-start-radius: .75rem;
        border-start-end-radius: .75rem;
    }

    .fi-main .fi-section.fi-collapsed > .fi-section-header {
        border-radius: .75rem;
    }

    .fi-main .fi-section-header-heading,
    .fi-main .fi-ta-header-heading {
        font-size: 1.0625rem;
        line-height: 1.4;
    }

    .fi-main .fi-section-header-icon {
        box-sizing: content-box;
        width: 1.125rem;
        height: 1.125rem;
        padding: .5rem;
        margin-top: 0;
        align-self: center;
        border-radius: .6rem;
        background: #EAF1FA;
        color: #1E5AA8;
    }

    .fi-main .fi-ta-ctn .fi-ta-pagination:not(:has(.fi-pagination-items)) {
        display: none;
    }

    .dark .fi-main .fi-section:not(.fi-aside) > .fi-section-header,
    .dark .fi-main .fi-ta-ctn .fi-ta-header:has(.fi-ta-header-heading) {
        background: rgba(255, 255, 255, .03);
    }

    .dark .fi-main .fi-section-header-icon {
        background: rgb(var(--primary-400) / .14);
        color: rgb(var(--primary-300));
    }

    /*
     * Rule Δ: a repeated item that folds (the trip's age bands) reads as a
     * row — its summary in the header, bold — and its switches sit together
     * in one pale box instead of four loose lines between the fields.
     */
    .fi-main .fi-fo-repeater-item-header > h4 {
        font-weight: 600;
        font-size: .9375rem;
    }

    .fi-main .ka-toggle-box {
        padding: .9rem 1rem;
        border: 1px solid #E1E8F2;
        border-radius: .75rem;
        background: #F7FAFD;
    }

    .dark .fi-main .ka-toggle-box {
        border-color: rgba(255, 255, 255, .08);
        background: rgba(255, 255, 255, .03);
    }

    /* A warning said in the form rather than a refusal (the licence note). */
    .ka-warning-note {
        padding: .7rem .9rem;
        border-radius: .6rem;
        background: rgb(var(--warning-50));
        color: rgb(var(--warning-800));
        font-size: .875rem;
    }

    .dark .ka-warning-note {
        background: rgb(var(--warning-400) / .12);
        color: rgb(var(--warning-300));
    }

    /*
     * The new-trip wizard (2026-09-24): periods as cards like the edit page's
     * ticks, and the price lines with the group's name beside its prices.
     */
    .ka-period-cards .fi-fo-checkbox-list-option-label {
        padding: .7rem .85rem;
        border: 1px solid rgb(var(--gray-200));
        border-radius: .75rem;
        background: #fff;
    }

    .ka-period-cards .fi-fo-checkbox-list-option-label:has(input:checked) {
        border-color: rgb(var(--primary-600));
        background: rgb(var(--primary-50));
    }

    .dark .ka-period-cards .fi-fo-checkbox-list-option-label {
        border-color: rgba(255, 255, 255, .1);
        background: rgba(255, 255, 255, .03);
    }

    .ka-price-row {
        align-items: end;
        padding-block: .6rem;
        border-bottom: 1px solid #E1E8F2;
    }

    .ka-price-row-name {
        font-weight: 600;
        padding-bottom: .6rem;
    }

    /* Rule Ε: the trip's name and its state on one line. */
    .ka-title-with-state {
        display: inline-flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .4rem .75rem;
    }

    /*
     * «Πριν τη δημοσίευση» as a column of its own (2026-09-18). It follows the
     * form down the page, because it is the answer to "why can I not publish
     * this yet" and that question is asked from whichever tab is open.
     *
     * A fifth of the row since 2026-09-21, so the type inside comes down with
     * it: at the old size the chips set the column's width, and a heading in
     * the panel's default size next to them made a narrow card look like a
     * squeezed wide one rather than a margin note. Scoped to this card — the
     * chips are shared with the trips list, where they are read at full size.
     */
    .ka-checklist-side .fi-section-header-heading {
        font-size: .9375rem;
    }

    .ka-checklist-side .kc-row {
        gap: .3rem;
    }

    .ka-checklist-side .kc-chip {
        padding: .22rem .45rem;
        font-size: .72rem;
        gap: .25rem;
    }

    .ka-checklist-side .kc-icon {
        width: .8rem;
        height: .8rem;
    }

    .ka-checklist-side .kc-summary,
    .ka-checklist-side .kc-unsaved {
        font-size: .75rem;
    }

    @media (min-width: 1280px) {
        .ka-checklist-side {
            position: sticky;
            top: 5rem;
        }
    }

    /*
     * The heading of a grouped table row, on a phone (2026-09-21).
     *
     * Filament stacks a table into cards below `md` and the row becomes a grid,
     * where the group header is sized by its content and inherits the row's
     * `white-space: nowrap`. On «Τιμοκατάλογοι» that is the trip's name and,
     * under it, the boat, the people and the length of the day — the line that
     * says *which trip these prices belong to*. It was being cut mid-word:
     * «Ιδιωτική εκδρομή στον Μπάλ», «8 ώρες ·».
     *
     * Full width and ordinary wrapping. Not scoped to one screen: any grouped
     * table in the panel has the same heading in the same place.
     */
    .fi-ta-group-header {
        width: 100%;
        min-width: 0;
        flex-wrap: wrap;
    }

    .fi-ta-group-header > * {
        min-width: 0;
    }

    .fi-ta-group-header h4,
    .fi-ta-group-header p {
        white-space: normal;
    }

    /*
     * The same stacked layout labels every cell by putting its column name in a
     * `::before`. On a group-header row that label is a lie — the cell holds
     * «Ηλιοβασίλεμα στη Χώρα» and was announced as «Περίοδος», because the
     * header happens to sit in the first column's cell.
     */
    td:has(> .fi-ta-group-header)::before {
        content: none !important;
    }

    /*
     * «Σκαμμένο» — a box inside a box (product owner, 2026-09-22, direction Α).
     *
     * *«Τα boxes που εμφανίζονται μέσα σε άλλα boxes, όπως π.χ. στις τιμές αν
     * πας να βάλεις περίοδο, πρέπει να κάνουν λίγο πιο πολύ standout.»* And
     * before that: *«δεν είναι πολύ εμφανές πότε αλλάζει κάτι»*.
     *
     * The two complaints are one thing. A repeater row inside a repeater row
     * was drawn exactly like its parent — white, same radius, same hairline —
     * so the form had depth that the page did not show, and a row that
     * appeared on a click looked like part of what was already there.
     *
     * A **well**, not a second card: the nested block is recessed into its
     * parent with a tint and an inset shadow, and the fields inside it stay
     * white. Two cards with the same surface are two equal things; a well is
     * never mistaken for one, and it needs no extra border to say so — which
     * matters where these nest, because the third border in a row is the one
     * nobody can read.
     *
     * Marked in PHP (`extraFieldWrapperAttributes`) rather than matched with
     * `:has()`, so the intent is named where the field is written and a
     * repeater that merely happens to sit inside another does not get it by
     * accident.
     */
    .ka-nest {
        background: #EDF3FA;
        border-radius: 0.75rem;
        padding: 0.8rem 0.9rem;
        box-shadow: inset 0 1px 2px rgba(15, 46, 87, 0.07);
    }

    /* The label and the helper line belong to the well, so they come inside
       its padding rather than floating above it. */
    .ka-nest > .fi-fo-field-wrp-label {
        margin-bottom: 0.35rem;
    }

    /* Whatever is typed into stays white, the way every other field on the
       page is — that contrast is what makes the well read as depth rather
       than as a grey patch. */
    .ka-nest .fi-input-wrp,
    .ka-nest .fi-fo-repeater-item,
    .ka-nest .fi-btn {
        background-color: #fff;
    }

    .dark .ka-nest {
        background: rgba(255, 255, 255, 0.04);
        box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.35);
    }

    /* On a phone the well and the row inside it each gave a full inset, and
       an age band's fields came out 60px narrower than the rest of the form
       (phone audit, 2026-09-23). The well keeps a sliver; the rows keep a
       little less of their own. */
    @media (max-width: 639.98px) {
        .ka-nest {
            padding: .5rem .4rem;
            border-radius: .6rem;
        }

        .ka-nest .fi-fo-repeater-item-content {
            padding: .75rem !important;
        }

        .ka-nest .fi-fo-repeater-item-header {
            padding-inline: .75rem !important;
        }
    }

    .dark .ka-nest .fi-input-wrp,
    .dark .ka-nest .fi-fo-repeater-item,
    .dark .ka-nest .fi-btn {
        background-color: rgba(255, 255, 255, 0.05);
    }

    /*
     * And the row itself goes back to being one block. The stacked layout turns
     * every row into a two-track grid — label, value — which is right for a
     * price and wrong for a heading: with the label suppressed the heading was
     * left in the value track, a hundred pixels wide, reading four words to
     * the line. A group header is not a field; it spans the card.
     */
    /* Phone only (2026-09-23): unscoped, it turned the heading row of every
       grouped table on a tablet or a desktop into a block a third of the
       table wide. */
    @media (max-width: 767.98px) {
        tr:has(> td > .fi-ta-group-header),
        td:has(> .fi-ta-group-header) {
            display: block !important;
            width: 100% !important;
        }
    }
</style>

<script>
    /*
     * The line tabs' cut edges and the open tab (see the styles above). Runs on
     * load, on every Livewire redraw and when a tab is chosen; a row that fits
     * gets neither mark and so no fade.
     */
    (function () {
        const mark = (nav) => {
            const end = nav.scrollWidth - nav.clientWidth - nav.scrollLeft;

            nav.toggleAttribute('data-more-start', nav.scrollLeft > 2);
            nav.toggleAttribute('data-more-end', end > 2);
        };

        const reveal = (nav) => {
            const active = nav.querySelector('.fi-tabs-item.fi-active');

            if (active && nav.scrollWidth > nav.clientWidth) {
                const left = active.getBoundingClientRect().left - nav.getBoundingClientRect().left + nav.scrollLeft;
                const right = left + active.offsetWidth;

                if (left < nav.scrollLeft + 40 || right > nav.scrollLeft + nav.clientWidth - 40) {
                    nav.scrollLeft = left - (nav.clientWidth - active.offsetWidth) / 2;
                }
            }

            mark(nav);
        };

        const run = () => document.querySelectorAll('.ka-line-tabs > nav.fi-tabs').forEach((nav) => {
            if (! nav.dataset.kaTabs) {
                nav.dataset.kaTabs = '1';
                nav.addEventListener('scroll', () => mark(nav), { passive: true });
                nav.addEventListener('click', () => setTimeout(() => reveal(nav), 60));
            }

            reveal(nav);
        });

        document.addEventListener('DOMContentLoaded', run);
        document.addEventListener('livewire:navigated', run);
        window.addEventListener('resize', run);
        document.addEventListener('livewire:init', () => {
            window.Livewire.hook('morph.updated', () => requestAnimationFrame(run));
        });
    })();
</script>
