{{--
    "Your page" — a way out to the guest's side of the product.

    Rendered at the top of the `/app` sidebar, which is where an operator's eye
    already is and the first place they look for the thing they are editing.

    **A small card, not a grey button** (product owner, 2026-09-17: the old
    «Δείτε τη σελίδα σας» button looked out of place). It says what it is — the
    page, live, at this address — rather than issuing an instruction: a green
    dot for "people can see this now", the address itself so the operator
    recognises it and can read it out to a customer, and an arrow that says it
    opens elsewhere.

    **Two destinations, one card.** With a home page it is «Η σελίδα σας» and
    leads there; without one (*bookings only*) it is «Οι εκδρομές σας» and leads
    to the search page, because `/{operator}` is a 404 for them (2026-09-25).
    The provider picks `$url`, `$label` and `$title`; `$url` is null only for
    the crew and outside a tenant, and nothing renders then.

    **A scoped `<style>` rather than utility classes**, the same reasoning the
    locale switcher records: Filament ships a precompiled stylesheet built from
    its own views, so a Tailwind class it does not already use does not exist at
    runtime and the component renders unstyled. The selectors carry the anchor
    element so they win over the older rules for this class in `sea.blade.php`.

    Nothing here is a hardcoded string (I18N-1).
--}}
@if ($url !== null)
    @php
        $address = preg_replace('#^https?://#', '', rtrim($url, '/'));
    @endphp

    <a
        href="{{ $url }}"
        target="_blank"
        rel="noopener"
        class="kaiki-view-frontend"
        title="{{ $title }}"
    >
        <span class="kaiki-view-frontend-icon" aria-hidden="true">
            {{-- Inline, because an icon component Filament has not compiled is
                 an icon that does not appear. A globe: a page on the web. --}}
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5">
                <circle cx="10" cy="10" r="7" />
                <path d="M3 10h14M10 3c2 2.2 3 4.5 3 7s-1 4.8-3 7c-2-2.2-3-4.5-3-7s1-4.8 3-7Z" />
            </svg>
        </span>

        <span class="kaiki-view-frontend-text">
            <span class="kaiki-view-frontend-label">
                {{ $label }}
                <span class="kaiki-view-frontend-live" role="img" aria-label="{{ __('panel.view_frontend.live') }}"></span>
            </span>
            <span class="kaiki-view-frontend-address">{{ $address }}</span>
        </span>

        <svg class="kaiki-view-frontend-arrow" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path d="M7 13 13 7M8 7h5v5" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
    </a>

    <style>
        .fi-sidebar a.kaiki-view-frontend,
        a.kaiki-view-frontend {
            display: flex;
            align-items: center;
            gap: .7rem;
            /* Edge to edge with the menu rows below it, which Filament pulls
               out past the nav's padding by the same half rem (2026-09-17). */
            margin: .25rem -.5rem .75rem -.5rem;
            padding: .6rem .7rem .6rem .5rem;
            border-radius: .75rem;
            border: 1px solid rgba(255, 255, 255, .12);
            background: rgba(255, 255, 255, .05);
            color: #fff;
            text-decoration: none;
            transition: background .2s ease, border-color .2s ease;
        }

        .fi-sidebar a.kaiki-view-frontend:hover,
        .fi-sidebar a.kaiki-view-frontend:focus-visible {
            background: rgba(255, 255, 255, .1);
            border-color: rgba(255, 255, 255, .22);
            color: #fff;
        }

        a.kaiki-view-frontend .kaiki-view-frontend-icon {
            display: grid;
            place-items: center;
            flex: none;
            inline-size: 2.1rem;
            block-size: 2.1rem;
            border-radius: .6rem;
            background: rgba(255, 255, 255, .1);
            color: #DCE7F5;
        }

        a.kaiki-view-frontend .kaiki-view-frontend-icon svg {
            inline-size: 1.15rem;
            block-size: 1.15rem;
        }

        a.kaiki-view-frontend .kaiki-view-frontend-text {
            display: grid;
            min-inline-size: 0;
            flex: 1;
            line-height: 1.25;
        }

        a.kaiki-view-frontend .kaiki-view-frontend-label {
            display: flex;
            align-items: center;
            gap: .4rem;
            font-size: .875rem;
            font-weight: 600;
        }

        a.kaiki-view-frontend .kaiki-view-frontend-live {
            inline-size: .45rem;
            block-size: .45rem;
            border-radius: 50%;
            background: #4ADE80;
            box-shadow: 0 0 0 3px rgba(74, 222, 128, .18);
        }

        a.kaiki-view-frontend .kaiki-view-frontend-address {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: .75rem;
            color: #9FB6D6;
        }

        a.kaiki-view-frontend .kaiki-view-frontend-arrow {
            flex: none;
            inline-size: 1rem;
            block-size: 1rem;
            color: #9FB6D6;
            transition: transform .2s ease, color .2s ease;
        }

        a.kaiki-view-frontend:hover .kaiki-view-frontend-arrow {
            transform: translate(2px, -2px);
            color: #fff;
        }
    </style>
@endif
