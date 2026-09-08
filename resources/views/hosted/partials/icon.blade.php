{{--
    One small line icon.

    Inline SVG rather than a font or a sprite file: an icon font is a download
    that renders as a box until it arrives, and a sprite is a second request for
    six hundred bytes. These are drawn with `currentColor`, so they take the
    colour of whatever they sit in — including the operator's own palette —
    without a stylesheet knowing they exist.

    `aria-hidden`, always. Every one of them sits beside a label that says the
    same thing, and an icon announced as well makes a screen reader read the row
    twice.

    @param string $name One of the cases below. An unknown name draws nothing,
                        which is the right failure: a missing icon is a missing
                        icon, not a broken page.
--}}
@php
    $paths = [
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><path d="M20 8v6M23 11h-6"/>',
        'boat' => '<path d="M3 18h18l-2-6H5l-2 6Z"/><path d="M12 12V4"/><path d="M12 4l6 4"/><path d="M5 21h14"/>',
        'tickets' => '<path d="M3 9a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2 2 2 0 0 0 0 4 2 2 0 0 1-2 2H5a2 2 0 0 1-2-2 2 2 0 0 0 0-4Z"/><path d="M9 7v10"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/><path d="m9 16 2 2 4-4"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
        'pin' => '<path d="M12 21s7-5.5 7-11a7 7 0 1 0-14 0c0 5.5 7 11 7 11Z"/><circle cx="12" cy="10" r="2.6"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.6-3.6"/>',
        'date' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/>',
        'type' => '<path d="M12 3 3 8v8l9 5 9-5V8l-9-5Z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
    ];
    $path = $paths[$name] ?? null;
@endphp
@if ($path)
    <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">{!! $path !!}</svg>
@endif
