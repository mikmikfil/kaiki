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
    }
</style>
