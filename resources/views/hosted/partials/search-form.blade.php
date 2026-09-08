{{--
    The search form, wherever it appears.

    Two places: the search page, and the operator's home page under the
    masthead. A partial rather than two copies, because the failure mode of two
    copies is a filter that exists on one page and not the other — and each page
    looks complete on its own, so nobody notices.

    **A plain `GET`.** No JavaScript, no mount, no hydration: the filters submit
    and the server renders the answer. The query string is the state, which is
    also what makes a search shareable — "Saturday, four of us, from Piraeus" is
    a link a guest can send to whoever they are travelling with.

    **Only the filters the operator enabled are drawn**, and that is the second
    half of the rule rather than the whole of it: a disabled filter arriving in
    the query string is already gone by the time this template runs, dropped by
    `SearchFilters`. Hiding it here and honouring it there is the version that
    passes a screenshot review.

    **The icon beside each label is decorative** (`hosted.partials.icon` marks
    every one `aria-hidden`). It is there so the eye can find the field it wants
    without reading six labels — on the home page this form is four controls
    wide and a visitor is scanning it, not reading it.

    @param string $action  Where it submits. The search page submits to itself.
    @param array  $applied What the visitor already chose, on the search page.
    @param string $idPrefix Unique per instance, because two forms on one page
                            would otherwise share `id`s and their labels would
                            point at the wrong controls.
--}}
@php
    use App\Domain\Catalog\Support\SearchFilters;

    $applied = $applied ?? [];
    $prefix = $idPrefix ?? 'f';
    $action = $action ?? null;
    $dateValue = $dateValue ?? now($tenant->timezone ?: 'Europe/Athens')->toDateString();
    $paxValue = $paxValue ?? 2;
@endphp

<form class="search-form" method="get" @if ($action) action="{{ $action }}" @endif>
    <div class="field">
        <label for="{{ $prefix }}-date">@include('hosted.partials.icon', ['name' => 'date']){{ __('hosted.search.fields.date') }}</label>
        <input type="date" id="{{ $prefix }}-date" name="date" value="{{ $dateValue }}">
    </div>

    <div class="field">
        <label for="{{ $prefix }}-pax">@include('hosted.partials.icon', ['name' => 'users']){{ __('hosted.search.fields.pax') }}</label>
        <input type="number" id="{{ $prefix }}-pax" name="pax" min="1" max="500" value="{{ $paxValue }}">
    </div>

    @if ($filters[SearchFilters::PORT] && $ports->isNotEmpty())
        <div class="field">
            <label for="{{ $prefix }}-port">@include('hosted.partials.icon', ['name' => 'pin']){{ __('hosted.search.fields.port') }}</label>
            <select id="{{ $prefix }}-port" name="port">
                <option value="">{{ __('hosted.search.any') }}</option>
                @foreach ($ports as $port)
                    <option value="{{ $port->uuid }}" @selected(($applied['port'] ?? null) === $port->uuid)>{{ $port->name }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if ($filters[SearchFilters::TYPE])
        <div class="field">
            <label for="{{ $prefix }}-type">@include('hosted.partials.icon', ['name' => 'type']){{ __('hosted.search.fields.type') }}</label>
            <select id="{{ $prefix }}-type" name="type">
                <option value="">{{ __('hosted.search.any') }}</option>
                @foreach ($categories as $value => $label)
                    <option value="{{ $value }}" @selected(($applied['type'] ?? null) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
    @endif

    @if ($filters[SearchFilters::DURATION])
        <div class="field">
            <label for="{{ $prefix }}-duration">@include('hosted.partials.icon', ['name' => 'clock']){{ __('hosted.search.fields.duration_max') }}</label>
            <input type="number" id="{{ $prefix }}-duration" name="duration_max" min="30" step="30"
                   value="{{ $applied['duration_max'] ?? '' }}">
        </div>
    @endif

    @if ($filters[SearchFilters::PRICE])
        <div class="field">
            {{-- Euros, because that is what a guest types. The controller turns
                 it into cents, which is what everything touching money works
                 in. --}}
            <label for="{{ $prefix }}-price">{{ __('hosted.search.fields.price_max') }}</label>
            <input type="number" id="{{ $prefix }}-price" name="price_max" min="0" step="10"
                   value="{{ $applied['price_max'] ?? '' }}">
        </div>
    @endif

    @if ($filters[SearchFilters::VESSEL] && $vessels->isNotEmpty())
        <div class="field">
            <label for="{{ $prefix }}-vessel">@include('hosted.partials.icon', ['name' => 'boat']){{ __('hosted.search.fields.vessel') }}</label>
            <select id="{{ $prefix }}-vessel" name="vessel">
                <option value="">{{ __('hosted.search.any') }}</option>
                @foreach ($vessels as $vessel)
                    <option value="{{ $vessel->uuid }}" @selected(($applied['vessel'] ?? null) === $vessel->uuid)>{{ $vessel->name }}</option>
                @endforeach
            </select>
        </div>
    @endif

    {{-- The locale travels with the submission, or a Greek visitor lands back on
         the operator's default language after every search. --}}
    <input type="hidden" name="lang" value="{{ $locale }}">

    <div class="field submit">
        <button type="submit" class="button">@include('hosted.partials.icon', ['name' => 'search']){{ __('hosted.search.submit') }}</button>
    </div>
</form>
