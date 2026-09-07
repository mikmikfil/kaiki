{{--
    The HTML half of every transactional email (spec NTF-1, NTF-6, I18N-2).

    ## Readable without images, because NTF-6 requires it

    There is no image in this template at all — not a logo, not a spacer, not a
    tracking pixel. Every mail client on the list blocks remote images by
    default for a sender the recipient has not written to before, which is
    exactly the situation a confirmation email is in. A layout that needs an
    image to make sense is a layout that arrives broken the first time it
    matters.

    The operator's identity is carried by the **name and the accent colour**,
    both of which survive an image block.

    ## Tables, inline styles, and no `<style>` block

    Not nostalgia. Outlook on Windows renders through Word, which supports
    neither flexbox nor grid and discards most of a `<style>` block; Gmail
    strips `<style>` on forwarded mail. A single centred table with inline
    styles is the only construction that renders the same in Gmail, Outlook and
    Apple Mail, which is NTF-6's own list.

    ## No `text-transform: uppercase` anywhere

    I18N-2. Uppercasing Greek drops the accents — ΆΝΝΑ becomes ΑΝΝΑ — and
    browsers and mail clients disagree about whether the final sigma changes.
    A design that leans on uppercase labels is a design that mangles half its
    audience's names.
--}}
@php
    /** @var \App\Models\Booking $booking */
    /** @var \App\Enums\NotificationTemplate $template */
    $accent = $brand['colors']['primary'] ?? '#063733';
    $operator = $brand['tenant']['name'] ?? config('app.name');
    $money = static fn (int $cents): string => number_format($cents / 100, 2, ',', '.') . ' €';
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __("mail.{$template->value}.subject", ['reference' => $booking->reference]) }}</title>
</head>
<body style="margin:0;padding:0;background:#f2f5f7;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f2f5f7;">
    <tr>
        <td align="center" style="padding:24px 12px;">

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                   style="max-width:560px;background:#ffffff;border:1px solid #dbe3e9;">

                {{-- The operator's name, in their own colour. No logo image:
                     see the file docblock. --}}
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
                            {{ __("mail.{$template->value}.heading") }}
                        </p>

                        <p style="margin:0 0 18px;">
                            {{ __("mail.{$template->value}.body", [
                                'name' => $booking->guest_name,
                                'reference' => $booking->reference,
                                'date' => $booking->local_date->format('d/m/Y'),
                                'time' => substr((string) $booking->local_time, 0, 5),
                            ]) }}
                        </p>

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
                               style="border-top:1px solid #e4ecf1;border-bottom:1px solid #e4ecf1;margin:0 0 18px;">
                            <tr>
                                <td style="padding:10px 0;color:#5a6b7a;">{{ __('mail.common.reference') }}</td>
                                <td style="padding:10px 0;text-align:right;font-weight:bold;">{{ $booking->reference }}</td>
                            </tr>
                            <tr>
                                <td style="padding:10px 0;color:#5a6b7a;border-top:1px solid #e4ecf1;">{{ __('mail.common.when') }}</td>
                                <td style="padding:10px 0;text-align:right;border-top:1px solid #e4ecf1;">
                                    {{ $booking->local_date->format('d/m/Y') }} · {{ substr((string) $booking->local_time, 0, 5) }}
                                </td>
                            </tr>
                            @if ($booking->product?->meetingPoint)
                                <tr>
                                    <td style="padding:10px 0;color:#5a6b7a;border-top:1px solid #e4ecf1;">{{ __('mail.common.meeting_point') }}</td>
                                    <td style="padding:10px 0;text-align:right;border-top:1px solid #e4ecf1;">
                                        {{ $booking->product->meetingPoint->name }}
                                    </td>
                                </tr>
                            @endif
                            @if ($booking->balance_cents > 0)
                                <tr>
                                    <td style="padding:10px 0;color:#5a6b7a;border-top:1px solid #e4ecf1;">{{ __('mail.common.balance') }}</td>
                                    <td style="padding:10px 0;text-align:right;border-top:1px solid #e4ecf1;font-weight:bold;">
                                        {{ $money($booking->balance_cents) }}
                                    </td>
                                </tr>
                            @endif
                        </table>

                        {{-- One action, and it is always the guest's own page.
                             ADR-0004: a gateway URL emailed today is dead by the
                             time a guest opens it in three weeks. --}}
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 18px;">
                            <tr>
                                <td style="background:{{ $accent }};">
                                    <a href="{{ route('guest.booking', ['token' => $booking->manage_token]) }}"
                                       style="display:inline-block;padding:12px 20px;color:#ffffff;
                                              text-decoration:none;font-weight:bold;font-size:15px;">
                                        {{ __('mail.common.open_booking') }}
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin:0;color:#5a6b7a;font-size:13px;">
                            {{ __('mail.common.transactional') }}
                        </p>
                    </td>
                </tr>
            </table>

        </td>
    </tr>
</table>
</body>
</html>
