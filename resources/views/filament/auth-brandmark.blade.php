{{--
    The blue side of a sign-in screen: the mark, one line, and
    the moving waves (product owner, 2026-09-17, direction C).

    ## One element, two places

    On a wide screen it is laid over the left column; on a phone it is the
    blue band across the top. Filament's
    own `.fi-logo` is hidden at every width, so exactly one mark is ever on
    screen. Rendered from `SIMPLE_PAGE_START`, registered before the language
    switch, so on a phone the order is band, language, form.

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
    <div class="kaiki-auth-brandmark-head">
        @if ($logo)
            <img src="{{ $logo }}" alt="{{ $brandName }}">
        @else
            <span class="kaiki-auth-brandmark-name">{{ $brandName }}</span>
        @endif

        <p>{{ __('auth.intro.line') }}</p>
    </div>
</div>

<script>
    /* The waves answer the pointer: they rise as it comes down towards them
       and lean after it sideways. Only two CSS variables change here, once per
       frame at most; the easing is in the stylesheet's transition. Nothing for
       touch, which has no hover, or for anyone who asked their device for
       less motion. */
    (function () {
        const panel = document.querySelector('.kaiki-auth-brandmark');

        if (! panel || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }

        let frame = 0;

        panel.addEventListener('pointermove', (event) => {
            if (event.pointerType === 'touch' || frame) {
                return;
            }

            frame = requestAnimationFrame(() => {
                frame = 0;

                const box = panel.getBoundingClientRect();
                const x = (event.clientX - box.left) / box.width - 0.5;
                const y = Math.min(Math.max((event.clientY - box.top) / box.height, 0), 1);

                panel.style.setProperty('--ka-wave-shift', (x * 60).toFixed(1));
                panel.style.setProperty('--ka-wave-lift', (1 + Math.pow(y, 2) * 0.45).toFixed(3));
            });
        }, { passive: true });

        panel.addEventListener('pointerleave', () => {
            panel.style.setProperty('--ka-wave-shift', '0');
            panel.style.setProperty('--ka-wave-lift', '1');
        });
    })();
</script>
