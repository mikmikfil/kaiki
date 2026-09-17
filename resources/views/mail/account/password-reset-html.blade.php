{{--
    The password reset's HTML half, for both panels.

    Built like the invitation it follows on from (`mail/staff/invitation-html`):
    one centred table, inline styles, no image, the operator's name over their
    colour, and the address in full under the button.
--}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('auth.reset_mail.subject', ['operator' => $operator]) }}</title>
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
                    <td style="padding:24px;font-family:Arial,Helvetica,sans-serif;font-size:15px;
                               line-height:1.55;color:#14202b;">

                        <p style="margin:0 0 14px;font-size:19px;font-weight:bold;">
                            {{ __('auth.reset_mail.heading') }}
                        </p>

                        <p style="margin:0 0 18px;">
                            {{ __('auth.reset_mail.body', ['name' => $user->name, 'operator' => $operator]) }}
                        </p>

                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px;">
                            <tr>
                                <td style="background:{{ $accent }};">
                                    <a href="{{ $resetUrl }}"
                                       style="display:inline-block;padding:12px 20px;color:#ffffff;
                                              text-decoration:none;font-weight:bold;font-size:15px;">
                                        {{ __('auth.reset_mail.action') }}
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0 0 18px;font-size:13px;color:#5a6b7a;word-break:break-all;">
                            {{ $resetUrl }}
                        </p>

                        <p style="margin:0 0 6px;font-size:13px;color:#5a6b7a;">
                            {{ __('auth.reset_mail.expiry', ['minutes' => $minutes]) }}
                        </p>

                        <p style="margin:0;font-size:13px;color:#5a6b7a;">
                            {{ __('auth.reset_mail.ignore') }}
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
