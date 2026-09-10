{{--
    How much of the money is taken at booking.

    A form and a save button. Nothing here is a hardcoded string —
    `NoHardcodedStringsTest` scans this directory (I18N-1).
--}}
<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex justify-end">
            <x-filament::button type="submit">
                {{ __('payment_settings.actions.save') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
