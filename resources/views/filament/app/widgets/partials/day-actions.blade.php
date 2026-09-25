{{--
    The home page's two action tiles, «Σάρωση εισιτηρίων» and «Πώληση τώρα»
    (Mike, 2026-09-25, direction Α of docs/mockups/dashboard-quick-actions.html).
    Under the next departure's card for the office, above it for crew. White,
    a navy icon square, the name and one line; one tile alone takes the row.

    Styled by `day-by-boat.blade.php`, which includes it.
--}}
@if ($actions !== [])
    <div @class(['kd-acts', 'is-one' => count($actions) === 1])>
        @foreach ($actions as $action)
            <a class="kd-act" data-action="{{ $action['key'] }}" href="{{ $action['url'] }}">
                <span class="kd-act-ic">
                    <x-filament::icon :icon="$action['icon']" class="kd-act-svg" />
                </span>
                <span class="kd-act-text">
                    <b>{{ $action['label'] }}</b>
                    <small>{{ $action['hint'] }}</small>
                </span>
                <x-filament::icon icon="heroicon-m-arrow-right" class="kd-act-arr" />
            </a>
        @endforeach
    </div>
@endif
