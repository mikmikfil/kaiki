{{--
    Lists as boxes on a phone (mobile direction A, product owner 2026-09-17).

    Below `md` every table in `/app` stops being a table: each row becomes a
    box, the first column is its title, and every other value sits under it
    with its column's name in small type. Κρατήσεις, Αναχωρήσεις, Εκδρομές,
    Σκάφη and every other list get it at once, which is the point — "every row
    is a box" is a rule for the phone, not a feature of four screens.

    ## Why CSS and a few lines of script, rather than Filament's own layouts

    Filament v3 can stack a table's columns (`Split`, `Stack`) and lay rows out
    in a grid (`contentGrid`), but that is decided per table, for every width:
    it would change the desktop tables too, and each of the thirty resources
    would need its columns rewritten. The desktop keeps its tables exactly as
    they are; only a narrow screen reflows.

    The column names come from the header, which a box has no room for. The
    script copies each header's text onto its cells as `data-label` — once on
    load, and again whenever Livewire redraws a table (sorting, filtering,
    paging) — and the stylesheet prints it. A cell whose header is empty (the
    checkbox, the actions) gets no label.

    ## What the script marks (phone audit, 2026-09-23)

    - `data-box-title` — the first cell that is actually shown. A column hidden
      on a phone (`visibleFrom('md')`, rendered `hidden md:table-cell`) is
      skipped, or the box's title slot was taken by a cell nobody could see.
    - `data-box-empty` — a cell with nothing in it. Filament wraps even an empty
      value in two divs and a link, so `td:empty` never matched and a blank
      «Επισκέπτης» still printed its label over nothing.
    - `data-box-menu-only` — an actions cell whose only visible action is the
      «⋯» ({@see \App\Filament\Support\MoreActions}). It moves to the top right
      of the box instead of taking a line of its own under a divider.
    - `data-box-zero` on the filter button's badge when it says «0».

    No hardcoded strings — `NoHardcodedStringsTest` scans this directory.
--}}
<style>
    .fi-ta-header-toolbar .fi-icon-btn-badge-ctn[data-box-zero] { display: none; }

    /* A column a tablet held upright has no room for, though a phone's box
       and a desktop row do (`->extraCellAttributes(['class' => …])`). */
    @media (min-width: 768px) and (max-width: 1023.98px) {
        .fi-ta-table .ka-tablet-hidden { display: none; }
    }

    @media (max-width: 767.98px) {
        .fi-ta-content { overflow: visible !important; }

        .fi-ta-table,
        .fi-ta-table > tbody { display: block; width: 100%; }

        .fi-ta-table > thead { display: none; }

        .fi-ta-table > tbody { padding: .6rem; background: transparent; border: 0 !important; }

        .fi-ta-table > tbody > tr.fi-ta-row {
            --ka-box-line: #EEF3F9;
            position: relative;
            display: grid;
            /* Title · checkbox · «⋯». An unused track is zero wide. */
            grid-template-columns: minmax(0, 1fr) auto auto;
            gap: .35rem .5rem;
            margin-block-end: .6rem;
            padding: .85rem .9rem;
            border: 1px solid #E1E8F2 !important;
            border-radius: 1rem;
            background: #fff;
        }

        .dark .fi-ta-table > tbody > tr.fi-ta-row {
            --ka-box-line: rgba(255, 255, 255, .08);
            border-color: rgba(255, 255, 255, .1) !important;
            background: rgb(var(--gray-900));
        }

        .fi-ta-table > tbody > tr.fi-ta-row > td {
            display: block;
            grid-column: 1 / -1;
            padding: 0 !important;
            border: 0 !important;
            min-width: 0;
        }

        /* A column a resource says is not for a phone — `->visibleFrom('md')`,
           which Filament renders as `hidden md:table-cell` — stays hidden. The
           rule above is specific enough to beat Tailwind's `.hidden` on its
           own, which is how four columns nobody wanted on a phone were showing
           up in the boxes anyway (2026-09-18). */
        .fi-ta-table > tbody > tr.fi-ta-row > td.hidden { display: none !important; }

        /* Inner padding Filament gives every value on a desktop row. */
        .fi-ta-table > tbody > tr.fi-ta-row > td .fi-ta-col-wrp > *,
        .fi-ta-table > tbody > tr.fi-ta-row > td .fi-ta-text,
        .fi-ta-table > tbody > tr.fi-ta-row > td .fi-ta-icon,
        .fi-ta-table > tbody > tr.fi-ta-row > td .fi-ta-image {
            padding-inline: 0 !important;
            padding-block: 0 !important;
        }

        /* Long values wrap instead of running off the box: a myDATA refusal,
           a colleague's email address. Filament sets `nowrap` and `truncate`
           for a desktop row, where the column is as wide as it needs. */
        .fi-ta-table > tbody > tr.fi-ta-row > td:not(.fi-ta-actions-cell),
        .fi-ta-table > tbody > tr.fi-ta-row > td:not(.fi-ta-actions-cell) * {
            white-space: normal !important;
            overflow-wrap: anywhere;
            text-overflow: clip !important;
        }

        .fi-ta-table > tbody > tr.fi-ta-row > td:not(.fi-ta-actions-cell) .truncate { overflow: visible; }

        .fi-ta-table > tbody > tr.fi-ta-row > td:not(.fi-ta-actions-cell) .max-w-max { max-width: 100%; }

        /* The checkbox, top right, out of the way of the title — with a tap
           area of 44px round an 18px box, laid over the padding rather than
           making the first line taller. */
        .fi-ta-table > tbody > tr.fi-ta-row > td.fi-ta-selection-cell {
            grid-column: 3;
            grid-row: 1;
            align-self: start;
        }

        .fi-ta-table > tbody > tr.fi-ta-row > td.fi-ta-selection-cell > div { padding: 0 !important; }

        .fi-ta-table > tbody > tr.fi-ta-row > td.fi-ta-selection-cell label {
            position: relative;
            display: flex;
            margin: 0;
            padding: 0;
        }

        .fi-ta-table > tbody > tr.fi-ta-row > td.fi-ta-selection-cell label::after {
            content: '';
            position: absolute;
            inset: -13px;
        }

        /* The first value is the box's title, with no label of its own. The
           script marks it, because which cell comes first depends on whether
           the table has checkboxes and which columns a phone hides. */
        .fi-ta-table > tbody > tr.fi-ta-row > td[data-box-title] {
            grid-column: 1;
            grid-row: 1;
            font-size: 1rem;
            font-weight: 700;
        }

        .fi-ta-table > tbody > tr.fi-ta-row > td[data-label]:not([data-box-title])::before {
            content: attr(data-label);
            display: block;
            font-size: .72rem;
            font-weight: 500;
            color: #7B8BA1;
            margin: 0;
        }

        .dark .fi-ta-table > tbody > tr.fi-ta-row > td[data-label]:not([data-box-title])::before { color: rgb(var(--gray-400)); }

        /* A value's own layout stays compact inside the box. */
        .fi-ta-table > tbody > tr.fi-ta-row > td[data-label]:not([data-box-title]) {
            display: grid;
            grid-template-columns: 7.5rem minmax(0, 1fr);
            align-items: baseline;
            gap: .5rem;
        }

        /* A blank value takes its label with it. */
        .fi-ta-table > tbody > tr.fi-ta-row > td[data-box-empty] { display: none !important; }

        /* The boxes are the surface; the card Filament draws round the whole
           table would be a box of boxes. */
        .fi-ta-ctn { background: transparent !important; box-shadow: none !important; --tw-ring-color: transparent !important; }

        /* The search box takes the row, beside the filter and column buttons;
           the empty bulk-actions slot on its left was indenting it ~45px. */
        .fi-ta-header-toolbar { padding-inline: .6rem !important; }
        .fi-ta-header-toolbar > div:not(:has(*)) { display: none; }
        .fi-ta-header-toolbar > .ms-auto { flex: 1 1 auto; min-width: 0; justify-content: flex-end; }
        .fi-ta-header-toolbar .fi-ta-search-field { flex: 1 1 auto; min-width: 0; }

        /* Actions along the bottom of the box, on one line: the one that
           matters on the left, «⋯» on the right. */
        .fi-ta-table > tbody > tr.fi-ta-row > td.fi-ta-actions-cell {
            margin-block-start: .35rem;
            padding-block-start: .35rem !important;
            border-block-start: 1px solid var(--ka-box-line) !important;
        }

        .fi-ta-table > tbody > tr.fi-ta-row > td.fi-ta-actions-cell > div { padding: 0 !important; }

        .fi-ta-table > tbody > tr.fi-ta-row > td.fi-ta-actions-cell .fi-ta-actions {
            justify-content: space-between;
            flex-wrap: nowrap;
            gap: 1rem;
            padding: 0 !important;
        }

        .fi-ta-table > tbody > tr.fi-ta-row > td.fi-ta-actions-cell .fi-ta-actions > .fi-dropdown:last-child { margin-inline-start: auto; }

        /* Only «⋯»: top right of the box, no divider, no line of its own. */
        .fi-ta-table > tbody > tr.fi-ta-row > td.fi-ta-actions-cell[data-box-menu-only] {
            grid-column: 3;
            grid-row: 1;
            align-self: start;
            margin: -.35rem -.4rem 0 0;
            padding: 0 !important;
            border: 0 !important;
        }

        .fi-ta-table > tbody > tr.fi-ta-row:has(> td[data-box-menu-only]) > td.fi-ta-selection-cell { grid-column: 2; }

        /*
         * A group heading (a trip on «Τιμοκατάλογοι», a day on «Αναχωρήσεις»)
         * is a label over the boxes that follow, not a box of its own with a
         * grey box inside it.
         */
        .fi-ta-table > tbody > tr.fi-ta-row:has(> td > .fi-ta-group-header) {
            display: block;
            margin: .9rem 0 .4rem;
            padding: 0 .25rem;
            border: 0 !important;
            border-radius: 0;
            background: transparent !important;
        }

        .fi-ta-table > tbody > tr.fi-ta-row:first-child:has(> td > .fi-ta-group-header) { margin-block-start: .1rem; }

        .fi-ta-table > tbody > tr.fi-ta-row > td.fi-ta-group-selection-cell { display: none !important; }

        .fi-ta-table .fi-ta-group-header {
            padding: 0 !important;
            background: transparent !important;
        }

        .fi-ta-table .fi-ta-group-header h4 { font-size: .95rem; font-weight: 700; }
    }
</style>

<script>
    (function () {
        const blank = (cell) => cell.innerText.trim() === ''
            && ! cell.querySelector('svg, img, input, select, textarea, button, [role="img"]');

        const shown = (el) => getComputedStyle(el).display !== 'none';

        const label = (table) => {
            const heads = [...table.querySelectorAll(':scope > thead > tr > th')].map((th) => th.innerText.trim());

            table.querySelectorAll(':scope > tbody > tr.fi-ta-row').forEach((row) => {
                const cells = [...row.children];
                const grouping = row.querySelector(':scope > td > .fi-ta-group-header') !== null;

                const title = grouping ? null : cells.find((cell) => cell.classList.contains('fi-ta-cell')
                    && ! cell.classList.contains('fi-ta-selection-cell')
                    && ! cell.classList.contains('fi-ta-actions-cell')
                    && ! cell.classList.contains('hidden'));

                cells.forEach((cell) => cell.toggleAttribute('data-box-title', cell === title));

                cells.forEach((cell, index) => {
                    // The checkbox and actions columns carry screen-reader
                    // text in their headers, not a name to print; a group
                    // heading is not the first column's value.
                    const unnamed = grouping
                        || cell.classList.contains('fi-ta-selection-cell')
                        || cell.classList.contains('fi-ta-group-selection-cell')
                        || cell.classList.contains('fi-ta-actions-cell');
                    const text = unnamed ? '' : (heads[index] || '');

                    if (text !== '') {
                        cell.setAttribute('data-label', text);
                    } else {
                        cell.removeAttribute('data-label');
                    }

                    const plain = cell.classList.contains('fi-ta-cell')
                        && ! cell.classList.contains('fi-ta-selection-cell')
                        && ! cell.classList.contains('fi-ta-actions-cell');

                    cell.toggleAttribute('data-box-empty', plain && cell !== title && blank(cell));

                    if (cell.classList.contains('fi-ta-actions-cell')) {
                        const visible = [...cell.querySelectorAll('.fi-ta-actions > *')].filter(shown);
                        const menuOnly = visible.length === 1 && visible[0].classList.contains('fi-dropdown');

                        cell.toggleAttribute('data-box-menu-only', menuOnly);
                    }
                });
            });
        };

        const zero = () => document.querySelectorAll('.fi-ta-header-toolbar .fi-icon-btn-badge-ctn')
            .forEach((badge) => badge.toggleAttribute('data-box-zero', badge.innerText.trim() === '0'));

        const run = () => {
            document.querySelectorAll('table.fi-ta-table').forEach(label);
            zero();
        };

        document.addEventListener('DOMContentLoaded', run);
        document.addEventListener('livewire:navigated', run);
        document.addEventListener('livewire:init', () => {
            window.Livewire.hook('morph.updated', () => requestAnimationFrame(run));
        });
    })();
</script>
