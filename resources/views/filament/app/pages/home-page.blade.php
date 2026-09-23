{{--
    The home-page editor (#102).

    A form and nothing else. The preview an operator wants is the page itself,
    which is one link away and is the real thing rather than an approximation
    that can disagree with it.

    Nothing here is a hardcoded string — `NoHardcodedStringsTest` scans this
    directory (I18N-1).
--}}
<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        {{-- The save bar stays on screen (2026-09-23): on a phone the form is
             long even with its sections closed, and a change made at the top
             had its button a dozen screens below it. Sticky rather than fixed,
             so it rests under the form on a short page, and above the phone's
             home bar by the safe-area inset. --}}
        <div
            class="sticky bottom-0 z-10 mt-6 flex items-center justify-between gap-4 rounded-xl bg-white/95 px-4 pt-3 shadow-lg ring-1 ring-gray-950/5 backdrop-blur dark:bg-gray-900/95 dark:ring-white/10"
            style="padding-bottom: calc(.75rem + env(safe-area-inset-bottom)); margin-bottom: .5rem;"
        >
            <a
                href="{{ $this->publicUrl() }}"
                target="_blank"
                rel="noopener noreferrer"
                class="text-sm font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400"
            >
                {{ __('home_page.actions.view') }}
            </a>

            <x-filament::button type="submit">
                {{ __('home_page.actions.save') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
