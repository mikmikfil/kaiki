{{--
    The EL / EN switcher (I18N-1, I18N-5).

    Rendered into the panel topbar and, separately, onto the login page — an
    operator staring at a sign-in form in a language they do not read is exactly
    the person who cannot reach a control hidden behind a user menu.

    **Inline SVG flags rather than emoji.** Windows does not render regional
    indicator pairs: `🇬🇷` shows as the letters "GR" in Chrome on Windows, which
    is what most of these operators use. Two hand-written SVGs are about a
    kilobyte, need no font, no network and no build step, and look the same on
    every platform.

    **Scoped `<style>` rather than utility classes.** Filament ships a
    precompiled stylesheet built from its own views, so a Tailwind class it does
    not already use simply does not exist at runtime — the component would
    render unstyled and nobody would find out until it was on screen. A scoped
    block under `.kaiki-locale-switcher` is self-contained and carries its own
    dark-mode rules off the `.dark` class Filament puts on `<html>`.
--}}
@php
    /** @var list<array{code: string, label: string, short: string, url: string, current: bool}> $locales */
    /** @var bool $alignEnd — true on the login page, which has no topbar to sit in. */
@endphp

@if (count($locales) > 1)
    <style>
        .kaiki-locale-switcher {
            display: inline-flex;
            align-items: center;
            gap: 0.125rem;
            padding: 0.125rem;
            border-radius: 0.5rem;
            background-color: rgb(244 244 245);
        }

        .dark .kaiki-locale-switcher {
            background-color: rgb(255 255 255 / 0.05);
        }

        .kaiki-locale-switcher__option {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 600;
            line-height: 1;
            color: rgb(82 82 91);
            text-decoration: none;
            transition: background-color 75ms, color 75ms;
        }

        .dark .kaiki-locale-switcher__option {
            color: rgb(161 161 170);
        }

        .kaiki-locale-switcher__option:hover {
            color: rgb(24 24 27);
            background-color: rgb(255 255 255 / 0.7);
        }

        .dark .kaiki-locale-switcher__option:hover {
            color: rgb(250 250 250);
            background-color: rgb(255 255 255 / 0.05);
        }

        .kaiki-locale-switcher__option[aria-current='true'] {
            color: rgb(24 24 27);
            background-color: rgb(255 255 255);
            box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05);
        }

        .dark .kaiki-locale-switcher__option[aria-current='true'] {
            color: rgb(250 250 250);
            background-color: rgb(255 255 255 / 0.1);
            box-shadow: none;
        }

        /* Focus must stay visible for keyboard users (A11Y-1). */
        .kaiki-locale-switcher__option:focus-visible {
            outline: 2px solid rgb(59 130 246);
            outline-offset: 1px;
        }

        .kaiki-locale-switcher__flag {
            display: block;
            width: 1rem;
            height: auto;
            border-radius: 1px;
        }
    </style>

    <div @style(['display: flex; justify-content: flex-end; margin-bottom: 1rem' => $alignEnd])>
    <nav class="kaiki-locale-switcher" aria-label="{{ __('panel.locale.switcher') }}">
        @foreach ($locales as $locale)
            <a
                class="kaiki-locale-switcher__option"
                href="{{ $locale['url'] }}"
                hreflang="{{ $locale['code'] }}"
                lang="{{ $locale['code'] }}"
                aria-current="{{ $locale['current'] ? 'true' : 'false' }}"
                title="{{ $locale['label'] }}"
            >
                @include('filament.flags.' . $locale['code'])

                {{-- The two-letter code, in its own alphabet: ΕΛ and EN. --}}
                <span aria-hidden="true">{{ $locale['short'] }}</span>
                <span class="fi-sr-only sr-only">{{ $locale['label'] }}</span>
            </a>
        @endforeach
    </nav>
    </div>
@endif
