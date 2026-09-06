{{--
    The hosted page shell (spec HOS-1, HOS-4, HOS-5, HOS-8, HOS-9, HOS-10).

    ## No script tag, anywhere in this file

    HOS-4: everything renders server-side. The widget arrives in M3's later
    issues and mounts into one named region; until then, and whenever a visitor
    blocks scripts, the page is complete without it.

    ## The brand block carries a nonce

    HOS-8's policy has no `unsafe-inline`, so the operator's colours reach the
    page through a `<style>` element with the per-response nonce the middleware
    minted. That is the whole reason the nonce exists, and why it is on the
    request rather than generated here — two generators would disagree.

    ## No `text-transform: uppercase`

    I18N-2. Greek capitals drop their accents and browsers disagree about the
    final sigma. Small labels use letter-spacing and weight in normal case.

    ## Light only

    Settled on 4 September: guest-facing surfaces are light, and light/dark is a
    dashboard concern. So there is no `prefers-color-scheme` block here — the
    colours are the operator's, and they chose them against white.
--}}
@php
    /** @var \App\Models\Tenant $tenant */
    /** @var array<string, mixed> $brand */
    $colors = $brand['colors'] ?? [];
    $primary = $colors['primary'] ?? '#0B4F4A';
    $accent = $colors['accent'] ?? '#B5511F';
    $text = $colors['text'] ?? '#16211F';
    $radius = $brand['button_radius_px'] ?? 10;
    $fontCss = $brand['font']['css_url'] ?? null;
    $fontFamily = $brand['font']['family'] ?? 'Inter';
    $logo = $brand['logo']['light_url'] ?? null;
@endphp
<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>@yield('title', $tenant->name)</title>
    <meta name="description" content="@yield('description', $tenant->name)">

    {{-- HOS-5: one canonical per locale, and each alternate points at the other. --}}
    <link rel="canonical" href="{{ $alternates[$locale] }}">
    @foreach ($alternates as $alternate => $url)
        <link rel="alternate" hreflang="{{ $alternate }}" href="{{ $url }}">
    @endforeach
    <link rel="alternate" hreflang="x-default" href="{{ $alternates['en'] }}">

    {{-- WGT-10's rule, applied to the page: a third-party request only when the
         operator actually chose a font. The CSP omits the host otherwise, so an
         accidental link here would be blocked rather than silently allowed. --}}
    @if ($fontCss)
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="{{ $fontCss }}">
    @endif

    <style nonce="{{ $nonce }}">
        :root {
            --kaiki-primary: {{ $primary }};
            --kaiki-accent: {{ $accent }};
            --kaiki-text: {{ $text }};
            --kaiki-radius: {{ $radius }}px;
            --kaiki-font: {{ $fontFamily }}, Inter, "Helvetica Neue", Arial, sans-serif;

            --paper: #F7F9F8;
            --surface: #FFFFFF;
            --rule: #E1E9E7;
            --ink-soft: #4A5D5A;
            --ink-faint: #7B8D8A;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--paper);
            color: var(--kaiki-text);
            font-family: var(--kaiki-font);
            font-size: 16px;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }

        a { color: var(--kaiki-primary); }
        a:focus-visible, button:focus-visible { outline: 2px solid var(--kaiki-accent); outline-offset: 2px; }

        .wrap { max-width: 66rem; margin: 0 auto; padding: 0 1.25rem; }

        /* --- nav --- */

        header.site {
            background: var(--surface);
            border-bottom: 1px solid var(--rule);
        }

        header.site .wrap {
            display: flex; align-items: center; justify-content: space-between;
            gap: 1rem; padding-block: 1rem;
        }

        .brand { display: flex; align-items: center; gap: .7rem; text-decoration: none; color: inherit; }
        .brand img { max-height: 44px; width: auto; }
        .brand .name { font-weight: 800; font-size: 1.15rem; letter-spacing: -.02em; }

        /* HOS-5 and brand decision 2: a visible switch on every guest surface. */
        .langs { display: flex; gap: .25rem; font-size: .85rem; }
        .langs a {
            text-decoration: none; padding: .3rem .55rem;
            border: 1px solid var(--rule); border-radius: var(--kaiki-radius);
            color: var(--ink-soft);
        }
        .langs a[aria-current="true"] {
            background: var(--kaiki-primary); border-color: var(--kaiki-primary); color: #fff;
        }

        /* --- content --- */

        main { padding-block: 2.5rem 3.5rem; }

        h1 { font-weight: 800; font-size: clamp(1.8rem, 4.5vw, 2.6rem); letter-spacing: -.025em; margin: 0 0 .6rem; text-wrap: balance; }
        h2 { font-weight: 700; font-size: 1.35rem; letter-spacing: -.015em; margin: 2.5rem 0 1rem; }

        /* Repeated cards are equal height with their last row pinned to the
           bottom — settled on 4 September, and the reason is that a row of
           cards whose prices sit at different heights reads as a mistake. The
           price row lands here with the product page; the grid is built for it
           now so it does not have to be retrofitted. */
        ul.trips {
            list-style: none; margin: 0; padding: 0;
            display: grid; grid-template-columns: repeat(auto-fill, minmax(17rem, 1fr));
            gap: 1.1rem;
        }

        li.trip {
            background: var(--surface); border: 1px solid var(--rule);
            border-radius: 14px; padding: 1.15rem 1.25rem;
            display: flex; flex-direction: column;
        }

        li.trip h3 { margin: 0 0 .4rem; font-size: 1.05rem; font-weight: 700; letter-spacing: -.012em; }
        li.trip .summary { margin: 0 0 .8rem; color: var(--ink-soft); font-size: .93rem; }
        li.trip .facts { margin: auto 0 0; color: var(--ink-faint); font-size: .84rem; }

        /* HOS-10: the booking area, replaced by a sentence. */
        .read-only {
            background: #FDF2EC; border: 1px solid var(--kaiki-accent); border-left-width: 4px;
            padding: 1rem 1.15rem; border-radius: var(--kaiki-radius); margin: 1.5rem 0;
        }

        /* --- home page blocks (#102) ------------------------------ */

        /* Each block owns its vertical rhythm through the gap on `main`
           rather than through margins, so two adjacent blocks never
           collapse or double their spacing. */
        main .wrap { display: flex; flex-direction: column; gap: 3rem; }

        .block { margin: 0; }
        .block > h2:first-child { margin-top: 0; }

        .hero { display: grid; gap: 1.5rem; }
        .hero.has-image { grid-template-columns: 1fr; }
        .hero-image { width: 100%; height: auto; border-radius: 14px; object-fit: cover; aspect-ratio: 16 / 9; }
        .hero-copy .standfirst { color: var(--ink-soft); font-size: 1.08rem; max-width: 42rem; }
        .hero-copy .standfirst p { margin: 0 0 .7rem; }

        .cta { margin: 1.25rem 0 0; }
        .button {
            display: inline-block; text-decoration: none;
            background: var(--kaiki-primary); color: #fff;
            padding: .7rem 1.4rem; border-radius: var(--kaiki-radius);
            font-weight: 600; font-size: .97rem;
        }

        .story { display: grid; gap: 1.5rem; align-items: start; }
        .story-image { width: 100%; height: auto; border-radius: 14px; object-fit: cover; aspect-ratio: 4 / 3; }
        .prose p { margin: 0 0 .8rem; }
        .prose p:last-child { margin-bottom: 0; }

        @media (min-width: 46rem) {
            .story.has-image { grid-template-columns: 1fr 1fr; }
            /* A class rather than an inline style: HOS-8's policy has no
               `unsafe-inline`, so a `style` attribute would be dropped and
               the operator's choice would silently do nothing. */
            .story.side-left.has-image .story-image { order: -1; }
        }

        .gallery .shots {
            list-style: none; margin: 1rem 0 0; padding: 0;
            display: grid; gap: .8rem;
            grid-template-columns: repeat(auto-fill, minmax(13rem, 1fr));
        }
        .gallery.cols-2 .shots { grid-template-columns: repeat(auto-fill, minmax(19rem, 1fr)); }
        .gallery.cols-4 .shots { grid-template-columns: repeat(auto-fill, minmax(10rem, 1fr)); }
        .gallery .shots img { width: 100%; height: 100%; border-radius: 10px; object-fit: cover; aspect-ratio: 3 / 2; }

        .contact-list { list-style: none; margin: 1rem 0 0; padding: 0; display: grid; gap: .7rem; }
        .contact-list li { display: grid; gap: .1rem; }
        .contact-list .label {
            font-size: .74rem; font-weight: 600; letter-spacing: .07em;
            color: var(--ink-faint);
        }
        .contact-list .instructions { color: var(--ink-soft); font-size: .9rem; }

        /* --- footer --- */

        footer.site {
            background: var(--surface); border-top: 1px solid var(--rule);
            padding-block: 2rem 2.5rem; font-size: .88rem; color: var(--ink-soft);
        }

        footer.site .cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); gap: 1.5rem; }
        footer.site h3 { font-size: .74rem; font-weight: 600; letter-spacing: .09em; color: var(--ink-faint); margin: 0 0 .5rem; }
        footer.site p { margin: 0 0 .3rem; }
        footer.site ul { margin: 0; padding: 0; list-style: none; }
        footer.site li { margin-bottom: .3rem; }

        .powered {
            margin-top: 1.75rem; padding-top: 1rem; border-top: 1px solid var(--rule);
            font-size: .8rem; color: var(--ink-faint);
        }
    </style>
</head>
<body>

<header class="site">
    <div class="wrap">
        <a class="brand" href="{{ route('hosted.index', ['operator' => $tenant->slug, 'lang' => $locale]) }}">
            @if ($logo)
                <img src="{{ $logo }}" alt="{{ $tenant->name }}">
            @else
                <span class="name">{{ $tenant->name }}</span>
            @endif
        </a>

        <nav class="langs" aria-label="{{ __('hosted.nav.language') }}">
            @foreach (['el' => 'ΕΛ', 'en' => 'EN'] as $code => $label)
                <a href="{{ $alternates[$code] }}"
                   hreflang="{{ $code }}"
                   @if ($code === $locale) aria-current="true" @endif>{{ $label }}</a>
            @endforeach
        </nav>
    </div>
</header>

<main>
    <div class="wrap">
        @yield('content')
    </div>
</main>

<footer class="site">
    <div class="wrap">
        <div class="cols">
            {{-- HOS-9: the operator's legal identity, in both locales. --}}
            <div>
                <h3>{{ __('hosted.footer.operator') }}</h3>
                <p><strong>{{ $tenant->legal_name ?? $tenant->name }}</strong></p>
                @if ($tenant->address_line1)
                    <p>{{ $tenant->address_line1 }}</p>
                @endif
                @if ($tenant->city || $tenant->postcode)
                    <p>{{ trim($tenant->postcode . ' ' . $tenant->city) }}</p>
                @endif
                @if ($tenant->vat_number)
                    <p>{{ __('hosted.footer.vat_number') }}: {{ $tenant->vat_number }}</p>
                @endif
                @if ($tenant->tax_office)
                    <p>{{ __('hosted.footer.tax_office') }}: {{ $tenant->tax_office }}</p>
                @endif
            </div>

            <div>
                <h3>{{ __('hosted.footer.contact') }}</h3>
                <p><a href="mailto:{{ $tenant->email }}">{{ $tenant->email }}</a></p>
                @if ($tenant->phone)
                    <p><a href="tel:{{ $tenant->phone }}">{{ $tenant->phone }}</a></p>
                @endif
            </div>

            <div>
                <h3>{{ __('hosted.footer.legal') }}</h3>
                <ul>
                    <li><a href="{{ route('hosted.legal', ['operator' => $tenant->slug, 'lang' => $locale]) }}">{{ __('hosted.footer.terms') }}</a></li>
                    <li><a href="{{ route('hosted.legal', ['operator' => $tenant->slug, 'lang' => $locale]) }}#privacy">{{ __('hosted.footer.privacy') }}</a></li>
                    <li><a href="{{ route('hosted.legal', ['operator' => $tenant->slug, 'lang' => $locale]) }}#cancellation">{{ __('hosted.footer.cancellation') }}</a></li>
                </ul>
            </div>
        </div>

        @if ($poweredBy)
            {{-- Brand decision 6: always, custom domains included. From a flag,
                 so a white-label tier is a config change rather than an edit. --}}
            <p class="powered">{{ __('hosted.footer.powered_by') }}</p>
        @endif
    </div>
</footer>

</body>
</html>
