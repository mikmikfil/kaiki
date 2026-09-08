{{--
    The invoice a guest downloads (MYD-12).

    ## Everything printed comes off the invoice row

    Not off the booking, and not off `vat_rates`. The row was frozen at issuance
    from a price snapshot that was itself frozen at pricing time (PRC-14), and
    reaching past it would let a vessel renamed in March or a statutory rate
    changed in April rewrite a document AADE registered in February.

    ## The QR is AADE's URL or nothing

    `qr_url` comes back with the MARK and points at the tax authority's own
    verification page. It cannot be constructed here — it is issued — so a
    document without one prints without a square rather than with a square
    pointing somewhere plausible. Somebody will scan it and believe the answer.

    ## No image, same as the e-ticket

    Chromium renders this with no file access (SEC-14). The operator's identity
    is the name and the accent colour, both of which survive an image block in
    any mail client and need no local file here.
--}}
<!doctype html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->reference() }}</title>
    <style>
        @page { size: A4; margin: 0; }

        body {
            margin: 0;
            font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif;
            font-size: 10.5pt;
            line-height: 1.5;
            color: #14202b;
        }

        .masthead {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2.5pt solid {{ $brand['colors']['primary'] ?? '#0B4F4A' }};
            padding-bottom: 10pt;
            margin-bottom: 16pt;
        }

        .masthead h1 { font-size: 15pt; margin: 0 0 2pt; }
        .masthead .kind { font-size: 10pt; color: #5a6b7a; margin: 0; }
        .masthead .who { text-align: right; font-size: 9.5pt; line-height: 1.45; }
        .masthead .who strong { font-size: 11pt; }

        h2 {
            font-size: 10.5pt;
            margin: 16pt 0 4pt;
            color: {{ $brand['colors']['primary'] ?? '#0B4F4A' }};
        }

        table { width: 100%; border-collapse: collapse; }

        td { padding: 3.5pt 0; vertical-align: top; border-bottom: 0.5pt solid #e4ecf1; }
        td.label { width: 40%; color: #5a6b7a; }

        .totals { margin-top: 14pt; }
        .totals td { border-bottom: none; padding: 2.5pt 0; }
        .totals td.figure { text-align: right; font-variant-numeric: tabular-nums; }
        .totals tr.grand td { border-top: 1pt solid #14202b; padding-top: 6pt; font-weight: bold; font-size: 12pt; }

        .verify {
            margin-top: 20pt;
            padding-top: 12pt;
            border-top: 0.5pt solid #cfd9e0;
            display: flex;
            gap: 14pt;
            align-items: flex-start;
        }

        .verify .qr { width: 26mm; height: 26mm; flex: none; }
        .verify .qr svg { width: 100%; height: 100%; }
        .verify p { margin: 0 0 4pt; font-size: 9pt; color: #5a6b7a; }
        .verify .mark { font-family: 'DejaVu Sans Mono', monospace; font-size: 9.5pt; color: #14202b; }

        .footer { margin-top: 16pt; font-size: 8.5pt; color: #8797a5; }
    </style>
</head>
<body style="padding: 15mm;">

@php
    $money = static fn (?int $cents): string => number_format(((int) $cents) / 100, 2, ',', '.') . ' €';
@endphp

<div class="masthead">
    <div>
        <h1>{{ $invoice->reference() }}</h1>
        <p class="kind">{{ $invoice->type->label() }}</p>
    </div>
    <div class="who">
        <strong>{{ $tenant->legal_name ?: $tenant->name }}</strong><br>
        @if ($tenant->vat_number)
            ΑΦΜ {{ $tenant->vat_number }}@if ($tenant->taxOfficeName()) · {{ $tenant->taxOfficeName() }} @endif<br>
        @endif
        @if ($tenant->address_line1)
            {{ trim($tenant->address_line1 . ' ' . ($tenant->postcode ?? '') . ' ' . ($tenant->city ?? '')) }}<br>
        @endif
        {{ $tenant->email }}
    </div>
</div>

@if ($invoice->counterparty_name)
    <h2>Στοιχεία πελάτη</h2>
    <table>
        <tr>
            <td class="label">Επωνυμία</td>
            <td>{{ $invoice->counterparty_name }}</td>
        </tr>
        <tr>
            <td class="label">ΑΦΜ</td>
            <td>{{ $invoice->counterparty_vat }}</td>
        </tr>
        @if ($invoice->counterparty_country && $invoice->counterparty_country !== 'GR')
            <tr>
                <td class="label">Χώρα</td>
                <td>{{ $invoice->counterparty_country }}</td>
            </tr>
        @endif
    </table>
@endif

<h2>Η υπηρεσία</h2>

<table>
    <tr>
        <td class="label">Περιγραφή</td>
        <td>{{ $invoice->booking?->product?->getTranslation('title', 'el') ?? 'Θαλάσσια εκδρομή' }}</td>
    </tr>
    <tr>
        <td class="label">Ημερομηνία εκδρομής</td>
        <td>{{ $invoice->booking?->local_date?->format('d/m/Y') ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">Αριθμός κράτησης</td>
        <td>{{ $invoice->booking?->reference ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">Ημερομηνία έκδοσης</td>
        <td>{{ $invoice->issued_at?->format('d/m/Y H:i') ?? '—' }}</td>
    </tr>
</table>

<table class="totals">
    <tr>
        <td class="label">Καθαρή αξία</td>
        <td class="figure">{{ $money($invoice->net_cents) }}</td>
    </tr>
    <tr>
        {{-- The rate as it was, from the row. `vat_rates` is not consulted: a
             statutory change must not rewrite a registered document. --}}
        <td class="label">ΦΠΑ {{ number_format($invoice->vat_rate_bp / 100, 0) }}%</td>
        <td class="figure">{{ $money($invoice->vat_cents) }}</td>
    </tr>
    <tr class="grand">
        <td>Σύνολο</td>
        <td class="figure">{{ $money($invoice->total_cents) }}</td>
    </tr>
</table>

@if ($invoice->mark)
    <div class="verify">
        @if ($qr)
            <div class="qr">{!! $qr !!}</div>
        @endif
        <div>
            <p>Καταχωρήθηκε στο myDATA της ΑΑΔΕ.</p>
            <p class="mark">MARK {{ $invoice->mark }}</p>
            @if ($qr)
                <p>Σαρώστε τον κωδικό για επαλήθευση στην ΑΑΔΕ.</p>
            @endif
        </div>
    </div>
@endif

<p class="footer">
    {{ $tenant->name }}
    @if ($invoice->environment !== 'live')
        · <strong>Δοκιμαστικό περιβάλλον — δεν έχει καταχωρηθεί πραγματικά στην ΑΑΔΕ</strong>
    @endif
</p>

</body>
</html>
