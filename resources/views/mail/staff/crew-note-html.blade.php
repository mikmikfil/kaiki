{{--
    A note to the crew, the HTML half — the staff invitation's construction:
    one centred table, inline styles, no image. Put on a schedule, put on one
    departure, or the 24-hours-before reminder (2026-09-24).
--}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }}</title>
</head>
<body style="margin:0;padding:0;background:#f2f5f7;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f2f5f7;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="max-width:560px;background:#ffffff;border:1px solid #dbe3e9;">
                <tr>
                    <td style="padding:20px 24px;border-bottom:3px solid {{ $accent }};
                               font-family:Arial,Helvetica,sans-serif;font-size:16px;font-weight:bold;color:#14202b;">
                        {{ $operator }}
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.55;color:#14202b;">
                        <p style="margin:0 0 14px;font-size:19px;font-weight:bold;">{{ $heading }}</p>
                        <p style="margin:0 0 18px;">{{ $body }}</p>
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 6px;">
                            <tr>
                                <td style="background:{{ $accent }};">
                                    <a href="{{ $url }}"
                                       style="display:inline-block;padding:12px 20px;color:#ffffff;text-decoration:none;font-weight:bold;font-size:15px;">
                                        {{ $action }}
                                    </a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
