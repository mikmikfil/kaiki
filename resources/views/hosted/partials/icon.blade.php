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

    ## Drawn on a 24 grid, and drawn properly

    The first set of these was sketched in a hurry and looked it. The "people"
    mark was one figure with a plus beside it — which everywhere else on the
    internet means *add a user*, sitting next to the words "up to twelve people".
    The "boat" was a triangle on a stick that could as easily have been a road
    sign, and the "kind of trip" was a wireframe cube, which is a package, a
    database or a 3-D model depending on where you last saw one.

    An icon that has to be decoded is worse than no icon, because the eye stops
    on it. So: shapes that fill the box rather than floating in the middle of it,
    one stroke weight throughout, and nothing that already means something else.

    @param string $name One of the cases below. An unknown name draws nothing,
                        which is the right failure: a missing icon is a missing
                        icon, not a broken page.
--}}
@php
    $paths = [
        // Two figures, because the words beside it are always a number of people.
        'users' => '<circle cx="9.4" cy="8.2" r="3.5"/>'
            .'<path d="M3.2 20a6.2 6.2 0 0 1 12.4 0"/>'
            .'<path d="M16.6 5.1a3.3 3.3 0 0 1 0 6.2"/>'
            .'<path d="M18 14.6a5.4 5.4 0 0 1 3 4.9"/>',

        // A hull, a mast and a sail, in that order of weight. Read at fifteen
        // pixels it is a boat rather than a pennant.
        'boat' => '<path d="M3.6 15.4h16.8l-2 3.7a2 2 0 0 1-1.7 1H7.3a2 2 0 0 1-1.8-1l-1.9-3.7Z"/>'
            .'<path d="M12 15.4V3.3"/>'
            .'<path d="M13.4 4.4 19 13.2h-5.6"/>',

        // A ticket with its stub, for who pays what.
        'tickets' => '<path d="M3.6 9.2V7a1.4 1.4 0 0 1 1.4-1.4h14A1.4 1.4 0 0 1 20.4 7v2.2a2.8 2.8 0 0 0 0 5.6V17a1.4 1.4 0 0 1-1.4 1.4H5A1.4 1.4 0 0 1 3.6 17v-2.2a2.8 2.8 0 0 0 0-5.6Z"/>'
            .'<path d="M14.4 5.6v2.1M14.4 10.9v2.2M14.4 16.3v2.1"/>',

        'check' => '<path d="M20 6.4 9.2 17.6 4 12.4"/>',

        // The calendar with a tick — a date that is settled.
        'calendar' => '<rect x="3.2" y="5" width="17.6" height="15.8" rx="2.6"/>'
            .'<path d="M16 3.2v3.6M8 3.2v3.6M3.2 10.6h17.6"/>'
            .'<path d="m9.1 15.5 2 2 3.8-3.8"/>',

        // …and the plain one, for a date field.
        'date' => '<rect x="3.2" y="5" width="17.6" height="15.8" rx="2.6"/>'
            .'<path d="M16 3.2v3.6M8 3.2v3.6M3.2 10.6h17.6"/>',

        'clock' => '<circle cx="12" cy="12" r="8.6"/><path d="M12 6.9v5.4l3.6 2.1"/>',

        // A map marker with a point in it, so it reads as a place rather than as
        // a balloon.
        'pin' => '<path d="M12 21.2c4.1-4.6 6.2-8.1 6.2-10.6a6.2 6.2 0 1 0-12.4 0c0 2.5 2.1 6 6.2 10.6Z"/>'
            .'<circle cx="12" cy="10.6" r="2.3"/>',

        // A tag, for the kind of trip.
        'type' => '<path d="M11.4 3.6H19a1.4 1.4 0 0 1 1.4 1.4v7.6a1.4 1.4 0 0 1-.4 1l-7.4 7.4a1.4 1.4 0 0 1-2 0l-7.6-7.6a1.4 1.4 0 0 1 0-2l7.4-7.4a1.4 1.4 0 0 1 1-.4Z"/>'
            .'<circle cx="16" cy="8" r="1.5"/>',

        // A headset with its boom, for "ask us". A handset would have done,
        // but the phone icon is already on this site meaning "the phone number
        // is here" — the same mark twice for two different offers is how a
        // visitor learns to stop reading icons.
        'support' => '<path d="M4.4 14.6v-2.8a7.6 7.6 0 0 1 15.2 0v2.8"/>'
            .'<rect x="2.4" y="13.4" width="3.6" height="5.8" rx="1.8"/>'
            .'<rect x="18" y="13.4" width="3.6" height="5.8" rx="1.8"/>'
            .'<path d="M19.8 19.2a3.2 3.2 0 0 1-3.2 2.6h-2.2"/>',

        // A handset, and an envelope. Both sit beside a value that already
        // says what it is, so neither has to be decoded — they exist to let the
        // eye find the phone number without reading the email address first.
        'phone' => '<path d="M8.4 4.2 10 7.4a1.4 1.4 0 0 1-.3 1.6l-1.1 1a12.6 12.6 0 0 0 4.4 4.4l1-1.1a1.4 1.4 0 0 1 1.6-.3l3.2 1.6a1.4 1.4 0 0 1 .7 1.6 3.9 3.9 0 0 1-4.2 2.9A14.6 14.6 0 0 1 4.1 6.4 3.9 3.9 0 0 1 7 2.2a1.4 1.4 0 0 1 1.4.7Z"/>',

        'mail' => '<rect x="2.8" y="5.2" width="18.4" height="13.6" rx="2.6"/>'
            .'<path d="m3.6 7 7.5 5.3a1.6 1.6 0 0 0 1.8 0L20.4 7"/>',

        // --- solid variants, for the contact panel ----------------------
        //
        // Filled rather than the stroked versions above, and drawn separately
        // rather than switched with `fill: currentColor`: filling an outline
        // does not make a solid icon, it makes a blob — the envelope's flap is
        // an open line and would vanish into the block behind it, and the pin's
        // inner circle would fill in and stop being a hole.
        //
        // Each is one closed silhouette. The pin keeps its hole through
        // `fill-rule: evenodd` on the two subpaths, which is why it is a single
        // `path` rather than a path and a circle.
        'phone-solid' => '<path d="M8.4 4.2 10 7.4a1.4 1.4 0 0 1-.3 1.6l-1.1 1a12.6 12.6 0 0 0 4.4 4.4l1-1.1a1.4 1.4 0 0 1 1.6-.3l3.2 1.6a1.4 1.4 0 0 1 .7 1.6 3.9 3.9 0 0 1-4.2 2.9A14.6 14.6 0 0 1 4.1 6.4 3.9 3.9 0 0 1 7 2.2a1.4 1.4 0 0 1 1.4.7Z"/>',

        'mail-solid' => '<path d="M2.8 8.3v8.5a2.4 2.4 0 0 0 2.4 2.4h13.6a2.4 2.4 0 0 0 2.4-2.4V8.3l-8.4 5.3a1.6 1.6 0 0 1-1.6 0L2.8 8.3Z"/>'
            .'<path d="M21 6.2a2.4 2.4 0 0 0-2.2-1.4H5.2A2.4 2.4 0 0 0 3 6.2l9 5.7 9-5.7Z"/>',

        'pin-solid' => '<path fill-rule="evenodd" d="M12 21.2c4.1-4.6 6.2-8.1 6.2-10.6a6.2 6.2 0 1 0-12.4 0c0 2.5 2.1 6 6.2 10.6Zm0-8.3a2.3 2.3 0 1 0 0-4.6 2.3 2.3 0 0 0 0 4.6Z"/>',

        'boat-solid' => '<path d="M3.6 15.4h16.8l-2 3.7a2 2 0 0 1-1.7 1H7.3a2 2 0 0 1-1.8-1l-1.9-3.7Z"/>'
            .'<path d="M11.2 3.3h1.6v10.6h-1.6z"/>'
            .'<path d="M13.3 5.1 18.4 13.9h-5.1V5.1Z"/>',

        // --- the social marks, solid ------------------------------------
        //
        // Each is one path whose holes come from `fill-rule: evenodd` on nested
        // subpaths — the lens of the camera, the handset in the bubble, the play
        // triangle. That is why they are single `path` entries with circles
        // written as arcs rather than a `circle` element beside a `rect`: a hole
        // has to be part of the same path as the shape it is a hole in.
        'social-instagram-solid' => '<path fill-rule="evenodd" d="M8.2 3.4h7.6a4.8 4.8 0 0 1 4.8 4.8v7.6a4.8 4.8 0 0 1-4.8 4.8H8.2a4.8 4.8 0 0 1-4.8-4.8V8.2a4.8 4.8 0 0 1 4.8-4.8Zm3.8 4.5a4.1 4.1 0 1 0 0 8.2 4.1 4.1 0 0 0 0-8.2Zm0 2a2.1 2.1 0 1 1 0 4.2 2.1 2.1 0 0 1 0-4.2Zm5.1-4.1a1.1 1.1 0 1 0 0 2.2 1.1 1.1 0 0 0 0-2.2Z"/>',

        'social-facebook-solid' => '<path d="M14.6 21.4v-8.3h2.8l.5-3.3h-3.3V7.7c0-.9.3-1.6 1.7-1.6h1.7V3.2A22 22 0 0 0 15.4 3c-2.6 0-4.3 1.5-4.3 4.3v2.5H8.2v3.3h2.9v8.3h3.5Z"/>',

        'social-whatsapp-solid' => '<path fill-rule="evenodd" d="M12 3a9 9 0 0 0-7.7 13.7l-1.1 3.9 4-1.2A9 9 0 1 0 12 3Zm-2.9 5.3c.2-.5.4-.5.6-.5h.5c.2 0 .4 0 .6.5l.7 1.7c.1.2.1.4 0 .6l-.4.5c-.1.2-.3.3-.1.6a6 6 0 0 0 2.8 2.4c.3.1.5.1.6-.1l.5-.6c.2-.2.3-.2.6-.1l1.6.8c.3.1.4.3.4.5a1.9 1.9 0 0 1-1.3 1.6c-.5.2-1.2.3-3.5-.8a8.4 8.4 0 0 1-3.5-3.6c-.6-1.2-.5-2.1-.4-2.6a2 2 0 0 1 .3-.9Z"/>',

        'social-tiktok-solid' => '<path d="M15.2 3.2c.4 2.3 1.8 3.7 4 3.9v2.6a6.6 6.6 0 0 1-3.9-1.2v5.6a5.6 5.6 0 1 1-4.8-5.5v2.7a2.9 2.9 0 1 0 2.1 2.8V3.2h2.6Z"/>',

        'social-youtube-solid' => '<path fill-rule="evenodd" d="M6.2 5.6h11.6a3.6 3.6 0 0 1 3.6 3.6v5.6a3.6 3.6 0 0 1-3.6 3.6H6.2a3.6 3.6 0 0 1-3.6-3.6V9.2a3.6 3.6 0 0 1 3.6-3.6Zm4.2 3.8v5.2l5-2.6-5-2.6Z"/>',

        'social-x-solid' => '<path d="M3.6 3.6h4.3l5 6.6 5.4-6.6h2.1l-6.5 7.9 7.5 9.5h-4.3l-5.3-7-5.7 7H3.9l6.9-8.4L3.6 3.6Z"/>',

        // The social marks. Drawn on the same 24 grid and the same stroke as
        // everything else here, rather than pasted from each network's brand
        // kit: a row of official logos in their own colours is five brands
        // shouting on somebody else's page, and most of those kits forbid
        // recolouring anyway.
        'social-instagram' => '<rect x="3.4" y="3.4" width="17.2" height="17.2" rx="4.8"/>'
            .'<circle cx="12" cy="12" r="4.1"/>'
            .'<circle cx="17.1" cy="6.9" r="1.1" fill="currentColor" stroke="none"/>',

        'social-facebook' => '<path d="M14.6 21.4v-8.3h2.8l.5-3.3h-3.3V7.7c0-.9.3-1.6 1.7-1.6h1.7V3.2A22 22 0 0 0 15.4 3c-2.6 0-4.3 1.5-4.3 4.3v2.5H8.2v3.3h2.9v8.3"/>',

        'social-whatsapp' => '<path d="M3.6 20.4l1.2-4.2a7.9 7.9 0 1 1 3 2.9l-4.2 1.3Z"/>'
            .'<path d="M9.1 8.3c.2-.5.4-.5.6-.5h.5c.2 0 .4 0 .6.5l.7 1.7c.1.2.1.4 0 .6l-.4.5c-.1.2-.3.3-.1.6a6 6 0 0 0 2.8 2.4c.3.1.5.1.6-.1l.5-.6c.2-.2.3-.2.6-.1l1.6.8c.3.1.4.3.4.5a1.9 1.9 0 0 1-1.3 1.6c-.5.2-1.2.3-3.5-.8a8.4 8.4 0 0 1-3.5-3.6c-.6-1.2-.5-2.1-.4-2.6a2 2 0 0 1 .3-.9Z"/>',

        'social-tiktok' => '<path d="M15.2 3.2c.4 2.3 1.8 3.7 4 3.9v2.6a6.6 6.6 0 0 1-3.9-1.2v5.6a5.6 5.6 0 1 1-4.8-5.5v2.7a2.9 2.9 0 1 0 2.1 2.8V3.2h2.6Z"/>',

        'social-youtube' => '<rect x="2.6" y="5.6" width="18.8" height="12.8" rx="3.6"/>'
            .'<path d="M10.4 9.4 15.4 12l-5 2.6V9.4Z"/>',

        'social-x' => '<path d="M4 4l7.2 9.3L4.4 20"/><path d="M20 20l-7.2-9.3L19.6 4"/>',

        'search' => '<circle cx="10.8" cy="10.8" r="7"/><path d="m20.2 20.2-4.4-4.4"/>',
    ];
    $path = $paths[$name] ?? null;
@endphp
@php
    // A `-solid` name is a silhouette: it paints with `fill` and has no stroke,
    // and the two cannot be mixed on one sprite because a stroked path drawn
    // with a fill closes itself.
    $solid = str_ends_with($name, '-solid');
@endphp

@if ($path)
    @if ($solid)
        <svg class="icon" viewBox="0 0 24 24" fill="currentColor" stroke="none"
             aria-hidden="true" focusable="false">{!! $path !!}</svg>
    @else
        <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">{!! $path !!}</svg>
    @endif
@endif
