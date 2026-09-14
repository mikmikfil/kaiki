{{--
    Kaiki's own logo and colours, on `/admin`.

    The contrast note sits above the form rather than beside either colour
    field, for the same reason the operator's screen puts it there: it is a
    judgement about a *pair*, and marking one of the two red would point at the
    wrong one half the time.

    It appears only when it is true. A permanent green "contrast is fine" badge
    is a thing people stop reading, and then stop seeing on the day it turns
    red.

    Nothing here is a hardcoded string — `NoHardcodedStringsTest` scans this
    directory (I18N-1).
--}}
<x-filament-panels::page>
    @php($warning = $this->contrastWarning())

    @if ($warning)
        <section
            class="fi-section rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10"
            aria-labelledby="platform-branding-contrast-heading"
        >
            <h2
                id="platform-branding-contrast-heading"
                class="text-base font-semibold leading-6 text-gray-950 dark:text-white"
            >
                {{ __('platform_branding.contrast.heading') }}
            </h2>

            <p class="mt-2 text-sm text-warning-600 dark:text-warning-400">
                {{ $warning }}
            </p>
        </section>
    @endif

    <form wire:submit="save">
        {{ $this->form }}
    </form>
</x-filament-panels::page>
