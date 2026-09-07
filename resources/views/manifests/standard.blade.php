{{--
    The passenger list, for a PDF (spec OPS-8, OPS-9, CMP-2, CMP-3).

    Rendered by Chromium through Browsershot (ARC-10, which forbids dompdf) — and
    CMP-3 is why that decision earns its keep here: Greek text has to render
    correctly and long names must not clip. dompdf's font handling is where Greek
    becomes boxes.

    **Every style is inline in this file.** A PDF is rendered by a headless
    browser with no session, no Vite dev server and no compiled asset manifest,
    so a stylesheet reference is a stylesheet that silently does not load and a
    manifest that comes out as unstyled text.

    `page-break-inside: avoid` on rows and a repeated `<thead>`: CMP-3 asks for a
    multi-page case, and a table split mid-row across a page break is one the
    harbourmaster hands back.
--}}
@php
    use App\Enums\ManifestColumn;
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('manifest.title') }}</title>
    <style>
        @page { size: A4; margin: 14mm 12mm; }

        /* A stack that exists on the Chromium image and carries Greek. */
        body {
            font-family: "DejaVu Sans", "Noto Sans", Arial, sans-serif;
            font-size: 10pt;
            color: #111;
            margin: 0;
        }

        h1 { font-size: 15pt; margin: 0 0 2mm; }

        .meta { width: 100%; border-collapse: collapse; margin: 0 0 5mm; font-size: 9.5pt; }
        .meta td { padding: 0.6mm 0; vertical-align: top; }
        .meta .k { color: #555; width: 26mm; }

        table.list { width: 100%; border-collapse: collapse; }
        table.list thead th {
            text-align: left; font-size: 8.5pt; text-transform: none;
            border-bottom: 0.6mm solid #111; padding: 1.4mm 2mm 1.4mm 0; white-space: nowrap;
        }
        table.list td {
            padding: 1.4mm 2mm 1.4mm 0; border-bottom: 0.2mm solid #ddd;
            vertical-align: top;
            /* Long names wrap rather than clipping or pushing the table wide. */
            word-break: break-word;
        }
        table.list tr { page-break-inside: avoid; }
        table.list .num { width: 7mm; color: #666; }

        .count { margin: 5mm 0 0; font-size: 10pt; }
        .count strong { font-size: 12pt; }
        .warn { margin: 2mm 0 0; font-size: 9pt; color: #a33; }
        .sign { margin: 14mm 0 0; font-size: 9.5pt; color: #555; }
        .sign .line { display: inline-block; border-bottom: 0.3mm solid #111; width: 60mm; margin-left: 3mm; }
    </style>
</head>
<body>
    <h1>{{ __('manifest.title') }}</h1>

    <table class="meta">
        @foreach (['trip', 'vessel', 'date', 'time', 'port', 'captain'] as $key)
            @if (($manifest->header[$key] ?? null) !== null && $manifest->header[$key] !== '')
                <tr>
                    <td class="k">{{ __('manifest.header.' . $key) }}</td>
                    <td>{{ $manifest->header[$key] }}</td>
                </tr>
            @endif
        @endforeach
    </table>

    <table class="list">
        <thead>
            <tr>
                <th class="num">#</th>
                @foreach ($manifest->columns as $column)
                    <th>{{ $column->label() }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($manifest->rows as $row)
                <tr>
                    <td class="num">{{ $loop->iteration }}</td>
                    @foreach ($manifest->columns as $column)
                        <td>{{ $row[$column->value] ?? '' }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- OPS-9: bodies against the licence, not seats against what was sold. An
         infant on a lap takes no seat and is a person on the boat. --}}
    <p class="count">
        {{ __('manifest.on_board') }}
        <strong>{{ $manifest->onBoard }}</strong>
        @if ($manifest->capacityMax !== null)
            {{ __('manifest.of_capacity', ['capacity' => $manifest->capacityMax]) }}
        @endif
    </p>

    @if ($manifest->missingDetails > 0)
        <p class="warn">{{ __('manifest.missing', ['count' => $manifest->missingDetails]) }}</p>
    @endif

    <p class="sign">{{ __('manifest.signature') }}<span class="line"></span></p>
</body>
</html>
