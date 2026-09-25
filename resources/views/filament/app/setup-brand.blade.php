{{--
    The Kaiki mark above the setup guide (product owner, 2026-09-22).

    The guide runs without the panel's navigation, so this is the only thing on
    the screen that says whose product it is — and it is the first screen a new
    operator ever sees. The panel's own brand: the uploaded platform logo when
    there is one, the name when there is not.
--}}
<div class="ka-setup-brand">
    <x-filament-panels::logo />
</div>

<style>
    .ka-setup-brand {
        display: flex;
        align-items: center;
        margin-bottom: .75rem;
    }
</style>
