{{--
    Το ναυλοσύμφωνο — **προσωρινό κείμενο** (CMP-6, CMP-7).

    ## Read this before changing anything

    The wording of a ναυλοσύμφωνο is prescribed by ΚΥΑ Α.Π. 3133.1/47821 and
    **nobody on this project has read it**. What follows is a layout carrying the
    fields such a document needs — the two parties, the vessel, the window, the
    port, the passengers, the price — in an order that reads sensibly. It is not
    the prescribed form and does not claim to be.

    The banner at the top says so in Greek, on the document itself, because a
    provisional legal form that does not announce itself is one somebody hands
    to a harbour master. When a lawyer supplies the real text:

    1. Replace the body below.
    2. Change `GenerateCharterAgreement::TEMPLATE_VERSION` from
       `provisional-2026-09` to `v1`. CMP-8 keys immutability to the version, so
       every agreement produced under the provisional template stays visibly
       distinguishable from a real one — in the row, in the snapshot and in the
       filename.
    3. Delete the banner.

    ## Everything printed comes from `$fields`, never from a model

    CMP-7: a regenerated PDF must be byte-comparable in content to the one the
    guest accepted. A template that reached back through `$agreement->booking`
    would print today's vessel name into last February's document. The snapshot
    was frozen at generation and this reads only that.

    ## No image, and the same reasoning as the e-ticket

    Chromium renders this with no file access (SEC-14). A logo would need one.
    The operator's identity is carried by the name and the accent colour.
--}}
<!doctype html>
<html lang="el">
<head>
    <meta charset="utf-8">
    <title>Ναυλοσύμφωνο · {{ $fields['charter']['reference'] ?? '' }}</title>
    <style>
        @page { size: A4; margin: 0; }

        body {
            margin: 0;
            font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif;
            font-size: 10.5pt;
            line-height: 1.5;
            color: #14202b;
        }

        .provisional {
            background: #fdf4ef;
            border: 1.5pt solid #b5511f;
            color: #8a3d16;
            padding: 8pt 10pt;
            margin-bottom: 14pt;
            font-size: 9.5pt;
            line-height: 1.45;
        }

        .provisional strong { display: block; margin-bottom: 3pt; font-size: 10.5pt; }

        h1 {
            font-size: 16pt;
            margin: 0 0 2pt;
            letter-spacing: -0.01em;
        }

        .sub { color: #5a6b7a; font-size: 9.5pt; margin: 0 0 14pt; }

        .rule { border-bottom: 2pt solid {{ $brand['colors']['primary'] ?? '#0B4F4A' }}; margin: 0 0 14pt; }

        h2 {
            font-size: 11pt;
            margin: 14pt 0 4pt;
            color: {{ $brand['colors']['primary'] ?? '#0B4F4A' }};
        }

        table { width: 100%; border-collapse: collapse; }

        td {
            padding: 3.5pt 0;
            vertical-align: top;
            border-bottom: 0.5pt solid #e4ecf1;
        }

        td.label { width: 38%; color: #5a6b7a; }

        .parties td { border-bottom: none; padding-right: 12pt; width: 50%; }

        .signature {
            margin-top: 22pt;
            border-top: 0.5pt solid #cfd9e0;
            padding-top: 10pt;
            font-size: 9.5pt;
            color: #5a6b7a;
        }

        .footer {
            margin-top: 18pt;
            font-size: 8.5pt;
            color: #8797a5;
        }
    </style>
</head>
<body style="padding: 15mm;">

<div class="provisional">
    <strong>ΠΡΟΣΩΡΙΝΟ ΥΠΟΔΕΙΓΜΑ — ΔΕΝ ΕΙΝΑΙ ΤΟ ΘΕΣΜΟΘΕΤΗΜΕΝΟ ΕΝΤΥΠΟ</strong>
    Το κείμενο του ναυλοσυμφώνου ορίζεται από την ΚΥΑ Α.Π. 3133.1/47821. Αυτό το
    έγγραφο περιέχει τα στοιχεία της ναύλωσης σε προσωρινή μορφή, εν αναμονή του
    οριστικού κειμένου από νομικό σύμβουλο. <strong style="display:inline">Μην το
    χρησιμοποιήσετε ως επίσημο ναυλοσύμφωνο.</strong>
</div>

<h1>Ναυλοσύμφωνο</h1>
<p class="sub">
    Αρ. κράτησης {{ $fields['charter']['reference'] ?? '—' }}
    · Έκδοση υποδείγματος {{ $fields['template_version'] ?? '—' }}
</p>

<div class="rule"></div>

<h2>Τα συμβαλλόμενα μέρη</h2>

<table class="parties">
    <tr>
        <td>
            <strong>Εκναυλωτής</strong><br>
            {{ $fields['operator']['legal_name'] ?? '—' }}<br>
            @if (! empty($fields['operator']['vat_number']))
                ΑΦΜ {{ $fields['operator']['vat_number'] }}
                @if (! empty($fields['operator']['tax_office'])) · {{ $fields['operator']['tax_office'] }} @endif
                <br>
            @endif
            @if (! empty($fields['operator']['gemi_number']))
                ΓΕΜΗ {{ $fields['operator']['gemi_number'] }}<br>
            @endif
            @if (! empty($fields['operator']['address']))
                {{ $fields['operator']['address'] }}<br>
            @endif
            @if (! empty($fields['operator']['phone']))
                {{ $fields['operator']['phone'] }}<br>
            @endif
            {{ $fields['operator']['email'] ?? '' }}
        </td>
        <td>
            <strong>Ναυλωτής</strong><br>
            {{ $fields['charterer']['company_name'] ?: $fields['charterer']['name'] ?? '—' }}<br>
            @if (! empty($fields['charterer']['vat_number']))
                ΑΦΜ {{ $fields['charterer']['vat_number'] }}<br>
            @endif
            @if (! empty($fields['charterer']['company_name']))
                Εκπρόσωπος: {{ $fields['charterer']['name'] }}<br>
            @endif
            @if (! empty($fields['charterer']['phone']))
                {{ $fields['charterer']['phone'] }}<br>
            @endif
            {{ $fields['charterer']['email'] ?? '' }}
        </td>
    </tr>
</table>

<h2>Το σκάφος</h2>

<table>
    <tr>
        <td class="label">Όνομα σκάφους</td>
        <td>{{ $fields['vessel']['name'] ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">Αριθμός νηολογίου (ΑΛΣ)</td>
        <td>{{ $fields['vessel']['registration_number'] ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">Κυβερνήτης</td>
        <td>{{ $fields['vessel']['captain_name'] ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">Μέγιστος αριθμός επιβατών</td>
        <td>{{ $fields['vessel']['capacity_max'] ?? '—' }}</td>
    </tr>
</table>

<h2>Η ναύλωση</h2>

<table>
    <tr>
        <td class="label">Ημερομηνία</td>
        <td>{{ $fields['charter']['local_date'] ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">Ώρα αναχώρησης</td>
        <td>{{ substr((string) ($fields['charter']['local_time'] ?? ''), 0, 5) ?: '—' }}</td>
    </tr>
    <tr>
        <td class="label">Διάρκεια</td>
        <td>
            @if (! empty($fields['charter']['duration_minutes']))
                {{ intdiv((int) $fields['charter']['duration_minutes'], 60) }} ώρες
                @if ((int) $fields['charter']['duration_minutes'] % 60 !== 0)
                    {{ (int) $fields['charter']['duration_minutes'] % 60 }} λεπτά
                @endif
            @else
                —
            @endif
        </td>
    </tr>
    <tr>
        <td class="label">Λιμάνι αναχώρησης</td>
        <td>{{ $fields['charter']['port'] ?? '—' }}</td>
    </tr>
    <tr>
        <td class="label">Επιβάτες</td>
        <td>{{ $fields['charter']['pax_total'] ?? '—' }}</td>
    </tr>
</table>

<h2>Το ναύλο</h2>

@php
    // Formatted here rather than in the action: the snapshot keeps cents, which
    // is what CMP-7 needs it to be comparable in. A localised string in a
    // snapshot is a string somebody has to parse back.
    $money = static fn (?int $cents): string => number_format(((int) $cents) / 100, 2, ',', '.') . ' €';
@endphp

<table>
    <tr>
        <td class="label">Συνολικό ναύλο</td>
        <td><strong>{{ $money($fields['money']['total_cents'] ?? 0) }}</strong> (συμπεριλαμβάνεται ΦΠΑ)</td>
    </tr>
    <tr>
        <td class="label">Καταβληθέν</td>
        <td>{{ $money($fields['money']['paid_cents'] ?? 0) }}</td>
    </tr>
    <tr>
        <td class="label">Υπόλοιπο</td>
        <td>{{ $money($fields['money']['balance_cents'] ?? 0) }}</td>
    </tr>
</table>

<div class="signature">
    Η αποδοχή του ναυλωτή καταγράφεται ηλεκτρονικά, με ημερομηνία, ώρα και
    διεύθυνση δικτύου, μέσα από τη σελίδα της κράτησής του. Δεν απαιτείται
    χειρόγραφη υπογραφή.
</div>

<p class="footer">
    {{ $fields['operator']['name'] ?? '' }}
    · Δημιουργήθηκε {{ \Illuminate\Support\Carbon::parse($fields['generated_at'] ?? now())->format('d/m/Y H:i') }}
    · Υπόδειγμα {{ $fields['template_version'] ?? '' }}
</p>

</body>
</html>
