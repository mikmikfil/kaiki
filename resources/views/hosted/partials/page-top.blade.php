{{--
    The top of a page that has no hero of its own: «Βρείτε εκδρομή» and
    «Επικοινωνήστε μαζί μας» (Mike, 2026-09-24: «ας βάλουμε χρώμα, μην είναι
    άδειο»; direction Α of docs/mockups/page-tops.html).

    A full-bleed dark band of the same kind as the home page's dark boxes — it
    *is* one: `.band.band-dark` gives it their gradient and their drifting glow,
    so the three can never drift apart. The crumbs, the eyebrow, the white
    title and the lede sit in the page's column, and whatever follows the band
    (the search bar, the contact cards) climbs over its lower edge.

    Expects: $tenant, $locale, $eyebrow, $title, $lede.
--}}
<header class="band band-dark page-top">
    <nav class="crumbs" aria-label="{{ __('hosted.page_top.breadcrumb') }}">
        @if (\App\Domain\Hosted\Support\HostedUrl::homeEnabledFor($tenant))
            <a href="{{ route('hosted.index', ['operator' => $tenant->slug, 'lang' => $locale]) }}">{{ __('hosted.page_top.home') }}</a>
            <span aria-hidden="true">›</span>
        @endif
        <span aria-current="page">{{ $title }}</span>
    </nav>
    <p class="eyebrow-line">{{ $eyebrow }}</p>
    <h1>{{ $title }}</h1>
    <p class="lede">{{ $lede }}</p>
</header>
