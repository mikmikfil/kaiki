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

    No hardcoded strings — `NoHardcodedStringsTest` scans this directory.
--}}
<style>
    @media (max-width: 767.98px) {
        .fi-ta-content { overflow: visible !important; }

        .fi-ta-table,
        .fi-ta-table > tbody { display: block; width: 100%; }

        .fi-ta-table > thead { display: none; }

        .fi-ta-table > tbody { padding: .6rem; background: transparent; border: 0 !important; }

        .fi-ta-table > tbody > tr.fi-ta-row {
            position: relative;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: .35rem .75rem;
            margin-block-end: .6rem;
            padding: .85rem .9rem;
            border: 1px solid #E1E8F2 !important;
            border-radius: 1rem;
            background: #fff;
        }

        .dark .fi-ta-table > tbody > tr.fi-ta-row { border-color: rgba(255, 255, 255, .1) !important; background: rgb(var(--gray-900)); }

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

        /* The checkbox, top right, out of the way of the title. */
        .fi-ta-table > tbody > tr.fi-ta-row > td.fi-ta-selection-cell {
            grid-column: 2;
            grid-row: 1;
            align-self: start;
        }

        /* Its desktop padding made the first row of the box twice as tall. */
        .fi-ta-table > tbody > tr.fi-ta-row > td.fi-ta-selection-cell * { padding: 0 !important; margin: 0 !important; }

        /* The first value is the box's title, with no label of its own. The
           script marks it, because which cell comes first depends on whether
           the table has checkboxes. */
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
            margin-block-end: .05rem;
        }

        /* A value's own layout stays compact inside the box. */
        .fi-ta-table > tbody > tr.fi-ta-row > td[data-label]:not([data-box-title]) {
            display: grid;
            grid-template-columns: 7.5rem minmax(0, 1fr);
            align-items: baseline;
            gap: .5rem;
        }

        .fi-ta-table > tbody > tr.fi-ta-row > td[data-label]:not([data-box-title])::before { margin: 0; }

        /* The boxes are the surface; the card Filament draws round the whole
           table would be a box of boxes. */
        .fi-ta-ctn { background: transparent !important; box-shadow: none !important; --tw-ring-color: transparent !important; }
        .fi-ta-header-toolbar { padding-inline: .6rem !important; }

        /* Empty cells take no room. */
        .fi-ta-table > tbody > tr.fi-ta-row > td:not(.fi-ta-actions-cell):not(.fi-ta-selection-cell):empty { display: none; }

        /* Actions along the bottom of the box, big enough for a thumb. */
        .fi-ta-table > tbody > tr.fi-ta-row > td.fi-ta-actions-cell {
            margin-block-start: .35rem;
            padding-block-start: .35rem !important;
            border-block-start: 1px solid #EEF3F9 !important;
        }

        .fi-ta-table > tbody > tr.fi-ta-row > td.fi-ta-actions-cell .fi-ta-actions { justify-content: flex-start; flex-wrap: wrap; gap: .25rem 1rem; padding: 0 !important; }
    }
</style>

<script>
    (function () {
        const label = (table) => {
            const heads = [...table.querySelectorAll(':scope > thead > tr > th')].map((th) => th.innerText.trim());

            table.querySelectorAll(':scope > tbody > tr.fi-ta-row').forEach((row) => {
                const title = [...row.children].find((cell) => cell.classList.contains('fi-ta-cell')
                    && ! cell.classList.contains('fi-ta-selection-cell')
                    && ! cell.classList.contains('fi-ta-actions-cell'));

                [...row.children].forEach((cell) => cell.toggleAttribute('data-box-title', cell === title));

                [...row.children].forEach((cell, index) => {
                    // The checkbox and actions columns carry screen-reader
                    // text in their headers, not a name to print.
                    const unnamed = cell.classList.contains('fi-ta-selection-cell') || cell.classList.contains('fi-ta-actions-cell');
                    const text = unnamed ? '' : (heads[index] || '');

                    if (text !== '') {
                        cell.setAttribute('data-label', text);
                    } else {
                        cell.removeAttribute('data-label');
                    }
                });
            });
        };

        const run = () => document.querySelectorAll('table.fi-ta-table').forEach(label);

        document.addEventListener('DOMContentLoaded', run);
        document.addEventListener('livewire:navigated', run);
        document.addEventListener('livewire:init', () => {
            window.Livewire.hook('morph.updated', () => requestAnimationFrame(run));
        });
    })();
</script>
