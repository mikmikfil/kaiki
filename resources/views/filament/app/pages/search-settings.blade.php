{{--
    The search-filter toggles (#105).

    A form and a link to the page they shape. Nothing here is a hardcoded string
    — `NoHardcodedStringsTest` scans this directory (I18N-1).
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
                {{ __('search_settings.actions.view') }}
            </a>

            <x-filament::button type="submit">
                {{ __('search_settings.actions.save') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
