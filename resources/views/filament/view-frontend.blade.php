{{--
    "View your page" — a way out to the guest's side of the product.

    Rendered at the top of the `/app` sidebar, which is where an operator's eye
    already is and the first place they look for the thing they are editing.

    **It is absent rather than dead when there is nothing to see.** HOS-6 lets an
    operator switch their hosted pages off, and the page is a 404 then; a button
    leading to a "not found" teaches an operator the feature is broken rather
    than switched off. `$url` is null in that case and nothing renders.

    **A scoped `<style>` rather than utility classes**, the same reasoning the
    locale switcher records: Filament ships a precompiled stylesheet built from
    its own views, so a Tailwind class it does not already use does not exist at
    runtime and the component renders unstyled.

    Nothing here is a hardcoded string (I18N-1).
--}}
@if ($url !== null)
    <a
        href="{{ $url }}"
        target="_blank"
        rel="noopener"
        class="kaiki-view-frontend"
        title="{{ __('panel.view_frontend.title') }}"
    >
        {{-- Inline, because an icon component Filament has not compiled is an
             icon that does not appear. --}}
        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
            <path d="M10 4.5c-3.2 0-5.9 2-7 5.5 1.1 3.5 3.8 5.5 7 5.5s5.9-2 7-5.5c-1.1-3.5-3.8-5.5-7-5.5Z" />
            <circle cx="10" cy="10" r="2.2" />
        </svg>

        <span>{{ __('panel.view_frontend.label') }}</span>
    </a>

    <style>
        .kaiki-view-frontend {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin: 0 0.5rem 0.5rem;
            padding: 0.5rem 0.75rem;
            border-radius: 0.5rem;
            border: 1px solid rgb(228 228 231);
            font-size: 0.8125rem;
            font-weight: 600;
            color: rgb(63 63 70);
            text-decoration: none;
        }

        .kaiki-view-frontend:hover {
            background: rgb(244 244 245);
            color: rgb(24 24 27);
        }

        .kaiki-view-frontend svg {
            width: 1.05rem;
            height: 1.05rem;
            flex: none;
        }

        .dark .kaiki-view-frontend {
            border-color: rgb(63 63 70);
            color: rgb(212 212 216);
        }

        .dark .kaiki-view-frontend:hover {
            background: rgb(39 39 42);
            color: rgb(250 250 250);
        }
    </style>
@endif
