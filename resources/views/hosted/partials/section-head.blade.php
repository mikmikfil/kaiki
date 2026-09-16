{{--
    The top of a home-page section: a short line in the operator's accent, the
    heading, and an optional lead paragraph (2026-09-16).

    The eyebrow is a label, not a heading — a `<p>`, so the outline a screen
    reader navigates by is the `<h2>` alone. Its case is whatever the operator
    typed: I18N-2 forbids the property that would change it.

    Expects: $eyebrow (?string), $heading (?string), $lead (?HtmlString from
    `BlockText`), $center (bool, optional). Renders nothing when all three are
    empty.
--}}
@if (($eyebrow ?? null) || ($heading ?? null) || ($lead ?? null))
    <div @class(['section-head', 'is-center' => $center ?? false])>
        @if ($eyebrow ?? null)
            <p class="eyebrow-line">{{ $eyebrow }}</p>
        @endif

        @if ($heading ?? null)
            <h2>{{ $heading }}</h2>
        @endif

        @if ($lead ?? null)
            <div class="lead">{{ $lead }}</div>
        @endif
    </div>
@endif
