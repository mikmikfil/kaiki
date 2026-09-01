{{--
    Union Jack rather than the Stars and Stripes, to match the `en_GB`
    formatting decision in App\Support\Format\Locales.

    The clip path id is namespaced because two SVGs on one page with the same id
    silently take each other's geometry.
--}}
<svg class="kaiki-locale-switcher__flag" viewBox="0 0 60 30" role="presentation" focusable="false">
    <clipPath id="kaiki-flag-en-clip">
        <path d="M30 15h30v15zv15H0zH0V0zV0h30z" />
    </clipPath>
    <path d="M0 0v30h60V0z" fill="#012169" />
    <path d="M0 0l60 30m0-30L0 30" stroke="#fff" stroke-width="6" />
    <path d="M0 0l60 30m0-30L0 30" clip-path="url(#kaiki-flag-en-clip)" stroke="#c8102e" stroke-width="4" />
    <path d="M30 0v30M0 15h60" stroke="#fff" stroke-width="10" />
    <path d="M30 0v30M0 15h60" stroke="#c8102e" stroke-width="6" />
</svg>
