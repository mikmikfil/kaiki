{{--
    A deliberately wrong Blade file, so the i18n lint has something to catch on
    every run rather than only when a human remembers to sabotage the tree.

    Never rendered. Outside every path in `scannedPaths()`.
--}}
<div class="flex items-center gap-2">
    {{-- Flagged: a sentence echoed straight out of Blade. --}}
    {{ 'Save this trip' }}

    {{-- Not flagged: already translated. --}}
    {{ __('api_keys.reveal.copy') }}
    {{ trans('panel.groups.settings') }}

    {{-- Not flagged: a variable, whatever it holds. --}}
    {{ $vessel->name }}

    {{-- Not flagged: an identifier rather than prose. --}}
    <x-thing wire:key="{{ 'vessel-row' }}" />
</div>
