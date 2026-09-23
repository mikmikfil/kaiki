{{--
    Row actions big enough to hit with a thumb (OPS-22, WCAG 2.5.8).

    #129's run at 390px measured the departures row actions — «Κατάσταση
    επιβατών», «Επεξεργασία» — at **20px tall**. They are Filament links, so
    their height is their line box and nothing else; on a desktop that is fine
    because a mouse pointer is one pixel, and on a boat it is the difference
    between opening the manifest and opening the row above it.

    WCAG 2.5.8 (AA) asks for 24×24 CSS pixels. Apple asks for 44pt and Android
    for 48dp, and those are the numbers written by people watching somebody use
    a phone one-handed on a moving deck, which is this product's actual case. So
    44px, which clears the standard rather than meeting it exactly.

    **Below `lg` only.** The same rule at desktop width would push every table
    row 24px taller for no one's benefit — a list of thirty departures is easier
    to scan when the rows are close together, and a mouse does not need the
    room.

    Injected from a render hook rather than a compiled Filament theme. A theme
    means a second Vite entry point and its own build step for the panel, which
    is a large change to the asset pipeline to carry six declarations; if the
    panel ever grows a theme, this moves into it unchanged.
--}}
<style>
    @media (max-width: 1023.98px) {
        .fi-ta-actions .fi-link,
        .fi-ta-actions .fi-icon-btn {
            min-block-size: 2.75rem;
            min-inline-size: 2.75rem;
            justify-content: center;
        }

        /*
         * Everything else a thumb reaches for (phone audit, 2026-09-23) — the
         * checkboxes of a table, the avatar that opens the user menu, the
         * filter and column buttons over a table, the icons on a repeater row,
         * the extra-small buttons. Measured at 16–36px. (The EL/EN switch
         * carries its own, in `locale-switcher`, which is only on the page
         * when it is.)
         *
         * The target grows, the drawing does not: an invisible `::after`
         * laid over the control's surroundings, so a toolbar or a repeater
         * header keeps its spacing and nothing on the page moves. Each of
         * these is `position: relative` already or is made so here.
         */
        .fi-ta-table .fi-ta-selection-cell label,
        .fi-ta-table .fi-ta-page-checkbox-cell label,
        .fi-ta-table th label:has(.fi-checkbox-input),
        .fi-user-menu .fi-dropdown-trigger > button,
        .fi-ta-header-toolbar .fi-icon-btn,
        .fi-fo-repeater-item-header .fi-icon-btn,
        .fi-fo-builder-item-header .fi-icon-btn,
        .fi-btn.fi-size-xs,
        .fi-btn.fi-btn-size-xs {
            position: relative;
        }

        .fi-ta-table .fi-ta-selection-cell label::after,
        .fi-ta-table th label:has(.fi-checkbox-input)::after {
            content: '';
            position: absolute;
            inset: -13px;
        }

        .fi-user-menu .fi-dropdown-trigger > button::after {
            content: '';
            position: absolute;
            inset: -6px;
        }

        .fi-ta-header-toolbar .fi-icon-btn::after,
        .fi-fo-repeater-item-header .fi-icon-btn::after,
        .fi-fo-builder-item-header .fi-icon-btn::after {
            content: '';
            position: absolute;
            inset: -6px;
        }

        .fi-btn.fi-size-xs::after,
        .fi-btn.fi-btn-size-xs::after {
            content: '';
            position: absolute;
            inset: -8px -2px;
        }
    }

    /*
     * A file field on a touch screen says «Επιλέξτε αρχείο», not «Σύρετε τα
     * αρχεία σας ή Αναζήτηση»: there is nothing to drag from on a phone. The
     * whole box stays the button it already is; only the words change.
     */
    @media (hover: none) and (pointer: coarse) {
        .filepond--drop-label label {
            font-size: 0 !important;
        }

        .filepond--drop-label label::after {
            {{-- Not @js(): that escapes Greek as \u03.., which CSS reads as garbage. --}}
            content: {!! json_encode(__('panel.upload_pick'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
            font-size: .875rem;
            font-weight: 600;
            color: rgb(var(--primary-600));
        }

        .dark .filepond--drop-label label::after {
            color: rgb(var(--primary-400));
        }
    }
</style>
