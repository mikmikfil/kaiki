{{--
    The brand mark at the top of a sign-in screen, on a phone.

    ## Why this exists rather than a CSS rule

    Filament renders its own `.fi-logo` inside `fi-simple-header`, which sits
    *below* the locale switcher and *above* the heading — and the order asked
    for is mark, switcher, form. The two are at different depths in the markup,
    so no amount of `order` or `flex-direction` puts one before the other:
    `order` only sorts siblings, and these are not siblings.

    So the mark is rendered again here, from `SIMPLE_PAGE_START`, registered
    before the switcher's hook so it comes out first. Filament's own copy is
    hidden below `lg` in `auth-split.blade.php`; above `lg` this one is hidden
    instead and Filament's is the one placed on the photograph. Exactly one is
    ever visible.

    ## Nothing here is a name

    The image is the platform logo if one has been uploaded on
    `/admin` → Εμφάνιση, and the text is the panel's own `brandName()`. Neither
    is written down in this file — `NoHardcodedStringsTest` scans this directory
    (I18N-1), and more to the point a second copy of the product's name is a
    second place to change it.
--}}
@php
    $panel = \Filament\Facades\Filament::getCurrentPanel();
    $brandName = $panel?->getBrandName();
    $logo = \App\Models\PlatformBrand::logoUrl();
@endphp

<div class="kaiki-auth-brandmark">
    @if ($logo)
        <img src="{{ $logo }}" alt="{{ $brandName }}">
    @else
        <span>{{ $brandName }}</span>
    @endif
</div>
