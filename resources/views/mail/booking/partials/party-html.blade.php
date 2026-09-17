{{--
    Who is booked and what it costs — or, on a quote, what the offer is made of.
    Shared by the full messages and the quote, with the parent's `$d` and styles.
--}}
<p style="{{ $section }}">{{ $template === \App\Enums\NotificationTemplate::QuoteSent ? __('mail.common.quote_items') : __('mail.common.party') }}</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="border-top:1px solid #e4ecf1;margin:0 0 24px;">
    @foreach ($d->party as $line)
        <tr>
            <td style="padding:8px 0;border-bottom:1px solid #e4ecf1;{{ $font }}">{{ $line['label'] }}</td>
            <td style="padding:8px 0;border-bottom:1px solid #e4ecf1;text-align:right;{{ $font }}">{{ $line['amount'] }}</td>
        </tr>
    @endforeach
    @if ($d->total !== null)
        <tr>
            <td style="padding:8px 0 4px;{{ $font }}font-weight:bold;">{{ __('mail.common.total') }}</td>
            <td style="padding:8px 0 4px;text-align:right;{{ $font }}font-weight:bold;">{{ $d->total }}</td>
        </tr>
    @endif
    @if ($d->paid !== null)
        <tr>
            <td style="padding:4px 0;{{ $font }}{{ $muted }}">{{ __('mail.common.paid') }}</td>
            <td style="padding:4px 0;text-align:right;{{ $font }}{{ $muted }}">{{ $d->paid }}</td>
        </tr>
    @endif
    @if ($d->balance !== null)
        <tr>
            <td style="padding:4px 0;{{ $font }}font-weight:bold;">
                {{ $d->balanceDue !== null ? __('mail.common.balance_due', ['date' => $d->balanceDue]) : __('mail.common.balance') }}
            </td>
            <td style="padding:4px 0;text-align:right;{{ $font }}font-weight:bold;">{{ $d->balance }}</td>
        </tr>
    @endif
</table>
