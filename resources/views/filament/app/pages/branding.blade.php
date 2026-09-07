{{--
    The branding editor (BRD-1 … BRD-7).

    The contrast badge sits **above** the form rather than beside the colour
    fields, because BRD-5 is a judgement about a pair of colours and neither
    field owns it: marking the text colour red would tell an operator to change
    the wrong one half the time.

    Nothing here is a hardcoded string — `NoHardcodedStringsTest` scans this
    directory (I18N-1).
--}}
<x-filament-panels::page>
    @php($results = $this->contrastResults())

    <section
        class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
        aria-labelledby="branding-contrast-heading"
    >
        <h2
            id="branding-contrast-heading"
            class="text-base font-semibold leading-6 text-gray-950 dark:text-white"
        >
            {{ __('branding.contrast.heading') }}
        </h2>

        @if (count($results) === 0)
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                {{ __('branding.contrast.unchecked') }}
            </p>
        @else
            <dl class="mt-4 space-y-3">
                @foreach ($results as $result)
                    <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                        <dt class="text-sm text-gray-700 dark:text-gray-300">{{ $result['label'] }}</dt>

                        <dd>
                            {{-- The ratio is in the badge text, not only in its
                                 colour: a contrast warning that can only be read
                                 by seeing the difference between green and amber
                                 is the one warning that must not be. --}}
                            <x-filament::badge :color="$result['passes'] ? 'success' : 'warning'">
                                {{ $result['message'] }}
                            </x-filament::badge>
                        </dd>
                    </div>
                @endforeach
            </dl>

            @if (collect($results)->contains(fn (array $result): bool => ! $result['passes']))
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                    {{ __('branding.contrast.warning') }}
                </p>
            @endif
        @endif
    </section>

    {{-- BRD-4, below the contrast badge and above the form: an operator scrolls
         down to a field, changes it, and looks back up. --}}
    @include('filament.app.pages.branding-preview')

    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex justify-end">
            <x-filament::button type="submit">
                {{ __('branding.actions.save') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
