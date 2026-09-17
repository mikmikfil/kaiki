{{--
    The Google review request settings (2026-09-17).

    A form and its save button. Nothing here is a hardcoded string —
    `NoHardcodedStringsTest` scans this directory (I18N-1).
--}}
<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex items-center justify-end gap-4">
            <x-filament::button type="submit">
                {{ __('review_settings.actions.save') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
