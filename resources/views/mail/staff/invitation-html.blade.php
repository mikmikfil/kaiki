{{--
    The invitation's HTML half — direction Γ, «Ο ρόλος σας» (Mike, 25/9;
    docs/mockups/invitation-email.html).

    A welcome built round the role: who you are to this company, what the role
    lets you do and what it does not, then one full-width button. Written for
    the question every new colleague asks on the first morning — «γιατί δεν
    βλέπω τις τιμές;» — before they have to ask it.

    Same construction rules as `mail/booking/html.blade.php` and for the same
    reasons: one centred table, inline styles, no image anywhere. A colleague
    opening this has never received mail from the operator's domain before,
    which is precisely when a client blocks remote images — and this is the one
    message in the product where a broken layout costs somebody their access.
--}}
@php
    $ink = '#14202b';
    $muted = '#5a6b7a';
    $line = '#e4ecf1';
    $font = 'font-family:Arial,Helvetica,sans-serif;';
    $roleKey = $role?->value;
    $can = $roleKey !== null ? (array) __("staff.invitation.roles.{$roleKey}.can") : [];
    $cannot = $roleKey !== null ? (string) __("staff.invitation.roles.{$roleKey}.cannot") : '';
    $isOwner = $role === \App\Enums\Role::Owner;
@endphp
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
                   style="max-width:560px;background:#ffffff;border:1px solid #dbe3e9;border-top:4px solid {{ $accent }};border-radius:10px;">

                <tr>
                    <td style="padding:24px 28px 6px;{{ $font }}font-size:14px;font-weight:bold;color:{{ $muted }};">
                        {{ $operator }}
                    </td>
                </tr>

                <tr>
                    <td style="padding:6px 28px 4px;{{ $font }}font-size:15px;line-height:1.55;color:{{ $ink }};">
                        <p style="margin:0 0 8px;font-size:24px;line-height:1.25;font-weight:bold;">
                            {{ __('staff.invitation.heading') }}
                        </p>
                        <p style="margin:0 0 18px;color:{{ $muted }};">
                            {{ $isOwner ? __('staff.invitation.lead_owner') : __('staff.invitation.lead') }}
                        </p>

                        {{-- Who you are to this company, in four lines. --}}
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;">
                            @foreach ([
                                'name' => $invitee->name,
                                'company' => $operator,
                                'email' => $invitee->email,
                                'added_by' => $addedBy,
                            ] as $key => $value)
                                <tr>
                                    <td valign="top" width="38%" style="padding:10px 12px 10px 0;{{ $loop->first ? '' : "border-top:1px solid {$line};" }}{{ $font }}font-size:14px;color:{{ $muted }};">
                                        {{ __("staff.invitation.facts.{$key}") }}
                                    </td>
                                    <td valign="top" style="padding:10px 0;{{ $loop->first ? '' : "border-top:1px solid {$line};" }}{{ $font }}font-size:15px;font-weight:bold;color:{{ $ink }};word-break:break-word;">
                                        {{ $value }}
                                    </td>
                                </tr>
                            @endforeach
                        </table>

                        @if ($role !== null)
                            {{-- The role, with what it can and cannot do. --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                                   style="background:#f4f7fb;border:1px solid #dce5ef;border-radius:10px;margin:0 0 24px;">
                                <tr>
                                    <td style="padding:18px 20px;{{ $font }}">
                                        <p style="margin:0 0 2px;font-size:13px;color:{{ $muted }};">{{ __('staff.invitation.role_label') }}</p>
                                        <p style="margin:0 0 12px;font-size:20px;font-weight:bold;color:{{ $accent }};">{{ $role->label() }}</p>
                                        <p style="margin:0 0 8px;font-size:14px;color:{{ $muted }};">
                                            {{ $isOwner ? __('staff.invitation.can_owner') : __('staff.invitation.can') }}
                                        </p>
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                            @foreach ($can as $item)
                                                <tr>
                                                    <td valign="top" width="26" style="padding:4px 0;{{ $font }}font-size:15px;font-weight:bold;color:#0e7c5a;">&#10003;</td>
                                                    <td valign="top" style="padding:4px 0;{{ $font }}font-size:15px;line-height:1.5;color:{{ $ink }};">{{ $item }}</td>
                                                </tr>
                                            @endforeach
                                        </table>
                                        @if ($cannot !== '')
                                            <p style="margin:12px 0 0;padding-top:12px;border-top:1px solid #dce5ef;font-size:14px;line-height:1.5;color:{{ $muted }};">
                                                {{ $cannot }}
                                            </p>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        @endif

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td align="center" style="background:{{ $accent }};border-radius:8px;">
                                    <a href="{{ $resetUrl }}"
                                       style="display:block;padding:14px 24px;{{ $font }}font-size:16px;font-weight:bold;color:#ffffff;text-decoration:none;text-align:center;">
                                        {{ __('staff.invitation.action') }}
                                    </a>
                                </td>
                            </tr>
                        </table>
                        <p style="margin:12px 0 22px;font-size:13px;color:{{ $muted }};text-align:center;">
                            {{ trans_choice('staff.invitation.expiry', $days, ['days' => $days]) }}
                        </p>
                    </td>
                </tr>

                <tr>
                    <td style="padding:16px 28px 22px;border-top:1px solid {{ $line }};{{ $font }}font-size:13px;line-height:1.55;color:{{ $muted }};">
                        @if ($askEmail !== null)
                            <p style="margin:0 0 8px;">
                                {{ __('staff.invitation.questions', ['email' => $askEmail]) }}
                            </p>
                        @endif
                        {{-- The address in full, because a link that cannot be
                             clicked must still be usable, and because somebody
                             about to type a password deserves to see where. --}}
                        <p style="margin:0 0 4px;">{{ __('staff.invitation.link_text') }}</p>
                        <p style="margin:0;font-size:12px;word-break:break-all;">{{ $resetUrl }}</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
