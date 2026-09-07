{{--
    The print-for-the-harbour layout (spec OPS-8, CMP-2).

    > *"A separate print-for-the-harbour layout exists"* … *"with larger type and
    > the vessel and captain details in the header"*.

    **A separate file rather than a print stylesheet on the other one**, and the
    difference is not cosmetic. This sheet is read standing up, in wind, by
    somebody who is not looking for a name they already know — so the type is
    larger, the header is the vessel and the captain rather than the trip, and
    the count is at the top where a port official looks first rather than at the
    bottom where a reader would arrive last.

    Fewer people fit on a page than on the standard layout. That is the trade,
    and it is the right way round: a sheet nobody can read at arm's length is a
    sheet that gets handed back.
--}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('manifest.title') }}</title>
    <style>
        @page { size: A4; margin: 12mm 10mm; }

        body {
            font-family: "DejaVu Sans", "Noto Sans", Arial, sans-serif;
            font-size: 13pt;
            color: #000;
            margin: 0;
        }

        .head { border-bottom: 1mm solid #000; padding-bottom: 3mm; margin-bottom: 4mm; }
        .head h1 { font-size: 20pt; margin: 0 0 1mm; }
        .head .boat { font-size: 17pt; font-weight: 700; }
        .head .line { font-size: 13pt; margin: 1mm 0 0; }

        /* At the top, because a port official looks there first. */
        .count { font-size: 17pt; font-weight: 700; margin: 0 0 4mm; }

        table { width: 100%; border-collapse: collapse; }
        th {
            text-align: left; font-size: 11pt; padding: 2mm 3mm 2mm 0;
            border-bottom: 0.6mm solid #000; white-space: nowrap;
        }
        td { padding: 2.6mm 3mm 2.6mm 0; border-bottom: 0.2mm solid #999; word-break: break-word; }
        tr { page-break-inside: avoid; }
        .num { width: 9mm; }

        .sign { margin: 16mm 0 0; font-size: 12pt; }
        .sign .line { display: inline-block; border-bottom: 0.4mm solid #000; width: 70mm; margin-left: 4mm; }
    </style>
</head>
<body>
    <div class="head">
        <h1>{{ __('manifest.title') }}</h1>
        <div class="boat">{{ $manifest->header['vessel'] ?? '' }}</div>
        <p class="line">
            {{ $manifest->header['date'] ?? '' }}
            @if (($manifest->header['time'] ?? null) !== null) · {{ $manifest->header['time'] }} @endif
            @if (($manifest->header['port'] ?? null) !== null) · {{ $manifest->header['port'] }} @endif
        </p>
        @if (($manifest->header['captain'] ?? null) !== null)
            <p class="line">{{ __('manifest.header.captain') }}: {{ $manifest->header['captain'] }}</p>
        @endif
    </div>

    <p class="count">
        {{ __('manifest.on_board') }} {{ $manifest->onBoard }}
        @if ($manifest->capacityMax !== null)
            {{ __('manifest.of_capacity', ['capacity' => $manifest->capacityMax]) }}
        @endif
    </p>

    <table>
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

    <p class="sign">{{ __('manifest.signature') }}<span class="line"></span></p>
</body>
</html>
