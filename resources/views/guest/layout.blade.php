{{--
    The shell every token page wears (spec TOK-5, BRD-1, TOK-3).

    Mobile-first and branded: a guest opens these on a phone, on a quay, on
    hotel wifi, and the page has to look like the operator they booked with
    rather than like this platform. The operator's colours, radius and font come
    through `css_variables` from `GetBrandPayload`, which is the same payload
    the widget uses — so a page and a widget on the same site cannot disagree
    about what the brand is.

    No analytics, no third-party script, no external image. TOK-3 sets
    `Referrer-Policy: no-referrer` so a token cannot leak through a `Referer`
    header, and the cheapest way to keep that true is to have almost nothing
    that makes a request. The one exception is the operator's own font
    stylesheet, which they chose.

    `<html lang>` is the resolved locale, not the browser's: TOK-5 takes it from
    the booking, and a screen reader reading Greek names in an English voice is
    the failure that gets noticed last.
--}}
@php
    /** @var array<string, mixed> $brand */
    $brand = $brand ?? [];
    $colors = $brand['css_variables'] ?? [];
    $tenantName = $brand['tenant']['name'] ?? config('app.name');
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Belt and braces with the header TOK-3 sets: a page saved to disk and
         reopened keeps the meta, and the header does not. --}}
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>{{ $title ?? __('guest.common.title') }} — {{ $tenantName }}</title>

    @if (! empty($brand['font']['css_url']))
        <link rel="stylesheet" href="{{ $brand['font']['css_url'] }}">
    @endif

    @if (! empty($brand['logo']['favicon_url']))
        <link rel="icon" href="{{ $brand['logo']['favicon_url'] }}">
    @endif

    <style>
        :root {
            @foreach ($colors as $name => $value)
                {{ $name }}: {{ $value }};
            @endforeach
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--kaiki-background, #f6f7f9);
            color: var(--kaiki-text, #14202b);
            font-family: var(--kaiki-font-family, system-ui), system-ui, -apple-system, sans-serif;
            font-size: 16px;
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
        }

        .wrap { max-width: 40rem; margin: 0 auto; padding: 1.25rem 1rem 4rem; }

        /* Checkout is the one guest page with two things to show at once — what
           you are paying for, and the form that pays for it — so it gets a
           wider measure than the pages that are a single column of facts. */
        .wrap.wide { max-width: 62rem; }

        .checkout-grid { display: grid; gap: 1.25rem; }

        @media (min-width: 56rem) {
            .checkout-grid {
                grid-template-columns: minmax(0, 1fr) 23rem;
                gap: 1.5rem;
                align-items: start;
            }

            /* The summary is first in the markup so a phone shows what is being
               paid for before the fields, and `order` puts it on the right on a
               wide screen without a second copy of the markup. */
            .checkout-main { order: 1; }
            .checkout-side { order: 2; position: sticky; top: 1rem; }
        }

        header.brand { display: flex; align-items: center; gap: .75rem; padding: .5rem 0 1.25rem; }
        header.brand img { max-height: 44px; width: auto; }
        header.brand .name { font-weight: 700; font-size: 1.05rem; }

        .card {
            background: #fff;
            border: 1px solid rgba(0, 0, 0, .08);
            border-radius: var(--kaiki-radius, 8px);
            padding: 1.1rem 1.15rem;
            margin-bottom: 1rem;
        }

        h1 { font-size: 1.4rem; line-height: 1.25; margin: 0 0 .35rem; }
        h2 { font-size: 1.05rem; margin: 0 0 .6rem; }

        .muted { color: rgba(0, 0, 0, .6); font-size: .92rem; }

        dl.rows { margin: 0; display: grid; grid-template-columns: 1fr auto; gap: .35rem .75rem; }
        dl.rows dt { color: rgba(0, 0, 0, .6); }
        dl.rows dd { margin: 0; text-align: right; font-variant-numeric: tabular-nums; }

        .total { font-weight: 700; border-top: 1px solid rgba(0, 0, 0, .12); padding-top: .5rem; margin-top: .35rem; }

        .btn {
            display: inline-block; width: 100%; text-align: center;
            padding: .8rem 1rem; border: 0; cursor: pointer;
            border-radius: var(--kaiki-radius, 8px);
            background: var(--kaiki-primary, #0b3d91); color: #fff;
            font: inherit; font-weight: 600; text-decoration: none;
        }

        .btn.secondary { background: transparent; color: var(--kaiki-text, #14202b); border: 1px solid rgba(0, 0, 0, .2); }
        .btn.danger { background: #a8321f; }
        .btn + .btn { margin-top: .5rem; }

        label { display: block; font-size: .9rem; font-weight: 600; margin: .75rem 0 .25rem; }

        input, select, textarea {
            width: 100%; padding: .65rem .7rem; font: inherit;
            border: 1px solid rgba(0, 0, 0, .25);
            border-radius: var(--kaiki-radius, 8px);
            background: #fff; color: inherit;
        }

        /* The consent row. `input { width: 100% }` above is right for text
           fields and wrong for a checkbox, which was stretching to the width of
           the card with its label orphaned underneath. */
        .consent { margin: 1rem 0 1.25rem; }

        .consent label {
            display: flex; align-items: flex-start; gap: .6rem;
            margin: 0; font-weight: 400; font-size: .92rem; line-height: 1.4;
        }

        .consent input[type="checkbox"] {
            width: auto; flex: none; margin-block-start: .15rem;
        }

        .field-error { margin: .3rem 0 0; font-size: .85rem; color: #a8321f; }

        /* One folded panel per passenger, so a manifest of eight is a list the
           length of the party rather than twenty-four fields in a column. */
        details.passenger {
            margin: .5rem 0 0;
            border: 1px solid rgba(0, 0, 0, .15);
            border-radius: var(--kaiki-radius, 8px);
            background: #fff;
        }

        details.passenger > summary {
            cursor: pointer; list-style: none;
            padding: .7rem .9rem;
            font-size: .9rem; font-weight: 600;
            display: flex; align-items: baseline; gap: .5rem;
        }

        details.passenger > summary::-webkit-details-marker { display: none; }

        /* The chevron, and the only thing saying these open. */
        details.passenger > summary::after {
            content: '';
            inline-size: .45rem; block-size: .45rem; margin-inline-start: auto;
            border-right: 2px solid rgba(0, 0, 0, .45);
            border-bottom: 2px solid rgba(0, 0, 0, .45);
            rotate: 45deg; translate: 0 -2px;
        }

        details.passenger[open] > summary::after { rotate: 225deg; translate: 0 2px; }

        /* The name once it is typed, so a folded row still says who is in it. */
        details.passenger .passenger-name { font-weight: 400; color: rgba(0, 0, 0, .55); }

        .passenger-body { padding: 0 .9rem 1rem; }
        .passenger-body label:first-child { margin-top: 0; }

        /* The pay button sits in the summary column and submits the form in the
           other one, so it needs its own top margin rather than the form's. */
        .btn.pay { margin-top: 1.1rem; }
        .secure { margin-top: .6rem; font-size: .82rem; }

        .notice-error { border-color: #a8321f; }

        .field-pair { display: grid; grid-template-columns: 1fr 1fr; gap: 0 .75rem; }
        @media (max-width: 30rem) { .field-pair { grid-template-columns: 1fr; } }

        .notice {
            border-left: 4px solid var(--kaiki-accent, #8a6410);
            background: rgba(0, 0, 0, .03);
            padding: .8rem .9rem; margin: 0 0 1rem;
            border-radius: 0 var(--kaiki-radius, 8px) var(--kaiki-radius, 8px) 0;
            font-size: .93rem;
        }

        .notice.bad { border-left-color: #a8321f; }

        footer.brand { padding: 1.5rem 0 0; font-size: .85rem; color: rgba(0, 0, 0, .55); }
    </style>
</head>
<body>
<div class="wrap @if ($wide ?? false) wide @endif">
    <header class="brand">
        @if (! empty($brand['logo']['light_url']))
            <img src="{{ $brand['logo']['light_url'] }}" alt="{{ $tenantName }}">
        @else
            <span class="name">{{ $tenantName }}</span>
        @endif
    </header>

    @yield('content')

    <footer class="brand">
        {{ $brand['email_footer_text'] ?? $tenantName }}
    </footer>
</div>
</body>
</html>
