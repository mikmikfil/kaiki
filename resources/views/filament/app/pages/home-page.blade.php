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

        <div class="mt-6 flex items-center justify-between gap-4">
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
