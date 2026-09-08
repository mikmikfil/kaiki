{{--
    The invitation's HTML half.

    Same construction rules as `mail/booking/html.blade.php` and for the same
    reasons: one centred table, inline styles, no image anywhere. A colleague
    opening this has never received mail from the operator's domain before,
    which is precisely when a client blocks remote images — and this is the one
    message in the product where a broken layout costs somebody their access.
--}}
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('staff.invitation.subject', ['operator' => $operator]) }}</title>
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
                            {{ __('staff.invitation.heading', ['operator' => $operator]) }}
                        </p>

                        <p style="margin:0 0 18px;">
                            {{ __('staff.invitation.body', [
                                'name' => $invitee->name,
                                'inviter' => $invitedBy->name,
                                'operator' => $operator,
                            ]) }}
                        </p>

                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px;">
                            <tr>
                                <td style="background:{{ $accent }};">
                                    <a href="{{ $resetUrl }}"
                                       style="display:inline-block;padding:12px 20px;color:#ffffff;
                                              text-decoration:none;font-weight:bold;font-size:15px;">
                                        {{ __('staff.invitation.action') }}
                                    </a>
                                </td>
                            </tr>
                        </table>

                        {{-- The address in full, because a link that cannot be
                             clicked must still be usable, and because somebody
                             about to type a password deserves to see where. --}}
                        <p style="margin:0 0 18px;font-size:13px;color:#5a6b7a;word-break:break-all;">
                            {{ $resetUrl }}
                        </p>

                        <p style="margin:0;font-size:13px;color:#5a6b7a;">
                            {{ __('staff.invitation.expiry') }}
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
