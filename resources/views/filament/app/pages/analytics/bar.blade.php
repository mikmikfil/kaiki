{{--
    One row's share, as a bar (2026-09-22).

    A table of figures answers «how much», and only after the reader has
    compared two lines in their head. The bar answers «which is the big one»
    before they have read a digit — which is the question somebody opens a
    report with.

    ## Inline styles, not Tailwind classes

    The panel's stylesheet is built and shipped inside Filament, so a utility
    this project is the first to use is simply not in it — the chart above this
    was painted black for exactly that reason, and a `bg-gray-100` that does not
    exist is a track nobody can see. Filament's own custom properties are
    available, so the colours still follow the operator's palette.

    Expects: $ratio (float 0–1, may be null).
--}}
@php
    $width = $ratio === null ? 0.0 : max(0.0, min(1.0, (float) $ratio));
@endphp

<span style="display: block; margin-top: .35rem; height: 3px; border-radius: 999px; background: rgb(var(--ka-track, var(--gray-200))); overflow: hidden;">
    <span style="display: block; height: 3px; width: {{ round($width * 100, 1) }}%; border-radius: 999px; background: rgb(var(--primary-600));"></span>
</span>
